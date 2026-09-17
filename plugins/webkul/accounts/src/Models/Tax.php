<?php

namespace Webkul\Account\Models;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Account\Database\Factories\TaxFactory;
use Webkul\Account\Enums\AmountType;
use Webkul\Account\Enums\DocumentType;
use Webkul\Account\Enums\RepartitionType;
use Webkul\Account\Enums\TaxIncludeOverride;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Settings\TaxesSettings;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;

class Tax extends Model implements Sortable
{
    use HasFactory, SortableTrait;

    protected $table = 'accounts_taxes';

    protected $fillable = [
        'sort',
        'company_id',
        'tax_group_id',
        'cash_basis_transition_account_id',
        'country_id',
        'creator_id',
        'type_tax_use',
        'tax_scope',
        'amount_type',
        'price_include_override',
        'tax_exigibility',
        'name',
        'description',
        'invoice_label',
        'invoice_legal_notes',
        'amount',
        'is_active',
        'include_base_amount',
        'is_base_affected',
        'analytic',
    ];

    protected $casts = [
        'amount_type'            => AmountType::class,
        'type_tax_use'           => TypeTaxUse::class,
        'price_include_override' => TaxIncludeOverride::class,
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        if (! $companyId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $companyId);
    }

    public static function scopeTaxQuery(
        Builder $query,
        ?int $companyId,
        ?TypeTaxUse $typeTaxUse = null,
        array $existingTaxIds = [],
    ): Builder {
        $query->forCompany($companyId)
            ->where(function (Builder $q) use ($existingTaxIds) {
                $q->active();

                if (! empty($existingTaxIds)) {
                    $q->orWhereIn('accounts_taxes.id', $existingTaxIds);
                }
            });

        if ($typeTaxUse) {
            $query->where('accounts_taxes.type_tax_use', $typeTaxUse);
        }

        return $query;
    }

    public static function taxValidationRule(?TypeTaxUse $expectedType = null): Closure
    {
        return function ($get, ?Model $record = null) use ($expectedType) {
            return function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $expectedType): void {
                $taxIds = array_filter(Arr::wrap($value));

                if (empty($taxIds)) {
                    return;
                }

                $companyId = is_callable($get)
                    ? ($get('../../company_id') ?? $get('company_id') ?? Auth::user()?->default_company_id)
                    : Auth::user()?->default_company_id;

                if (! $companyId) {
                    $fail(__('The company context is missing.'));

                    return;
                }

                $user = Auth::user();

                if ($user) {
                    $authorized = (int) $user->default_company_id === (int) $companyId
                        || $user->allowedCompanies()->where('companies.id', $companyId)->exists();

                    if (! $authorized) {
                        $fail(__('The selected company is not authorized.'));

                        return;
                    }
                }

                $existingTaxIds = $record && $record->exists && method_exists($record, 'taxes')
                    ? $record->taxes()->pluck('accounts_taxes.id')->map(fn ($id) => (int) $id)->all()
                    : [];

                $validTaxesQuery = static::query();

                static::scopeTaxQuery($validTaxesQuery, (int) $companyId, $expectedType, $existingTaxIds);

                $validTaxIds = $validTaxesQuery->whereIn('accounts_taxes.id', $taxIds)
                    ->pluck('accounts_taxes.id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $invalidTaxIds = array_diff(array_map('intval', $taxIds), $validTaxIds);

                if (! empty($invalidTaxIds)) {
                    $fail(__('The selected tax is invalid, inactive, or belongs to another company.'));
                }
            };
        };
    }

    public function taxGroup()
    {
        return $this->belongsTo(TaxGroup::class, 'tax_group_id');
    }

    public function cashBasisTransitionAccount()
    {
        return $this->belongsTo(Account::class, 'cash_basis_transition_account_id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function childrenTaxes()
    {
        return $this->belongsToMany(self::class, 'accounts_tax_taxes', 'parent_tax_id', 'child_tax_id');
    }

    public function invoiceRepartitionLines()
    {
        return $this->hasMany(TaxPartition::class, 'tax_id')
            ->where('document_type', DocumentType::INVOICE);
    }

    public function refundRepartitionLines()
    {
        return $this->hasMany(TaxPartition::class, 'tax_id')
            ->where('document_type', DocumentType::REFUND);
    }

    public function getHasNegativeFactorAttribute(): bool
    {
        return $this->invoiceRepartitionLines()
            ->where('repartition_type', RepartitionType::TAX)
            ->where('factor_percent', '<', 0)
            ->exists();
    }

    public function getPriceIncludeAttribute()
    {
        return $this->price_include_override == TaxIncludeOverride::TAX_INCLUDED
            || (new TaxesSettings)->account_price_include == TaxIncludeOverride::TAX_INCLUDED && ! $this->price_include_override;
    }

    public function evalTaxAmountFixedAmount($batch, $rawBase, $evaluationContext)
    {
        if ($this->amount_type === AmountType::FIXED) {
            $sign = $evaluationContext['price_unit'] < 0.0 ? -1 : 1;

            return $sign * $evaluationContext['quantity'] * $this->amount;
        }
    }

    public function evalTaxAmountPriceIncluded($batch, $rawBase, $evaluationContext)
    {
        if ($this->amount_type === AmountType::PERCENT) {
            $totalPercentage = array_sum(array_map(function ($tax) {
                return $tax->amount;
            }, $batch)) / 100.0;

            $toPriceExcludedFactor = ($totalPercentage != -1)
                ? 1 / (1 + $totalPercentage)
                : 0.0;

            return $rawBase * $toPriceExcludedFactor * $this->amount / 100.0;
        }

        if ($this->amount_type === AmountType::DIVISION) {
            return $rawBase * $this->amount / 100.0;
        }
    }

    public function evalTaxAmountPriceExcluded($batch, $rawBase, $evaluationContext)
    {
        if ($this->amount_type === AmountType::PERCENT) {
            return $rawBase * $this->amount / 100.0;
        }

        if ($this->amount_type === AmountType::DIVISION) {
            $totalPercentage = array_sum(array_map(function ($tax) {
                return $tax->amount;
            }, $batch)) / 100.0;

            $inclBaseMultiplicator = ($totalPercentage == 1.0)
                ? 1.0
                : 1 - $totalPercentage;

            return $rawBase * $this->amount / 100.0 / $inclBaseMultiplicator;
        }
    }

    public function parentTaxes()
    {
        return $this->belongsToMany(self::class, 'accounts_tax_taxes', 'child_tax_id', 'parent_tax_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tax) {
            $tax->creator_id ??= Auth::id();
        });

        static::saved(function (self $tax) {
            try {
                if ($tax->invoiceRepartitionLines()->exists() && $tax->refundRepartitionLines()->exists()) {
                    TaxPartition::validateRepartitionLines($tax->id);
                }
            } catch (Exception $e) {
                throw $e;
            }
        });
    }

    protected static function newFactory(): TaxFactory
    {
        return TaxFactory::new();
    }
}
