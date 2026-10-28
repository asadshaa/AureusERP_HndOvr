<?php

namespace Webkul\TimeOff\Services;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Carbon;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;
use Webkul\TimeOff\Enums\RequestDateFromPeriod;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;

class LeaveApprovalService
{
    public function __construct(protected ApprovalEngine $approvals) {}

    public function submit(Leave $leave, User $requester): ApprovalRequest
    {
        $leave->loadMissing('employee');
        if (! $leave->employee || (int) $leave->employee->company_id !== (int) $leave->company_id) {
            throw new RuntimeException('The leave employee does not belong to the leave company.');
        }
        if (
            (int) $leave->employee->user_id !== (int) $requester->id
            && ! app(HrHierarchyService::class)->canManage($requester, $leave->employee)
            && ! $requester->can('hr_approve_leave')
        ) {
            throw new RuntimeException('A user can only submit leave for their own HR hierarchy.');
        }
        if (! in_array($leave->state, [State::CONFIRM, State::REFUSE], true)) {
            throw new RuntimeException('Only a new or refused leave request can be submitted.');
        }
        if ($leave->request_unit_hours) {
            // Re-checked on submit: a refused request resubmitted must still fit this month's limit.
            app(ShortLeavePolicy::class)->validate([
                'employee_id'        => $leave->employee_id,
                'request_unit_hours' => true,
                'request_hour_from'  => $leave->request_hour_from,
                'request_hour_to'    => $leave->request_hour_to,
                'request_date_from'  => $leave->request_date_from?->toDateString(),
                'request_date_to'    => $leave->request_date_to ? Carbon::parse($leave->request_date_to)->toDateString() : null,
            ], (int) $leave->id);
        }

        $approval = $this->approvals->submit(
            $leave,
            $requester,
            'leave_request',
            null,
            [
                'company_id'    => (int) $leave->company_id,
                'employee_id'   => (int) $leave->employee_id,
                'department_id' => $leave->department_id,
                'team_id'       => $leave->employee->team_id,
                'leave_type_id' => $leave->holiday_status_id,
                'days'          => (string) $leave->number_of_days,
            ],
        );

        $leave->update([
            'approval_request_id' => $approval->id,
            'state'               => State::CONFIRM,
            'submitted_at'        => now(),
            'approved_at'         => null,
            'rejected_at'         => null,
            'rejection_reason'    => null,
        ]);

        $this->notifyApprovers($leave, $requester);

        return $approval;
    }

    public function approve(Leave $leave, User $actor, ?string $reason = null): Leave
    {
        $approval = $leave->approvalRequest
            ?? throw new RuntimeException('The leave request has not been submitted.');
        $this->approvals->approve($approval, $actor, $reason);

        return $this->synchronize($leave);
    }

    public function reject(Leave $leave, User $actor, string $reason): Leave
    {
        $approval = $leave->approvalRequest
            ?? throw new RuntimeException('The leave request has not been submitted.');
        $this->approvals->reject($approval, $actor, $reason);

        return $this->synchronize($leave);
    }

    public function synchronize(Leave $leave): Leave
    {
        $leave->load('approvalRequest.decisions.actor.employee');
        $approval = $leave->approvalRequest;
        // A withdrawn or cancelled leave keeps its state whatever the approval request says.
        if (! $approval || $leave->state === State::CANCEL || $approval->status === LeaveCancellationService::WITHDRAWN_STATUS) {
            return $leave;
        }

        $firstApprover = $approval->decisions->first()?->actor?->employee;
        $lastApprover = $approval->decisions->last()?->actor?->employee;
        if ($approval->status === 'approved') {
            $leave->update([
                'state'              => State::VALIDATE_TWO,
                'first_approver_id'  => $firstApprover?->id,
                'second_approver_id' => $lastApprover?->id,
                'approved_at'        => $approval->completed_at ?? now(),
                'rejected_at'        => null,
                'rejection_reason'   => null,
            ]);

            $this->notifyDecision($leave, 'approved');
        } elseif ($approval->status === 'rejected') {
            $reason = $approval->decisions->last()?->reason;
            $leave->update([
                'state'              => State::REFUSE,
                'first_approver_id'  => $firstApprover?->id,
                'second_approver_id' => $lastApprover?->id,
                'approved_at'        => null,
                'rejected_at'        => $approval->completed_at ?? now(),
                'rejection_reason'   => $reason,
            ]);

            $this->notifyDecision($leave, 'rejected', $reason);
        } elseif ($approval->decisions->isNotEmpty()) {
            $leave->update([
                'state'             => State::VALIDATE_ONE,
                'first_approver_id' => $firstApprover?->id,
            ]);

            $this->notifyDecision($leave, 'partially_approved');
        }

        return $leave->fresh(['approvalRequest.decisions']);
    }

    private function notifyApprovers(Leave $leave, User $requester): void
    {
        try {
            $leave->loadMissing(['employee', 'holidayStatus']);
            $employee = $leave->employee;
            if (! $employee) {
                return;
            }

            $parentId = $employee->parent_id;
            $manager = $parentId ? Employee::query()->with('user')->find($parentId)?->user : null;

            $hasManager = $manager && $manager->is_active && (int) $manager->id !== (int) $requester->id;

            if ($hasManager) {
                $recipients = collect([$manager]);
                $routingNote = '[Routed to Line Manager]';
            } else {
                $recipients = User::query()
                    ->where('default_company_id', $leave->company_id)
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $u): bool => (
                        $u->hasRole([
                            'Admin', 'Super Admin', 'hr', 'hr_manager', 'hr manager',
                            'hr_ops_manager', 'hr ops manager', 'hr operations manager',
                            'hr_administrator', 'hr administrator', 'human resources', 'human resources manager',
                        ])
                        || $u->can('hr_approve_leave')
                        || $u->can('hr_view_all_records')
                    ) && (int) $u->id !== (int) $requester->id);
                $routingNote = '[Forwarded to HR: no line manager assigned]';
            }

            if ($recipients->isEmpty()) {
                return;
            }

            $who = $employee->name.((int) $employee->user_id !== (int) $requester->id ? " (by {$requester->name})" : '');
            $leaveTypeName = $leave->holidayStatus?->name ?? 'Leave';
            $startDate = Carbon::parse($leave->request_date_from);
            $endDate = Carbon::parse($leave->request_date_to ?: $leave->request_date_from);

            $dayAndDate = "From {$startDate->format('l, d M Y')} to {$endDate->format('l, d M Y')} ({$leave->number_of_days} days)";
            $reason = $leave->private_name ? " | Note: {$leave->private_name}" : '';

            $title = "{$employee->name} requested time off: {$leaveTypeName}";
            $body = "{$who} submitted {$leaveTypeName} request ({$dayAndDate}){$reason} {$routingNote}";

            $notification = FilamentNotification::make()
                ->warning()
                ->title($title)
                ->body($body);

            foreach ($recipients as $recipient) {
                $recipient->notifyNow($notification->toDatabase());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * " on Wed, 28 Oct 2026 (first half, 10:00–14:00)", " on ... (14:00–16:00)"
     * for a short leave, or " from ... to ... (3 days)".
     */
    private function decisionWhen(Leave $leave): string
    {
        if (! $leave->request_date_from) {
            return $leave->date_from ? ' on '.Carbon::parse($leave->date_from)->format('D, d M Y') : '';
        }

        $start = Carbon::parse($leave->request_date_from);
        $end = $leave->request_date_to ? Carbon::parse($leave->request_date_to) : $start;

        if (! $start->isSameDay($end) && ! $leave->request_unit_half && ! $leave->request_unit_hours) {
            $days = $leave->number_of_days ? ' ('.(float) $leave->number_of_days.' days)' : '';

            return " from {$start->format('D, d M Y')} to {$end->format('D, d M Y')}{$days}";
        }

        $officeStart = $this->hoursFromClock((string) config('attendance.monthly_report.office_start', '10:00'));
        $officeEnd = $this->hoursFromClock((string) config('attendance.monthly_report.office_end', '18:00'));
        $middle = $officeStart + ($officeEnd - $officeStart) / 2;

        $window = null;
        if ($leave->request_unit_half) {
            $window = $leave->request_date_from_period === RequestDateFromPeriod::AFTERNOON
                ? 'second half, '.$this->clockFromHours($middle).'–'.$this->clockFromHours($officeEnd)
                : 'first half, '.$this->clockFromHours($officeStart).'–'.$this->clockFromHours($middle);
        } elseif ($leave->request_unit_hours && $leave->request_hour_from !== null && $leave->request_hour_to !== null) {
            $window = $this->clockFromHours((float) $leave->request_hour_from).'–'.$this->clockFromHours((float) $leave->request_hour_to);
        }

        return " on {$start->format('D, d M Y')}".($window !== null ? " ({$window})" : '');
    }

    private function hoursFromClock(string $clock): float
    {
        [$hours, $minutes] = array_map('intval', explode(':', $clock) + [1 => 0]);

        return $hours + $minutes / 60;
    }

    private function clockFromHours(float $hours): string
    {
        $minutes = (int) round($hours * 60);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function notifyDecision(Leave $leave, string $decision, ?string $reason = null): void
    {
        if (! config('attendance.notifications.leave_decision', true)) {
            return;
        }

        try {
            $leave->loadMissing(['employee.user', 'employee.parent', 'user', 'holidayStatus', 'approvalRequest.decisions.actor.employee']);
            $recipients = collect([$leave->user, $leave->employee?->user])
                ->filter(fn (?User $u): bool => $u && $u->is_active)
                ->unique('id');

            if ($recipients->isEmpty()) {
                return;
            }

            $type = $leave->holidayStatus?->name ?? 'Leave';
            $isApproved = $decision === 'approved';

            // Resolve who approved/rejected the leave request
            $approval = $leave->approvalRequest;
            $lastDecision = $approval?->decisions?->last();
            $actor = $lastDecision?->actor;
            $actorEmployee = $actor?->employee ?? ($lastDecision?->actor_id ? Employee::where('user_id', $lastDecision->actor_id)->first() : null);

            $isLineManager = $leave->employee && $actorEmployee && (int) $leave->employee->parent_id === (int) $actorEmployee->id;
            if ($isLineManager) {
                $approverRole = 'Line Manager';
            } elseif ($actor && ($actor->hasRole(['Admin', 'Super Admin', 'hr', 'hr_manager', 'hr manager', 'hr_ops_manager', 'hr ops manager', 'hr_administrator', 'human resources', 'human resources manager']) || $actor->can('hr_approve_leave') || $actor->can('hr_manage_attendance'))) {
                $approverRole = 'HR';
            } else {
                $approverRole = 'Line Manager';
            }

            $actorName = $actor?->name;
            $approverLabel = $actorName ? "{$actorName} ({$approverRole})" : $approverRole;

            $dateInfo = $this->decisionWhen($leave);

            $isApproved = $decision === 'approved';
            $isPartiallyApproved = $decision === 'partially_approved';

            if ($isPartiallyApproved) {
                $title = "Your {$type} was approved by {$approverRole}";
                $body = "Your {$type}{$dateInfo} was approved by {$approverLabel} and forwarded for final review.";
                $color = 'info';
                $icon = 'heroicon-o-check-circle';
            } elseif ($isApproved) {
                $title = "Your {$type} was approved";
                $body = "Your {$type}{$dateInfo} was approved by {$approverLabel}.";
                $color = 'success';
                $icon = 'heroicon-o-check-circle';
            } else {
                $title = "Your {$type} was refused";
                $body = "Your {$type}{$dateInfo} was refused by {$approverLabel}.".($reason ? " Reason: {$reason}" : '');
                $color = 'danger';
                $icon = 'heroicon-o-x-circle';
            }

            $notification = FilamentNotification::make()
                ->color($color)
                ->icon($icon)
                ->title($title)
                ->body($body);

            // synchronize() runs twice per decision (from the engine's subject
            // synchronizer and again from approve()/reject()), so the notice is
            // keyed per request + decision + step and sent at most once.
            $key = sprintf('leave_decision_%d_%s_%d', (int) $approval?->id, $decision, (int) $approval?->decisions?->count());
            foreach ($recipients as $recipient) {
                app(HierarchicalNotificationService::class)->sendIdempotentNotification($recipient, $notification, $key.'_'.$recipient->id, ['leave_id' => $leave->id, 'decision' => $decision]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
