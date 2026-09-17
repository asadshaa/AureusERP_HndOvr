<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Accounting\Database\Factories\DocumentAuditFactory;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class DocumentAudit extends Model
{
    use HasFactory;

    protected static function newFactory(): DocumentAuditFactory
    {
        return DocumentAuditFactory::new();
    }

    protected $table = 'accounting_document_audits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'action'   => DocumentAuditAction::class,
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        if (! $companyId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $companyId);
    }
}
