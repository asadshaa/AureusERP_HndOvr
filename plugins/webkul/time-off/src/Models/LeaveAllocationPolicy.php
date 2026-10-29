<?php

namespace Webkul\TimeOff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Yearly allocation policy of one leave type in one company: days granted
 * every 1 January and the most unused days that may be carried forward from
 * the previous year (YearlyLeaveAllocationService).
 *
 * @property int $company_id
 * @property int $leave_type_id
 * @property string $annual_days
 * @property string $carry_forward_cap
 * @property bool $is_active
 */
class LeaveAllocationPolicy extends Model
{
    protected $table = 'time_off_allocation_policies';

    /**
     * The leave policy (decision of 2026-10-29): 25 days a year, Annual 12,
     * Sick 8 and Casual 5. Only Annual Leave carries forward, at most 6 unused
     * days, and carried days do not expire. Used for the yearly allocation and
     * the hire-time allocation (AllocateDefaultLeaveOnEmployeeCreated), both
     * prorated for the joining year.
     *
     * @var array<string, array{annual_days: float, carry_forward_cap: float}>
     */
    public const DEFAULTS = [
        'Annual Leave' => ['annual_days' => 12, 'carry_forward_cap' => 6],
        'Sick Leave'   => ['annual_days' => 8, 'carry_forward_cap' => 0],
        'Casual Leave' => ['annual_days' => 5, 'carry_forward_cap' => 0],
    ];

    protected $fillable = [
        'company_id',
        'leave_type_id',
        'annual_days',
        'carry_forward_cap',
        'is_active',
        'creator_id',
    ];

    protected $casts = [
        'annual_days'       => 'decimal:2',
        'carry_forward_cap' => 'decimal:2',
        'is_active'         => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * The company's policies, adding the defaults for its own Annual / Sick /
     * Casual Leave types when the company has no policy for that leave type
     * name yet (one per name, the oldest type, even if a duplicate type of
     * the same name exists). With $persist = false (dry run) the defaults are
     * returned unsaved.
     *
     * @return Collection<int, self>
     */
    public static function forCompany(int $companyId, bool $persist = true): Collection
    {
        $policies = self::query()->with('leaveType')->where('company_id', $companyId)->get();
        $coveredNames = $policies->map(fn (self $policy): ?string => $policy->leaveType?->name)->filter()->all();

        $missing = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereIn('name', array_diff(array_keys(self::DEFAULTS), $coveredNames))
            ->whereNotIn('id', $policies->pluck('leave_type_id'))
            ->orderBy('id')
            ->get()
            ->unique('name');

        foreach ($missing as $leaveType) {
            $attributes = [
                'company_id'    => $companyId,
                'leave_type_id' => $leaveType->id,
                'is_active'     => true,
            ] + self::DEFAULTS[$leaveType->name];

            $policy = $persist ? self::query()->firstOrCreate(
                ['company_id' => $companyId, 'leave_type_id' => $leaveType->id],
                $attributes,
            ) : new self($attributes);

            $policy->setRelation('leaveType', $leaveType);
            $policies->push($policy);
        }

        return $policies;
    }

    /** The annual days for a leave type in a company, or null when it has no active policy. */
    public static function annualDaysFor(int $companyId, int $leaveTypeId): ?float
    {
        $policy = self::query()
            ->where('company_id', $companyId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('is_active', true)
            ->first();

        return $policy ? (float) $policy->annual_days : null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $policy): void {
            $policy->creator_id ??= Auth::id();
        });
    }
}
