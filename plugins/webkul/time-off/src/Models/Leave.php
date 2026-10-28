<?php

namespace Webkul\TimeOff\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\RequestDateFromPeriod;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\TimeOff\Services\LeaveBalanceService;
use Webkul\TimeOff\Services\ShortLeavePolicy;

class Leave extends Model
{
    use HasChatter, HasFactory, HasLogActivity;

    public const ACTIVITY_PLAN_PLUGIN = 'time-off';

    protected $table = 'time_off_leaves';

    protected $attributes = [
        'state' => 'confirm',
    ];

    public function getModelTitle(): string
    {
        return __('time-off::models/leave.title');
    }

    public function getNameAttribute(): ?string
    {
        return $this->holidayStatus?->name ?? __('time-off::models/leave.title');
    }

    protected $fillable = [
        'user_id',
        'manager_id',
        'holiday_status_id',
        'employee_id',
        'employee_company_id',
        'company_id',
        'department_id',
        'calendar_id',
        'meeting_id',
        'first_approver_id',
        'second_approver_id',
        'approval_request_id',
        'creator_id',
        'private_name',
        'attachment',
        'state',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'duration_display',
        'request_date_from_period',
        'request_date_from',
        'request_date_to',
        'notes',
        'request_unit_half',
        'request_unit_hours',
        'date_from',
        'date_to',
        'number_of_days',
        'number_of_hours',
        'request_hour_from',
        'request_hour_to',
        'cancellation_requested_at',
        'cancellation_requested_by',
        'cancellation_request_reason',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    public function getLogAttributeLabels(): array
    {
        return [
            'user.name'                => __('time-off::models/leave.log-attributes.user'),
            'manger.name'              => __('time-off::models/leave.log-attributes.manager'),
            'holidayStatus.name'       => __('time-off::models/leave.log-attributes.holiday_status'),
            'employee.name'            => __('time-off::models/leave.log-attributes.employee'),
            'employeeCompany.name'     => __('time-off::models/leave.log-attributes.employee_company'),
            'department.name'          => __('time-off::models/leave.log-attributes.department'),
            'calendar.name'            => __('time-off::models/leave.log-attributes.calendar'),
            'firstApprover.name'       => __('time-off::models/leave.log-attributes.first_approver'),
            'lastApprover.name'        => __('time-off::models/leave.log-attributes.last_approver'),
            'private_name'             => __('time-off::models/leave.log-attributes.description'),
            'state'                    => __('time-off::models/leave.log-attributes.state'),
            'duration_display'         => __('time-off::models/leave.log-attributes.duration_display'),
            'request_date_from_period' => __('time-off::models/leave.log-attributes.request_date_from_period'),
            'request_date_from'        => __('time-off::models/leave.log-attributes.request_date_from'),
            'request_date_to'          => __('time-off::models/leave.log-attributes.request_date_to'),
            'notes'                    => __('time-off::models/leave.log-attributes.notes'),
            'request_unit_half'        => __('time-off::models/leave.log-attributes.request_unit_half'),
            'request_unit_hours'       => __('time-off::models/leave.log-attributes.request_unit_hours'),
            'date_from'                => __('time-off::models/leave.log-attributes.date_from'),
            'date_to'                  => __('time-off::models/leave.log-attributes.date_to'),
            'number_of_days'           => __('time-off::models/leave.log-attributes.number_of_days'),
            'number_of_hours'          => __('time-off::models/leave.log-attributes.number_of_hours'),
            'request_hour_from'        => __('time-off::models/leave.log-attributes.request_hour_from'),
            'request_hour_to'          => __('time-off::models/leave.log-attributes.request_hour_to'),
        ];
    }

    protected $casts = [
        'state'                     => State::class,
        'request_date_from_period'  => RequestDateFromPeriod::class,
        'request_date_from'         => 'date',
        'date_from'                 => 'date',
        'request_unit_half'         => 'boolean',
        'request_unit_hours'        => 'boolean',
        'number_of_hours'           => 'decimal:4',
        'submitted_at'              => 'datetime',
        'approved_at'               => 'datetime',
        'rejected_at'               => 'datetime',
        'cancellation_requested_at' => 'datetime',
        'cancelled_at'              => 'datetime',
    ];

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function cancellationRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancellation_requested_by');
    }

    /** Waiting for a decision (submitted or not yet submitted). */
    public function isPending(): bool
    {
        return in_array($this->state, [State::CONFIRM, State::VALIDATE_ONE], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function holidayStatus(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'holiday_status_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function employeeCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'employee_company_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'calendar_id');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'first_approver_id');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'second_approver_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function synchronizeApprovalState(ApprovalRequest $request): void
    {
        if ((int) $this->approval_request_id === (int) $request->id) {
            app(LeaveApprovalService::class)->synchronize($this);
        }
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($leave) {
            $authUser = Auth::user();

            $leave->creator_id ??= $authUser?->id;

            $leave->company_id ??= $authUser?->default_company_id;
        });

        // Short leave limits (max hours each, max per month), whatever path writes the request.
        static::saving(function (self $leave): void {
            if ((bool) $leave->request_unit_hours
                && (! $leave->exists || $leave->isDirty(['request_unit_hours', 'request_hour_from', 'request_hour_to', 'request_date_from', 'request_date_to', 'employee_id']))) {
                app(ShortLeavePolicy::class)->validate([
                    'employee_id'        => $leave->employee_id,
                    'request_unit_hours' => true,
                    'request_hour_from'  => $leave->request_hour_from,
                    'request_hour_to'    => $leave->request_hour_to,
                    'request_date_from'  => $leave->request_date_from?->toDateString(),
                    'request_date_to'    => $leave->request_date_to ? Carbon::parse($leave->request_date_to)->toDateString() : null,
                ], $leave->exists ? (int) $leave->id : null);
            }

            // Leave balance (LeaveBalanceService), whatever path writes the request: a new
            // or changed pending request, or a refused / cancelled one put back to pending.
            $state = $leave->state instanceof State ? $leave->state->value : (string) ($leave->state ?? State::CONFIRM->value);
            $original = $leave->getRawOriginal('state');
            if (in_array($state, LeaveBalanceService::PENDING_STATES, true)
                && (! $leave->exists
                    || $leave->isDirty(['holiday_status_id', 'number_of_days', 'request_date_from', 'employee_id'])
                    || ($leave->isDirty('state') && ! in_array((string) $original, LeaveBalanceService::PENDING_STATES, true)))) {
                app(LeaveBalanceService::class)->assertLeaveFits($leave);
            }
        });
    }
}
