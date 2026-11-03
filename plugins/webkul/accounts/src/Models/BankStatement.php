<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankStatement extends Model
{
    use HasFactory;

    protected $table = 'accounts_bank_statements';

    protected $fillable = [
        'company_id',
        'journal_id',
        'currency_id',
        'company_currency_id',
        'bank_gl_account_id',
        'creator_id',
        'name',
        'reference',
        'bank_name',
        'bank_account_number',
        'account_title',
        'first_line_index',
        'date',
        'statement_start_date',
        'statement_end_date',
        'opening_balance',
        'total_debits',
        'total_credits',
        'closing_balance',
        'detected_currency_code',
        'currency_was_overridden',
        'company_opening_balance',
        'company_total_debits',
        'company_total_credits',
        'company_closing_balance',
        'conversion_status',
        'original_filename',
        'file_hash',
        'source_sheet',
        'parser',
        'import_status',
        'validation_errors',
        'reconciliation_override_by_id',
        'reconciliation_override_at',
        'reconciliation_override_reason',
        'reconciliation_override_errors',
        'raw_header',
        'balance_start',
        'balance_end',
        'balance_end_real',
        'is_completed',
    ];

    protected function casts(): array
    {
        return [
            'date'                           => 'date',
            'statement_start_date'           => 'date',
            'statement_end_date'             => 'date',
            'opening_balance'                => 'decimal:4',
            'total_debits'                   => 'decimal:4',
            'total_credits'                  => 'decimal:4',
            'closing_balance'                => 'decimal:4',
            'currency_was_overridden'        => 'boolean',
            'company_opening_balance'        => 'decimal:4',
            'company_total_debits'           => 'decimal:4',
            'company_total_credits'          => 'decimal:4',
            'company_closing_balance'        => 'decimal:4',
            'balance_start'                  => 'decimal:4',
            'balance_end'                    => 'decimal:4',
            'balance_end_real'               => 'decimal:4',
            'is_completed'                   => 'boolean',
            'validation_errors'              => 'array',
            'reconciliation_override_at'     => 'datetime',
            'reconciliation_override_errors' => 'array',
            'raw_header'                     => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function companyCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'company_currency_id');
    }

    public function bankGlAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_gl_account_id');
    }

    /**
     * The reviewer who accepted this statement's reported reconciliation
     * differences (BankStatementImportService::acceptReconciliationDifferences()).
     */
    public function reconciliationOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciliation_override_by_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'statement_id')->orderBy('sort');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($bankStatement) {
            $bankStatement->creator_id ??= Auth::id();
        });
    }
}
