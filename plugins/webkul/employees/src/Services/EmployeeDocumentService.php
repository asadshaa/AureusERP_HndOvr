<?php

namespace Webkul\Employee\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Policies\EmployeeDocumentPolicy;
use Webkul\Security\Models\User;

/**
 * Every write to employee documents goes through here, so the same checks
 * apply whether the upload comes from the employee page, the HR Documents
 * list or a future API: who may upload (EmployeeDocumentPolicy), same
 * company for employee and type, PDF/JPG/PNG up to 10 MB on the private
 * disk, an expiry date only for types that have one.
 */
class EmployeeDocumentService
{
    public function __construct(
        protected EmployeeDocumentPolicy $policy,
    ) {}

    /**
     * @param  array{document_type_id: int|string, title?: string|null, number?: string|null, issued_on?: string|null, expires_on?: string|null, notes?: string|null, original_name?: string|null}  $data
     * @param  UploadedFile|string  $file  an upload, or a path already stored on the private disk (Filament FileUpload)
     */
    public function upload(Employee $employee, User $uploader, array $data, UploadedFile|string $file): EmployeeDocument
    {
        $type = DocumentType::query()->whereKey($data['document_type_id'] ?? null)->first();

        if (! $type || (int) $type->company_id !== (int) $employee->company_id || ! $type->is_active) {
            throw ValidationException::withMessages(['document_type_id' => 'Choose a document type of this employee\'s company.']);
        }

        if (! $this->policy->upload($uploader, $employee, $type)) {
            throw new AuthorizationException('You may not upload documents for this employee.');
        }

        $isHr = $this->policy->hrCanManage($uploader, $employee);
        $stored = $this->store($employee, $file, $data['original_name'] ?? null);

        return EmployeeDocument::query()->create([
            'company_id'       => $employee->company_id,
            'employee_id'      => $employee->id,
            'document_type_id' => $type->id,
            'title'            => $data['title'] ?? null,
            'number'           => $data['number'] ?? null,
            'issued_on'        => $data['issued_on'] ?? null,
            'expires_on'       => $type->has_expiry ? ($data['expires_on'] ?? null) : null,
            'notes'            => $data['notes'] ?? null,
            'status'           => $isHr ? EmployeeDocument::STATUS_VERIFIED : EmployeeDocument::STATUS_PENDING,
            'uploaded_by'      => $uploader->id,
            'verified_by'      => $isHr ? $uploader->id : null,
            'verified_at'      => $isHr ? now() : null,
        ] + $stored);
    }

    /** Edit the details (not the file) of a document. */
    public function update(EmployeeDocument $document, User $user, array $data): EmployeeDocument
    {
        $this->assertCanManage($document, $user);

        $type = $document->documentType;
        $document->update(array_intersect_key($data, array_flip(['title', 'number', 'issued_on', 'expires_on', 'notes'])));

        if (! $type?->has_expiry && $document->expires_on !== null) {
            $document->update(['expires_on' => null]);
        }

        return $document->fresh();
    }

    /**
     * Replace the file: the old row is soft-deleted (its file is kept for the
     * audit trail) and points to the new one.
     */
    public function replace(EmployeeDocument $document, User $user, UploadedFile|string $file, array $data = []): EmployeeDocument
    {
        $this->assertCanManage($document, $user);

        return DB::transaction(function () use ($document, $user, $file, $data): EmployeeDocument {
            $stored = $this->store($document->employee, $file, $data['original_name'] ?? null);

            $replacement = EmployeeDocument::query()->create([
                'company_id'       => $document->company_id,
                'employee_id'      => $document->employee_id,
                'document_type_id' => $document->document_type_id,
                'title'            => array_key_exists('title', $data) ? $data['title'] : $document->title,
                'number'           => array_key_exists('number', $data) ? $data['number'] : $document->number,
                'issued_on'        => array_key_exists('issued_on', $data) ? $data['issued_on'] : $document->issued_on,
                'expires_on'       => $document->documentType?->has_expiry
                    ? (array_key_exists('expires_on', $data) ? $data['expires_on'] : $document->expires_on)
                    : null,
                'notes'       => array_key_exists('notes', $data) ? $data['notes'] : $document->notes,
                'status'      => EmployeeDocument::STATUS_VERIFIED,
                'uploaded_by' => $user->id,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ] + $stored);

            $document->update(['replaced_by_id' => $replacement->id]);
            $document->delete();

            return $replacement;
        });
    }

    /** HR has checked a document the employee uploaded. */
    public function verify(EmployeeDocument $document, User $user): EmployeeDocument
    {
        $this->assertCanManage($document, $user);

        $document->update(['status' => EmployeeDocument::STATUS_VERIFIED, 'verified_by' => $user->id, 'verified_at' => now()]);

        return $document;
    }

    /** Soft delete; the file stays on the private disk. */
    public function delete(EmployeeDocument $document, User $user): void
    {
        $this->assertCanManage($document, $user);

        $document->delete();
    }

    private function assertCanManage(EmployeeDocument $document, User $user): void
    {
        if (! $this->policy->update($user, $document)) {
            throw new AuthorizationException('You may not change this employee\'s documents.');
        }
    }

    /**
     * Put the file on the private disk under employees/documents/{employee}
     * and check its real type and size there.
     *
     * @return array{file_path: string, original_name: string|null, mime_type: string, size: int}
     */
    private function store(Employee $employee, UploadedFile|string $file, ?string $originalName = null): array
    {
        $disk = Storage::disk(EmployeeDocument::DISK);
        $directory = EmployeeDocument::DIRECTORY.'/'.$employee->id;

        if ($file instanceof UploadedFile) {
            $originalName ??= $file->getClientOriginalName();
            $path = $file->store($directory, EmployeeDocument::DISK);
        } else {
            // Only a fresh upload from the staging folder is accepted, never an
            // arbitrary path on the private disk (someone else's file).
            $path = ltrim(str_replace('\\', '/', $file), '/');

            if (str_contains($path, '..') || ! str_starts_with($path, EmployeeDocument::INCOMING_DIRECTORY.'/') || ! $disk->exists($path)) {
                throw ValidationException::withMessages(['file_path' => 'The uploaded file was not found.']);
            }

            $target = $directory.'/'.basename($path);
            $disk->move($path, $target);
            $path = $target;
        }

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file_path' => 'The file could not be stored.']);
        }

        $mime = (string) $disk->mimeType($path);
        $size = (int) $disk->size($path);

        if (! in_array($mime, EmployeeDocument::ACCEPTED_MIME_TYPES, true) || $size > EmployeeDocument::MAX_SIZE_KB * 1024) {
            $disk->delete($path);

            throw ValidationException::withMessages(['file_path' => 'Upload a PDF, JPG or PNG file of at most 10 MB.']);
        }

        return [
            'file_path'     => $path,
            'original_name' => $originalName ?: basename($path),
            'mime_type'     => $mime,
            'size'          => $size,
        ];
    }
}
