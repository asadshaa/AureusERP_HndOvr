<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Database\Factories\DocumentAttachmentFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class DocumentAttachment extends Model
{
    use HasFactory;

    protected static function newFactory(): DocumentAttachmentFactory
    {
        return DocumentAttachmentFactory::new();
    }

    protected $table = 'accounting_document_attachments';

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        if (! $companyId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $companyId);
    }

    protected static function booted(): void
    {
        static::creating(function (self $attachment): void {
            $attachment->creator_id ??= Auth::id();
        });
    }
}
