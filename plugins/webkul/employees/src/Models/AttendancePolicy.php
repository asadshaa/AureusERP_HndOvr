<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A company's attendance time-bank policy. forCompany() always returns a
 * policy: the saved row, or an unsaved one filled with the config defaults
 * (config/attendance.php -> time_bank.*).
 */
class AttendancePolicy extends Model
{
    protected $table = 'employees_attendance_policies';

    protected $fillable = [
        'company_id',
        'monthly_grace_minutes',
        'daily_extra_credit_cap_minutes',
        'carry_over',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'monthly_grace_minutes'          => 'integer',
            'daily_extra_credit_cap_minutes' => 'integer',
            'carry_over'                     => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array{monthly_grace_minutes: int, daily_extra_credit_cap_minutes: int, carry_over: bool} */
    public static function defaults(): array
    {
        return [
            'monthly_grace_minutes'          => max(0, (int) config('attendance.time_bank.monthly_grace_minutes', 0)),
            'daily_extra_credit_cap_minutes' => max(0, (int) config('attendance.monthly_report.max_outside_credit_minutes', 60)),
            'carry_over'                     => (bool) config('attendance.time_bank.carry_over', false),
        ];
    }

    public static function forCompany(int $companyId): self
    {
        static $tableExists = null;
        $tableExists ??= Schema::hasTable('employees_attendance_policies');

        $policy = $tableExists ? static::query()->where('company_id', $companyId)->first() : null;

        return $policy ?? new static(['company_id' => $companyId] + static::defaults());
    }
}
