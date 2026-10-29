<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Services\Attendance\AttendanceMonthLockService;
use Webkul\Employee\Services\Attendance\RosterPresence;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One employee's shift on one date, overriding the roster and the work
 * schedule: a changed shift, a day off (shift_id null) or one side of a
 * swap with a colleague. Always with a reason. One per employee per date.
 *
 * Enforced on every save: the employee and the shift of the same company, a
 * reason, and no change to a day of a finalised attendance month.
 */
class ShiftOverride extends Model
{
    protected $table = 'employees_shift_overrides';

    public const KindChange = 'change';

    public const KindOff = 'off';

    public const KindSwap = 'swap';

    protected $fillable = [
        'company_id',
        'employee_id',
        'date',
        'shift_id',
        'kind',
        'swap_employee_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function swapEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'swap_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::saving(function (self $override): void {
            $override->validateOverride();

            app(AttendanceMonthLockService::class)->guardShiftOverride($override);

            $override->updated_by = Auth::id() ?? $override->updated_by;
            if (! $override->exists) {
                $override->created_by ??= Auth::id();
            }
        });

        static::deleting(function (self $override): void {
            app(AttendanceMonthLockService::class)->guardShiftOverride($override);
        });

        static::saved(fn (self $override) => RosterPresence::forget((int) $override->company_id));
        static::deleted(fn (self $override) => RosterPresence::forget((int) $override->company_id));
    }

    /** @throws ValidationException */
    protected function validateOverride(): void
    {
        $companyId = Employee::query()->withTrashed()->whereKey($this->employee_id)->value('company_id');
        if ($companyId === null) {
            throw ValidationException::withMessages(['employee_id' => 'Choose the employee.']);
        }

        $this->company_id ??= (int) $companyId;
        if ((int) $companyId !== (int) $this->company_id) {
            throw ValidationException::withMessages(['employee_id' => 'The employee belongs to another company.']);
        }

        if (! $this->date) {
            throw ValidationException::withMessages(['date' => 'Choose the date.']);
        }

        if ($this->shift_id !== null) {
            $shiftCompany = Shift::query()->whereKey($this->shift_id)->value('company_id');
            if ((int) $shiftCompany !== (int) $this->company_id) {
                throw ValidationException::withMessages(['shift_id' => 'The shift belongs to another company.']);
            }
        }

        if ($this->swap_employee_id !== null) {
            $swapCompany = Employee::query()->withTrashed()->whereKey($this->swap_employee_id)->value('company_id');
            if ((int) $swapCompany !== (int) $this->company_id) {
                throw ValidationException::withMessages(['swap_employee_id' => 'Shifts can only be swapped within one company.']);
            }
        }

        $this->reason = trim((string) $this->reason);
        if (mb_strlen($this->reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the change.']);
        }
        $this->reason = mb_substr($this->reason, 0, 255);

        $this->kind = match (true) {
            $this->swap_employee_id !== null => self::KindSwap,
            $this->shift_id === null         => self::KindOff,
            default                          => self::KindChange,
        };
    }
}
