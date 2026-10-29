<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One instalment of an approved loan, or the single deduction of an approved
 * salary advance (RequestInstalmentService). "due" is what the payroll-input
 * export picks up for its month; HR marks it deducted, skipped or settled.
 */
class RequestInstalment extends Model
{
    public const Due = 'due';

    public const Deducted = 'deducted';

    public const Skipped = 'skipped';

    public const Settled = 'settled';

    public const KindLoan = 'loan';

    public const KindAdvance = 'salary_advance';

    protected $table = 'employees_request_instalments';

    protected $fillable = [
        'company_id',
        'employee_id',
        'request_id',
        'kind',
        'number',
        'due_month',
        'amount',
        'status',
        'note',
        'status_changed_at',
        'status_changed_by',
    ];

    protected function casts(): array
    {
        return [
            'number'            => 'integer',
            'amount'            => 'decimal:4',
            'status_changed_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::Due      => 'Due',
            self::Deducted => 'Deducted',
            self::Skipped  => 'Skipped',
            self::Settled  => 'Settled',
        ];
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::Deducted => 'success',
            self::Skipped  => 'warning',
            self::Settled  => 'info',
            default        => 'gray',
        };
    }

    public static function kindLabel(?string $kind): string
    {
        return $kind === self::KindAdvance ? 'Salary advance' : 'Loan';
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(EmployeeRequest::class, 'request_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RequestInstalmentEvent::class, 'instalment_id');
    }
}
