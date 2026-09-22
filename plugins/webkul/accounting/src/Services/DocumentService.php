<?php

namespace Webkul\Accounting\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Contracts\DocumentStorageProvider;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Events\DocumentContentChanged;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Accounting\Models\DocumentVersion;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\User;

/**
 * The single entry point for everything document-related. Every method
 * that reads or writes a Document takes the acting $user explicitly and
 * enforces company isolation and permissions itself -- callers (a future
 * Filament resource, a console command, a test) are never trusted to have
 * scoped a query correctly on their own.
 *
 * Company isolation follows the exact pattern already used by
 * InvoiceResource/BillResource/etc. elsewhere in this codebase: scope by
 * the acting user's default_company_id, not an ad-hoc "current company"
 * concept invented for this feature.
 */
class DocumentService
{
    /** 20 MB -- generous for a scanned receipt or a multi-page statement PDF, small enough to keep uploads fast. */
    public const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024;

    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(
        private readonly DocumentStorageProvider $storage,
    ) {}

    /**
     * Upload a brand new document (its first version).
     */
    public function upload(
        User $user,
        int $companyId,
        DocumentType $documentType,
        string $title,
        ?string $description,
        UploadedFile $file,
        ?string $ipAddress = null,
    ): Document {
        $this->assertCompanyAccess($user, $companyId);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $companyId, null);
        $this->validateFile($file);

        $document = DB::transaction(function () use ($user, $companyId, $documentType, $title, $description, $file, $ipAddress) {
            $document = Document::create([
                'company_id'    => $companyId,
                'creator_id'    => $user->id,
                'document_type' => $documentType,
                'title'         => $title,
                'description'   => $description,
                'status'        => DocumentStatus::Active,
            ]);

            $version = $this->storeVersion($document, $file, $user, 1, null);

            $document->update(['current_version_id' => $version->id]);

            $this->recordAudit($document, $user, DocumentAuditAction::Uploaded, $ipAddress, [
                'version_id' => $version->id,
                'filename'   => $version->original_filename,
            ]);

            return $document->refresh();
        });

        // Fired outside the transaction, only once the document is
        // durably committed -- see DocumentContentChanged for why
        // DocumentService fires this without knowing what (if anything)
        // reacts to it.
        DocumentContentChanged::dispatch($document);

        return $document;
    }

    /**
     * Store a file that arrived from a paired peer instance.
     *
     * A peer is not a User, so there is no actor to authorize and
     * `uploaded_by` is deliberately null -- attributing it to whoever
     * happened to be logged in, or to the reviewer who later accepts it,
     * would put a name against an upload that no person performed.
     *
     * Everything else is the ordinary upload path: the same MIME and size
     * validation, the same checksum, the same storage provider. Nothing a
     * peer sends bypasses a check a human upload has to pass.
     *
     * $source is the informal provenance marker recorded on the initial
     * Uploaded audit's metadata -- 'peer_transmission' by default (this
     * method's original and still most common caller), or 'drive_import'
     * for a file registered by DriveIngestionService (Phase 1 of Drive ->
     * Aureus ingestion). Any other non-human, non-peer origin added later
     * should pass its own value here rather than overloading one of these
     * two.
     */
    public function uploadFromPeer(
        int $companyId,
        DocumentType $documentType,
        string $title,
        ?string $description,
        UploadedFile $file,
        ?string $ipAddress = null,
        string $source = 'peer_transmission',
    ): Document {
        $this->validateFile($file);

        $document = DB::transaction(function () use ($companyId, $documentType, $title, $description, $file, $ipAddress, $source) {
            $document = Document::create([
                'company_id'    => $companyId,
                'creator_id'    => null,
                'document_type' => $documentType,
                'title'         => $title,
                'description'   => $description,
                'status'        => DocumentStatus::Active,
            ]);

            $version = $this->storeVersion($document, $file, null, 1, null);

            $document->update(['current_version_id' => $version->id]);

            $this->recordSystemOrUserAudit($document, null, DocumentAuditAction::Uploaded, $ipAddress, [
                'version_id' => $version->id,
                'filename'   => $version->original_filename,
                'source'     => $source,
            ]);

            return $document->refresh();
        });

        DocumentContentChanged::dispatch($document);

        return $document;
    }

    /**
     * Add a new version to an existing document. The prior version is
     * never touched or deleted -- accounting evidence keeps its full
     * history.
     *
     * $changeReason is mandatory here (unlike the very first upload, where
     * there's nothing yet to explain a change against) -- replacing the
     * file behind a document that may already be attached as evidence
     * needs a reason on the audit trail, not just a timestamp.
     */
    public function addVersion(
        User $user,
        Document $document,
        UploadedFile $file,
        string $changeReason,
        ?string $ipAddress = null,
    ): DocumentVersion {
        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $document->company_id, $document);
        $this->validateFile($file);

        if (trim($changeReason) === '') {
            throw new RuntimeException(
                'A reason is required when replacing a document with a new version -- explain what changed and why. '.
                'This stays on the permanent audit trail.'
            );
        }

        $version = DB::transaction(function () use ($user, $document, $file, $changeReason, $ipAddress) {
            $nextVersionNumber = (int) $document->versions()->max('version_number') + 1;

            $version = $this->storeVersion($document, $file, $user, $nextVersionNumber, $changeReason);

            $document->update(['current_version_id' => $version->id]);

            $this->recordAudit($document, $user, DocumentAuditAction::VersionAdded, $ipAddress, [
                'version_id'     => $version->id,
                'version_number' => $version->version_number,
                'filename'       => $version->original_filename,
                'change_reason'  => $changeReason,
            ]);

            return $version;
        });

        DocumentContentChanged::dispatch($document->refresh());

        return $version;
    }

    /**
     * Attach an already-uploaded document to an accounting record
     * (invoice, bill, journal entry, bank statement line, ...). Refuses
     * if the record belongs to a different company than the document --
     * a document must never be usable as evidence for another company's
     * transaction.
     */
    public function attach(User $user, Document $document, Model $record, ?string $note = null, ?string $ipAddress = null): DocumentAttachment
    {
        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $document->company_id, $document);

        $recordCompanyId = $record->getAttribute('company_id');

        if ($recordCompanyId !== null && (int) $recordCompanyId !== (int) $document->company_id) {
            $this->recordAudit($document, $user, DocumentAuditAction::AccessDenied, $ipAddress, [
                'reason'              => 'cross_company_attachment',
                'attachable_type'     => $record::class,
                'attachable_id'       => $record->getKey(),
                'attachable_company'  => $recordCompanyId,
            ]);

            throw new RuntimeException(
                "This document belongs to a different company than the record you're attaching it to, so it can't be used as evidence for it. ".
                'Upload a separate document under the correct company instead.'
            );
        }

        $attachment = DocumentAttachment::create([
            'company_id'      => $document->company_id,
            'document_id'     => $document->id,
            'attachable_type' => $record::class,
            'attachable_id'   => $record->getKey(),
            'creator_id'      => $user->id,
            'note'            => $note,
        ]);

        $this->recordAudit($document, $user, DocumentAuditAction::Attached, $ipAddress, [
            'attachable_type' => $record::class,
            'attachable_id'   => $record->getKey(),
        ]);

        return $attachment;
    }

    /**
     * Detaching removes only the link between a document and a record --
     * the document itself and its full history are untouched either way.
     * Even so, once the record it's attached to is posted (an Invoice/Bill,
     * via the shared Move model) or a completed Bank Statement, that link
     * is locked: the evidence a posted, legally-final transaction was
     * recorded with must stay visible against it permanently. Attaching
     * MORE evidence to a posted record is still allowed -- only removing
     * an existing link is locked.
     */
    public function detach(User $user, DocumentAttachment $attachment, ?string $ipAddress = null): void
    {
        $document = $attachment->document;

        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $document->company_id, $document);

        $record = $attachment->attachable;

        if ($record && $this->isAttachableLocked($record)) {
            $this->recordAudit($document, $user, DocumentAuditAction::AccessDenied, $ipAddress, [
                'reason'          => 'posted_record_locked',
                'attachable_type' => $attachment->attachable_type,
                'attachable_id'   => $attachment->attachable_id,
            ]);

            throw new RuntimeException(
                'This document is attached to a posted record and can no longer be detached. '.
                'Posted invoices/bills and completed bank statements keep their supporting evidence permanently for audit purposes.'
            );
        }

        $this->recordAudit($document, $user, DocumentAuditAction::Detached, $ipAddress, [
            'attachable_type' => $attachment->attachable_type,
            'attachable_id'   => $attachment->attachable_id,
        ]);

        $attachment->delete();
    }

    /**
     * Look up a single document scoped to the acting user's own company.
     * A document that exists but belongs to another company is treated
     * IDENTICALLY to one that doesn't exist at all -- same exception,
     * same message -- so a caller can never distinguish "wrong company"
     * from "no such id" by probing ids. The attempt is still audited
     * against the document's real company if it does exist elsewhere,
     * so that company's own audit trail shows the attempt.
     */
    public function find(User $user, int $documentId): Document
    {
        $companyId = $user->default_company_id;

        $document = Document::query()->forCompany($companyId)->find($documentId);

        if ($document) {
            return $document;
        }

        $elsewhere = Document::query()->find($documentId);

        if ($elsewhere) {
            $this->recordAudit($elsewhere, $user, DocumentAuditAction::AccessDenied, null, [
                'reason' => 'cross_company_lookup',
            ]);
        }

        throw new ModelNotFoundException('Document not found.');
    }

    /**
     * List documents for the acting user's own company only.
     *
     * @return Collection<int, Document>
     */
    public function listForUser(User $user, array $filters = [])
    {
        $companyId = $user->default_company_id;

        $this->assertPermission($user, AccountingPermissions::ViewDocuments, $companyId, null);

        $query = Document::query()->forCompany($companyId);

        if (! empty($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->get();
    }

    /**
     * Read the current version's bytes back out. This is the one place
     * that actually touches file content after upload, so it's also
     * where a checksum mismatch (file corrupted or tampered with at rest)
     * gets caught before handing anything back to the caller.
     */
    public function retrieveContents(User $user, int $documentId, ?string $ipAddress = null): array
    {
        $document = $this->find($user, $documentId);

        $this->assertPermission($user, AccountingPermissions::DownloadDocuments, $document->company_id, $document);

        $version = $document->currentVersion;

        if (! $version) {
            throw new RuntimeException("Document \"{$document->title}\" has no uploaded file yet.");
        }

        $contents = $this->readVerifiedBytes($document, $version, $user, $ipAddress);

        $this->recordAudit($document, $user, DocumentAuditAction::Downloaded, $ipAddress, [
            'version_id' => $version->id,
        ]);

        return [
            'contents' => $contents,
            'version'  => $version,
            'document' => $document,
        ];
    }

    /**
     * @internal Not part of DocumentService's normal API. This is the ONE
     * method on this class with no permission check and no company scoping
     * -- deliberately, because it exists for a SYSTEM process (a queued
     * Drive-export job) where there is no acting User to check against, not
     * because the check was forgotten. It hands back a document's raw file
     * bytes to whatever calls it, full stop.
     *
     * DriveSyncService is the only intended caller, and it is only ever
     * invoked from SyncDocumentToDriveJob with a company-scoped Document it
     * already loaded directly by id -- no user input flows into which
     * document gets read. Do NOT reach for this as a shortcut anywhere a
     * real user or an HTTP request is involved, even internally within this
     * plugin -- use retrieveContents() there, which enforces
     * DownloadDocuments + company isolation the way every other read path
     * in this class does. If a genuine new need for unauthenticated,
     * system-level access to document bytes shows up beyond Drive sync,
     * that's a reason to revisit this method's design, not to add another
     * caller to it as-is.
     *
     * No "Downloaded" audit entry is recorded either -- the caller is
     * responsible for its own audit entry describing what it actually did
     * with the bytes (e.g. DriveExported). Still runs through the exact
     * same checksum/missing-object verification as retrieveContents() --
     * system callers get no less scrutiny than a human downloading through
     * the UI, just no permission gate in front of it.
     */
    public function readCurrentVersionForSync(Document $document): array
    {
        $version = $document->currentVersion;

        if (! $version) {
            throw new RuntimeException("Document \"{$document->title}\" has no uploaded file yet.");
        }

        return [
            'contents' => $this->readVerifiedBytes($document, $version, null, null),
            'version'  => $version,
        ];
    }

    /**
     * Shared by retrieveContents() (user-facing) and
     * readCurrentVersionForSync() (system-facing) -- the actual
     * existence + checksum verification, identical either way. $user is
     * null for a system caller; the AccessDenied audit on a failure
     * still fires, just attributed to no one rather than misattributed
     * to a human who didn't do it.
     */
    private function readVerifiedBytes(Document $document, DocumentVersion $version, ?User $user, ?string $ipAddress): string
    {
        if (! $this->storage->exists($version->storage_path)) {
            $this->recordSystemOrUserAudit($document, $user, DocumentAuditAction::AccessDenied, $ipAddress, [
                'reason'     => 'missing_object',
                'version_id' => $version->id,
            ]);

            throw new RuntimeException(
                "The file for \"{$document->title}\" is missing from storage even though the record exists. ".
                'This needs investigating before the document can be treated as available -- do not assume it is safe to re-upload silently.'
            );
        }

        $contents = $this->storage->get($version->storage_path);

        if (hash('sha256', $contents) !== $version->checksum_sha256) {
            $this->recordSystemOrUserAudit($document, $user, DocumentAuditAction::AccessDenied, $ipAddress, [
                'reason'     => 'checksum_mismatch',
                'version_id' => $version->id,
            ]);

            throw new RuntimeException(
                "The stored file for \"{$document->title}\" does not match its recorded checksum. ".
                'The file may have been altered outside the application. Treat it as untrustworthy until reviewed.'
            );
        }

        return $contents;
    }

    private function recordSystemOrUserAudit(Document $document, ?User $user, DocumentAuditAction $action, ?string $ipAddress, array $metadata): void
    {
        if ($user) {
            $this->recordAudit($document, $user, $action, $ipAddress, $metadata);

            return;
        }

        $document->audits()->create([
            'company_id' => $document->company_id,
            'actor_id'   => null,
            'action'     => $action,
            // Same normalization recordAudit() applies for a user-attributed
            // row: an empty array stores as NULL, not '[]'. Both call sites
            // today always pass a non-empty $metadata, but keeping the two
            // branches identical means that stays true if a future caller
            // doesn't.
            'metadata'   => $metadata ?: null,
            'ip_address' => $ipAddress,
        ]);
    }

    public function archive(User $user, Document $document, ?string $ipAddress = null): Document
    {
        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::DeleteDocuments, $document->company_id, $document);

        $document->update(['status' => DocumentStatus::Archived]);

        $this->recordAudit($document, $user, DocumentAuditAction::Archived, $ipAddress);

        return $document->refresh();
    }

    public function restore(User $user, Document $document, ?string $ipAddress = null): Document
    {
        $this->assertCompanyAccess($user, $document->company_id);
        $this->assertPermission($user, AccountingPermissions::ManageDocuments, $document->company_id, $document);

        $document->update(['status' => DocumentStatus::Active]);

        $this->recordAudit($document, $user, DocumentAuditAction::Restored, $ipAddress);

        return $document->refresh();
    }

    private function storeVersion(Document $document, UploadedFile $file, ?User $user, int $versionNumber, ?string $changeReason): DocumentVersion
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('Could not read the uploaded file -- it may have failed to upload completely. Please try again.');
        }

        $checksum = hash('sha256', $contents);
        $path = $this->buildStoragePath($document, $file, $versionNumber);

        if (! $this->storage->put($path, $contents)) {
            throw new RuntimeException('Could not save the document to storage. Nothing was recorded -- please try again.');
        }

        $storedSize = $this->storage->size($path);

        if ($storedSize !== strlen($contents)) {
            // Don't leave a corrupt, half-written object sitting in storage
            // with no record pointing at it and no record NOT pointing at
            // it either -- clean it up before surfacing the failure.
            $this->storage->delete($path);

            throw new RuntimeException(
                "The document was written to storage but came back a different size than what was uploaded ({$storedSize} vs ".strlen($contents).' bytes). '.
                'Nothing was recorded. Please try uploading again.'
            );
        }

        return DocumentVersion::create([
            'document_id'       => $document->id,
            'version_number'    => $versionNumber,
            'storage_disk'      => 'accounting_documents',
            'storage_path'      => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type'         => $file->getMimeType() ?: $file->getClientMimeType(),
            'file_size'         => $storedSize,
            'checksum_sha256'   => $checksum,
            'uploaded_by'       => $user?->id,
            'change_reason'     => $changeReason,
        ]);
    }

    /**
     * Whether $record's own state marks it as posted/final. Only two
     * attachable record types exist for accounting documents so far --
     * Move (Invoice/Bill/Journal Entry all share this table and its
     * MoveState) and BankStatement -- so this stays a short, explicit
     * match rather than an interface every attachable type would need to
     * implement for one boolean.
     */
    private function isAttachableLocked(Model $record): bool
    {
        if ($record instanceof Move) {
            return $record->state === MoveState::POSTED;
        }

        if ($record instanceof BankStatement) {
            return (bool) $record->is_completed;
        }

        return false;
    }

    private function buildStoragePath(Document $document, UploadedFile $file, int $versionNumber): string
    {
        $extension = $file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin');

        return sprintf(
            'companies/%d/%s/documents/%d/v%d-%s.%s',
            $document->company_id,
            now()->format('Y'),
            $document->id,
            $versionNumber,
            (string) Str::uuid(),
            $extension,
        );
    }

    private function validateFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException('The uploaded file did not transfer correctly. Please try again.');
        }

        if ($file->getSize() === false || $file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            $maxMb = self::MAX_FILE_SIZE_BYTES / (1024 * 1024);

            throw new RuntimeException("This file is larger than the {$maxMb}MB limit for accounting documents. Reduce its size and try again.");
        }

        // getMimeType() sniffs the file's actual bytes (via PHP's fileinfo
        // extension); getClientMimeType() is just whatever Content-Type the
        // browser/OS decided to send, which is frequently wrong or generic
        // (a real PDF reported as "application/octet-stream" is common on
        // Windows) and, in a real deployment, trivially spoofable by the
        // client. Only fall back to the client-reported value if PHP
        // genuinely couldn't sniff anything.
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType();

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException(
                "\"{$mimeType}\" isn't an accepted file type for accounting documents. ".
                'Upload a PDF, image (JPEG/PNG/WEBP), Word document, or Excel/CSV spreadsheet instead.'
            );
        }
    }

    /**
     * A company mismatch here means the ACTING USER's own default company
     * doesn't match the company the document/action belongs to -- distinct
     * from find()'s "does this document exist under my company" check,
     * this guards write paths where a Document object was already handed
     * to the service (so the caller must not have been able to pass one
     * belonging to another company in the first place, but this is the
     * backstop if they somehow did).
     */
    private function assertCompanyAccess(User $user, int $companyId): void
    {
        if ((int) $user->default_company_id !== (int) $companyId) {
            throw new RuntimeException("You don't have access to documents for this company.");
        }
    }

    private function assertPermission(User $user, string $permission, int $companyId, ?Document $document): void
    {
        if ($user->can($permission)) {
            return;
        }

        if ($document) {
            $this->recordAudit($document, $user, DocumentAuditAction::AccessDenied, null, [
                'reason'     => 'missing_permission',
                'permission' => $permission,
            ]);
        }

        throw new RuntimeException("You don't have permission to do that with accounting documents.");
    }

    private function recordAudit(Document $document, User $user, DocumentAuditAction $action, ?string $ipAddress = null, array $metadata = []): void
    {
        $document->audits()->create([
            'company_id'  => $document->company_id,
            'actor_id'    => $user->id,
            'action'      => $action,
            'metadata'    => $metadata ?: null,
            'ip_address'  => $ipAddress,
        ]);
    }
}
