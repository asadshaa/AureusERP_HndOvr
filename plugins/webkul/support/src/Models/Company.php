<?php

namespace Webkul\Support\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Security\Traits\HasPermissionScope;
use Webkul\Support\Database\Factories\CompanyFactory;

class Company extends Model implements Sortable
{
    use HasChatter, HasCustomFields, HasFactory, HasPermissionScope, SoftDeletes, SortableTrait;

    protected $fillable = [
        'sort',
        'name',
        'company_id',
        'parent_id',
        'tax_id',
        'is_sales_tax_registered',
        'strn',
        'registration_number',
        'email',
        'phone',
        'mobile',
        'street1',
        'street2',
        'city',
        'zip',
        'state_id',
        'country_id',
        'logo',
        'color',
        'is_active',
        'founded_date',
        'creator_id',
        'currency_id',
        'fx_gain_account_id',
        'fx_loss_account_id',
        'rate_source_priority',
        'allow_previous_rate_fallback',
        'pnl_translation_policy',
        'balance_sheet_translation_policy',
        'partner_id',
        'website',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active'                     => 'boolean',
            'is_sales_tax_registered'       => 'boolean',
            'founded_date'                  => 'date',
            'rate_source_priority'          => 'array',
            'allow_previous_rate_fallback'  => 'boolean',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'parent_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Company::class, 'parent_id');
    }

    public function scopeParents($query)
    {
        return $query->whereNull('parent_id');
    }

    public function isBranch(): bool
    {
        return ! is_null($this->parent_id);
    }

    public function isParent(): bool
    {
        return is_null($this->parent_id);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function enabledCurrencies(): BelongsToMany
    {
        return $this->belongsToMany(Currency::class, 'accounting_company_currencies')
            ->withPivot(['transaction_enabled', 'reporting_enabled'])
            ->withTimestamps();
    }

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }

    public function fxGainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'fx_gain_account_id');
    }

    public function fxLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'fx_loss_account_id');
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function parents()
    {
        $parents = collect();

        $current = $this->parent;

        while ($current) {
            $parents->push($current);

            $current = $current->parent;
        }

        return $parents;
    }

    public function getParentsAttribute()
    {
        return $this->parents();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($company) {
            $company->creator_id ??= Auth::id();

            if (! $company->partner_id) {
                $partner = Partner::create([
                    'creator_id'       => $company->creator_id ?? Auth::id(),
                    'sub_type'         => 'company',
                    'company_registry' => $company->registration_number,
                    'name'             => $company->name,
                    'email'            => $company->email,
                    'website'          => $company->website,
                    'tax_id'           => $company->tax_id,
                    'phone'            => $company->phone,
                    'mobile'           => $company->mobile,
                    'color'            => $company->color,
                    'street1'          => $company->street1,
                    'street2'          => $company->street2,
                    'city'             => $company->city,
                    'zip'              => $company->zip,
                    'state_id'         => $company->state_id,
                    'country_id'       => $company->country_id,
                    'parent_id'        => $company->parent_id,
                    'company_id'       => $company->id,
                ]);

                $company->partner_id = $partner->id;
            }
        });

        static::saved(function ($company) {
            Partner::updateOrCreate(
                [
                    'id' => $company->partner_id,
                ],
                [
                    'sub_type'         => 'company',
                    'company_registry' => $company->registration_number,
                    'name'             => $company->name,
                    'email'            => $company->email,
                    'website'          => $company->website,
                    'tax_id'           => $company->tax_id,
                    'phone'            => $company->phone,
                    'mobile'           => $company->mobile,
                    'color'            => $company->color,
                    'street1'          => $company->street1,
                    'street2'          => $company->street2,
                    'city'             => $company->city,
                    'zip'              => $company->zip,
                    'state_id'         => $company->state_id,
                    'country_id'       => $company->country_id,
                    'parent_id'        => $company->parent_id,
                    'company_id'       => $company->id,
                ]
            );

            if ($company->currency_id && Schema::hasTable('accounting_company_currencies')) {
                DB::table('accounting_company_currencies')->updateOrInsert(
                    ['company_id' => $company->id, 'currency_id' => $company->currency_id],
                    ['transaction_enabled' => true, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });
    }
}
