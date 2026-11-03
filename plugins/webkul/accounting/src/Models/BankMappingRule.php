<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Models\Concerns\AuditsConfiguration;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankMappingRule extends Model
{
    use AuditsConfiguration;

    protected $table = 'accounting_bank_mapping_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'minimum_amount' => 'decimal:4',
            'maximum_amount' => 'decimal:4',
            'confidence'     => 'decimal:4',
            'is_active'      => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bankGlAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_gl_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function offsetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'offset_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $rule): void {
            $rule->creator_id ??= Auth::id();
        });
    }
}
