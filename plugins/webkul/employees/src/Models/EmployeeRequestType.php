<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class EmployeeRequestType extends Model
{
    protected $table = 'employees_request_types';

    protected $fillable = [
        'company_id', 'journal_id', 'debit_account_id', 'credit_account_id', 'creator_id',
        'code', 'name', 'category', 'approval_request_type', 'is_financial', 'requires_amount',
        'requires_document', 'is_active', 'configuration',
    ];

    protected function casts(): array
    {
        return [
            'is_financial'      => 'boolean',
            'requires_amount'   => 'boolean',
            'requires_document' => 'boolean',
            'is_active'         => 'boolean',
            'configuration'     => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class, 'request_type_id');
    }

    /**
     * Per-type "nature of expense" options, admin-configured through the
     * existing generic `configuration` KeyValue field rather than a new
     * table -- e.g. configuration: {"expense_natures": "Team event, Gift, Training"}.
     * Nothing read this column before Section 8; it was write-only.
     *
     * @return array<int, string>
     */
    public function getExpenseNatures(): array
    {
        $raw = data_get($this->configuration, 'expense_natures', []);
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }

        return array_values(array_filter($raw, fn ($value): bool => filled($value)));
    }

    protected static function booted(): void
    {
        static::creating(function (self $type): void {
            $type->creator_id ??= Auth::id();
        });
    }
}
