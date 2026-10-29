<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Models\Company;

/**
 * Per-company job offer settings: the monthly gross salary above which an
 * offer also needs the CEO's approval (empty = config
 * recruitment.offers.ceo_approval_threshold).
 */
class OfferSetting extends Model
{
    protected $table = 'recruitments_offer_settings';

    protected $fillable = [
        'company_id',
        'ceo_approval_threshold',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'ceo_approval_threshold' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The company's CEO approval threshold (monthly gross, company currency). */
    public static function ceoThresholdFor(int $companyId): string
    {
        $value = static::query()->where('company_id', $companyId)->value('ceo_approval_threshold');

        return $value !== null ? (string) $value : number_format((float) config('recruitment.offers.ceo_approval_threshold', 300000), 2, '.', '');
    }
}
