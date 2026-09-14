<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Collection;
use RuntimeException;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Models\DocumentTransfer;
use Webkul\Accounting\Models\DocumentVersion;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Models\UserDevice;

/**
 * Sends an existing document to another user's registered device.
 *
 * Deliberately a thin layer over DocumentService: it never opens the
 * storage disk, never reads bytes, and never bypasses a permission
 * check. Its only job is to decide -- server-side -- whether this sender
 * may put this document on that device, and to leave an audit trail of
 * the decision.
 *
 * Company scope always comes from the authenticated user's
 * default_company_id, never from caller input.
 */
class DocumentTransferService
{
    public function __construct(private readonly DocumentService $documents) {}

    public function send(
        User $sender,
        int $documentId,
        UserDevice $device,
        ?string $note = null,
        ?string $ipAddress = null,
    ): DocumentTransfer {
        if (! $sender->can(AccountingPermissions::TransferDocuments)) {
            throw new RuntimeException('You do not have permission to send documents to a device.');
        }

        // Resolves within the sender's own company and throws otherwise,
        // so cross-company sends fail before anything else happens.
        $document = $this->documents->find($sender, $documentId);

        if ($device->company_id !== $sender->default_company_id) {
            throw new RuntimeException('That device belongs to a different company.');
        }

        if (! $device->isActive()) {
            throw new RuntimeException('That device has been revoked.');
        }

        $pending = DocumentTransfer::query()
            ->pending()
            ->where('sender_id', $sender->id)
            ->count();

        if ($pending >= (int) config('accounting_transfer.max_pending_per_sender')) {
            throw new RuntimeException('You have too many pending transfers.');
        }

        $transfer = DocumentTransfer::query()->create([
            'company_id'          => $sender->default_company_id,
            'document_id'         => $document->id,
            'sender_id'           => $sender->id,
            'recipient_id'        => $device->user_id,
            'recipient_device_id' => $device->id,
            'status'              => DocumentTransferStatus::Pending,
            'expected_sha256'     => $document->currentVersion?->checksum_sha256,
            'note'                => $note,
            'expires_at'          => now()->addHours((int) config('accounting_transfer.expiry_hours')),
        ]);

        $document->audits()->create([
            'company_id' => $document->company_id,
            'actor_id'   => $sender->id,
            'action'     => DocumentAuditAction::TransferSent,
            'ip_address' => $ipAddress,
            'metadata'   => [
                'transfer_id'  => $transfer->id,
                'recipient_id' => $device->user_id,
                'device_id'    => $device->id,
                'device_label' => $device->label,
            ],
        ]);

        return $transfer;
    }

    /**
     * @return Collection<int, DocumentTransfer>
     */
    public function pendingFor(User $user): Collection
    {
        return DocumentTransfer::query()
            ->pending()
            ->forRecipient($user->id)
            ->where('expires_at', '>', now())
            ->with(['document.currentVersion', 'sender'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{contents: string, version: DocumentVersion, transfer: DocumentTransfer}
     */
    public function claim(User $recipient, DocumentTransfer $transfer, ?string $ipAddress = null): array
    {
        if ($transfer->recipient_id !== $recipient->id) {
            throw new RuntimeException('This transfer was not sent to you.');
        }

        if (! $transfer->isPending()) {
            throw new RuntimeException('This transfer is no longer pending.');
        }

        if ($transfer->hasExpired()) {
            throw new RuntimeException('This transfer has expired.');
        }

        // retrieveContents() re-verifies the stored checksum and audits
        // the read, so integrity is checked by the same code path a
        // normal download uses -- not a parallel one.
        $result = $this->documents->retrieveContents($recipient, $transfer->document_id, $ipAddress);

        if ($transfer->expected_sha256 !== null
            && hash('sha256', $result['contents']) !== $transfer->expected_sha256) {
            throw new RuntimeException('The document changed after this transfer was created.');
        }

        $transfer->update([
            'status'       => DocumentTransferStatus::Delivered,
            'delivered_at' => now(),
        ]);

        $transfer->document->audits()->create([
            'company_id' => $transfer->company_id,
            'actor_id'   => $recipient->id,
            'action'     => DocumentAuditAction::TransferDelivered,
            'ip_address' => $ipAddress,
            'metadata'   => [
                'transfer_id' => $transfer->id,
                'sender_id'   => $transfer->sender_id,
                'device_id'   => $transfer->recipient_device_id,
                // Recorded explicitly so a later direct peer transport is
                // distinguishable from server delivery in the audit trail.
                'path'        => 'server',
            ],
        ]);

        return [
            'contents' => $result['contents'],
            'version'  => $result['version'],
            'transfer' => $transfer->refresh(),
        ];
    }

    public function cancel(User $actor, DocumentTransfer $transfer, ?string $ipAddress = null): DocumentTransfer
    {
        if (! in_array($actor->id, [$transfer->sender_id, $transfer->recipient_id], true)) {
            throw new RuntimeException('You cannot cancel this transfer.');
        }

        if (! $transfer->isPending()) {
            throw new RuntimeException('This transfer is no longer pending.');
        }

        $transfer->update(['status' => DocumentTransferStatus::Cancelled]);

        $transfer->document->audits()->create([
            'company_id' => $transfer->company_id,
            'actor_id'   => $actor->id,
            'action'     => DocumentAuditAction::TransferCancelled,
            'ip_address' => $ipAddress,
            'metadata'   => ['transfer_id' => $transfer->id],
        ]);

        return $transfer->refresh();
    }
}
