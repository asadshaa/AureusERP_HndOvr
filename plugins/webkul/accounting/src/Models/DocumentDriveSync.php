<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Accounting\Database\Factories\DocumentDriveSyncFactory;
use Webkul\Accounting\Enums\DriveSyncStatus;

class DocumentDriveSync extends Model
{
    use HasFactory;

    protected static function newFactory(): DocumentDriveSyncFactory
    {
        return DocumentDriveSyncFactory::new();
    }

    protected $table = 'accounting_document_drive_syncs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'          => DriveSyncStatus::class,
            'exists_in_drive' => 'boolean',
            'last_synced_at'  => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function lastSyncedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'last_synced_version_id');
    }

    /**
     * True once we're confident this sync row reflects the document's
     * CURRENT version -- compares the recorded checksum, not just
     * whether a sync has ever happened, so a document edited after its
     * last successful export still reads as out of sync.
     */
    public function isUpToDateWith(Document $document): bool
    {
        if ($this->status !== DriveSyncStatus::Synced) {
            return false;
        }

        return $this->last_synced_checksum === $document->currentVersion?->checksum_sha256;
    }
}
