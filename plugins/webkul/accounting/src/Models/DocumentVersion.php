<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Webkul\Accounting\Database\Factories\DocumentVersionFactory;
use Webkul\Security\Models\User;

class DocumentVersion extends Model
{
    use HasFactory;

    /**
     * A version is written once, in DocumentService::storeVersion(), and
     * never again -- "replacing" a document's file always means inserting a
     * NEW version, so every prior version's checksum and content stay
     * exactly what they were when uploaded. This is the enforcement behind
     * that guarantee: no application code path (now or added later) can
     * silently mutate an existing version row, even by accident.
     */
    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            throw new RuntimeException(
                'Document versions are immutable and cannot be updated once created. '.
                'To replace a document\'s file, add a new version through DocumentService::addVersion() instead.'
            );
        });
    }

    protected static function newFactory(): DocumentVersionFactory
    {
        return DocumentVersionFactory::new();
    }

    protected $table = 'accounting_document_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
