<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A company's offer letter template (Recruitment > Configurations > Offer
 * letter templates): rich text with {{placeholders}} merged by
 * OfferLetterRenderer, and the email subject. One default per company.
 */
class OfferLetterTemplate extends Model
{
    protected $table = 'recruitments_offer_letter_templates';

    protected $fillable = [
        'company_id',
        'name',
        'email_subject',
        'body',
        'is_default',
        'is_active',
        'creator_id',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active'  => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /** The company's default active template, else its first active one. */
    public static function preferredFor(int $companyId): ?self
    {
        return static::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            $template->creator_id ??= Auth::id();
        });

        // One default per company: making a template the default clears the others.
        static::saved(function (self $template): void {
            if ($template->is_default && ($template->wasRecentlyCreated || $template->wasChanged('is_default'))) {
                static::query()
                    ->where('company_id', $template->company_id)
                    ->whereKeyNot($template->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }
}
