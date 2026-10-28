<?php

namespace Webkul\TimeOff\Services;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;

/**
 * Withdraw and cancel Time Off. The shared approval engine has no withdraw
 * step, so the transitions live here, each in a transaction on the locked
 * leave row, logged on the leave's chatter and notified:
 *
 *  - withdraw            : the employee (or whoever created the request)
 *                          takes back a pending request at any time. Its
 *                          approval request is closed as "withdrawn" and
 *                          the leave becomes "cancel". The line manager (HR
 *                          when there is none) is told if it was submitted.
 *  - requestCancellation : the employee asks to cancel an APPROVED leave that
 *                          has not started yet (start date after today,
 *                          Asia/Karachi). The leave stays approved; HR and
 *                          the line manager are notified.
 *  - cancel              : HR (hr_approve_leave, in the leave's company)
 *                          cancels an approved leave, with a reason, whether
 *                          or not the employee asked. The employee and line
 *                          manager are notified.
 *  - declineCancellation : HR keeps the leave approved and tells the employee.
 *
 * A cancelled leave no longer counts as taken or pending
 * (LeaveBalanceService), so its days are back in the balance, and attendance
 * ignores it (it reads approved leave, state validate_two, only).
 */
class LeaveCancellationService
{
    public const WITHDRAWN_STATUS = 'withdrawn';

    public function __construct(
        protected ApprovalEngine $approvals,
        protected HierarchicalNotificationService $notifications,
    ) {}

    public function canWithdraw(Leave $leave, User $actor): bool
    {
        return $leave->isPending() && $this->isOwnerOrRequester($leave, $actor);
    }

    public function canRequestCancellation(Leave $leave, User $actor): bool
    {
        return $leave->state === State::VALIDATE_TWO
            && $leave->cancellation_requested_at === null
            && $this->isOwnerOrRequester($leave, $actor)
            && $this->startsAfterToday($leave);
    }

    public function canCancel(Leave $leave, User $actor): bool
    {
        return $leave->state === State::VALIDATE_TWO && $this->isHr($leave, $actor);
    }

    public function canDeclineCancellation(Leave $leave, User $actor): bool
    {
        return $leave->state === State::VALIDATE_TWO
            && $leave->cancellation_requested_at !== null
            && $this->isHr($leave, $actor);
    }

    public function withdraw(Leave $leave, User $actor, ?string $reason = null): Leave
    {
        $leave = DB::transaction(function () use ($leave, $actor, $reason): Leave {
            $locked = $this->lock($leave);
            if (! $this->canWithdraw($locked, $actor)) {
                throw new RuntimeException('Only a pending leave request can be withdrawn, and only by the employee who asked for it.');
            }

            $approval = $locked->approvalRequest;
            $wasSubmitted = $approval && $approval->status === 'pending';
            if ($wasSubmitted) {
                $approval->update(['status' => self::WITHDRAWN_STATUS, 'completed_at' => now()]);
            }

            $locked->update([
                'state'               => State::CANCEL,
                'cancelled_at'        => now(),
                'cancelled_by'        => $actor->id,
                'cancellation_reason' => $this->clean($reason) ?? 'Withdrawn by the employee',
            ]);

            $this->log($locked, $actor, 'withdrew this request', $reason);
            $locked->setAttribute('was_submitted', $wasSubmitted);

            return $locked;
        });

        if ($leave->getAttribute('was_submitted')) {
            $this->notify(
                $this->managerOrHr($leave, $actor),
                FilamentNotification::make()->color('gray')->icon('heroicon-o-arrow-uturn-left')
                    ->title("{$this->employeeName($leave)} withdrew a {$this->typeName($leave)} request")
                    ->body("{$this->employeeName($leave)} withdrew the {$this->typeName($leave)} request{$this->when($leave)}. Nothing is left to approve.".$this->reasonText($reason)),
                $leave,
                'withdrawn',
            );
        }

        return $leave->fresh();
    }

    public function requestCancellation(Leave $leave, User $actor, string $reason): Leave
    {
        $reason = $this->clean($reason) ?? throw new RuntimeException('Give a reason for the cancellation.');

        $leave = DB::transaction(function () use ($leave, $actor, $reason): Leave {
            $locked = $this->lock($leave);
            if (! $this->canRequestCancellation($locked, $actor)) {
                throw new RuntimeException('Only your own approved leave that has not started yet can be cancelled this way.');
            }

            $locked->update([
                'cancellation_requested_at'   => now(),
                'cancellation_requested_by'   => $actor->id,
                'cancellation_request_reason' => $reason,
            ]);

            $this->log($locked, $actor, 'asked HR to cancel this approved leave', $reason);

            return $locked;
        });

        $recipients = $this->hrUsers($leave, $actor)->merge($this->lineManager($leave, $actor))->unique('id');

        $this->notify(
            $recipients,
            FilamentNotification::make()->warning()->icon('heroicon-o-no-symbol')
                ->title("{$this->employeeName($leave)} asks to cancel an approved {$this->typeName($leave)}")
                ->body("{$this->employeeName($leave)} asks to cancel the approved {$this->typeName($leave)}{$this->when($leave)}.".$this->reasonText($reason).' HR can cancel it from Time Off.'),
            $leave,
            'cancellation_requested_'.$leave->cancellation_requested_at?->timestamp,
        );

        return $leave->fresh();
    }

    public function cancel(Leave $leave, User $actor, string $reason): Leave
    {
        $reason = $this->clean($reason) ?? throw new RuntimeException('Give a reason for the cancellation.');

        $leave = DB::transaction(function () use ($leave, $actor, $reason): Leave {
            $locked = $this->lock($leave);
            if (! $this->canCancel($locked, $actor)) {
                throw new RuntimeException('Only HR of the leave company can cancel an approved leave.');
            }

            $locked->update([
                'state'               => State::CANCEL,
                'cancelled_at'        => now(),
                'cancelled_by'        => $actor->id,
                'cancellation_reason' => $reason,
            ]);

            $this->log($locked, $actor, 'cancelled this approved leave; the days are back in the balance', $reason);

            return $locked;
        });

        $recipients = $this->employeeUsers($leave, $actor)->merge($this->lineManager($leave, $actor))->unique('id');

        $this->notify(
            $recipients,
            FilamentNotification::make()->danger()->icon('heroicon-o-no-symbol')
                ->title("{$this->typeName($leave)} of {$this->employeeName($leave)} was cancelled")
                ->body("The approved {$this->typeName($leave)} of {$this->employeeName($leave)}{$this->when($leave)} was cancelled by {$actor->name} (HR). The days are back in the balance.".$this->reasonText($reason)),
            $leave,
            'cancelled',
        );

        return $leave->fresh();
    }

    public function declineCancellation(Leave $leave, User $actor, string $reason): Leave
    {
        $reason = $this->clean($reason) ?? throw new RuntimeException('Give a reason.');

        $leave = DB::transaction(function () use ($leave, $actor, $reason): Leave {
            $locked = $this->lock($leave);
            if (! $this->canDeclineCancellation($locked, $actor)) {
                throw new RuntimeException('There is no cancellation request to decline.');
            }

            $requestedAt = $locked->cancellation_requested_at;
            $locked->update([
                'cancellation_requested_at'   => null,
                'cancellation_requested_by'   => null,
                'cancellation_request_reason' => null,
            ]);

            $this->log($locked, $actor, 'kept this leave approved (cancellation declined)', $reason);
            $locked->setAttribute('declined_request_at', $requestedAt?->timestamp);

            return $locked;
        });

        $this->notify(
            $this->employeeUsers($leave, $actor),
            FilamentNotification::make()->info()->icon('heroicon-o-check-circle')
                ->title("Your {$this->typeName($leave)} stays approved")
                ->body("{$actor->name} (HR) kept your {$this->typeName($leave)}{$this->when($leave)} approved.".$this->reasonText($reason)),
            $leave,
            'cancellation_declined_'.$leave->getAttribute('declined_request_at'),
        );

        return $leave->fresh();
    }

    private function lock(Leave $leave): Leave
    {
        /** @var Leave $locked */
        $locked = Leave::query()->whereKey($leave->getKey())->lockForUpdate()->firstOrFail();

        return $locked->load(['employee.user', 'employee.parent.user', 'user', 'holidayStatus', 'approvalRequest']);
    }

    private function isOwnerOrRequester(Leave $leave, User $actor): bool
    {
        $leave->loadMissing('employee');

        return (int) $leave->employee?->user_id === (int) $actor->id
            || ($leave->creator_id && (int) $leave->creator_id === (int) $actor->id);
    }

    private function isHr(Leave $leave, User $actor): bool
    {
        return $actor->can('hr_approve_leave') && $this->approvals->userCanAccessCompany($actor, (int) $leave->company_id);
    }

    private function startsAfterToday(Leave $leave): bool
    {
        $start = $leave->request_date_from ?? $leave->date_from;

        return $start !== null && Carbon::parse($start)->toDateString() > LeaveBalanceService::today()->toDateString();
    }

    private function log(Leave $leave, User $actor, string $what, ?string $reason): void
    {
        try {
            $leave->addMessage([
                'type'        => 'comment',
                'body'        => e("{$actor->name} {$what}.").($this->clean($reason) ? '<br>Reason: '.e($this->clean($reason)) : ''),
                'company_id'  => $leave->company_id,
                'causer_type' => $actor->getMorphClass(),
                'causer_id'   => $actor->id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param Collection<int, User> $recipients */
    private function notify(Collection $recipients, FilamentNotification $notification, Leave $leave, string $event): void
    {
        if (! config('attendance.notifications.leave_decision', true)) {
            return;
        }

        foreach ($recipients as $recipient) {
            try {
                $this->notifications->sendIdempotentNotification(
                    $recipient,
                    $notification,
                    "leave_{$event}_{$leave->id}_{$recipient->id}",
                    ['leave_id' => $leave->id, 'event' => $event],
                );
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @return Collection<int, User> */
    private function employeeUsers(Leave $leave, User $actor): Collection
    {
        return collect([$leave->employee?->user, $leave->user])
            ->filter(fn (?User $user): bool => $user && $user->is_active && (int) $user->id !== (int) $actor->id)
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> */
    private function lineManager(Leave $leave, User $actor): Collection
    {
        return collect([$leave->employee?->parent?->user])
            ->filter(fn (?User $user): bool => $user && $user->is_active && (int) $user->id !== (int) $actor->id)
            ->values();
    }

    /** @return Collection<int, User> */
    private function hrUsers(Leave $leave, User $actor): Collection
    {
        // The same people who may cancel (isHr): hr_approve_leave in the leave's company.
        return CurrentCompany::scopeUsersOf(User::query()->where('is_active', true), (int) $leave->company_id)
            ->whereKeyNot($actor->id)
            ->get()
            ->filter(fn (User $user): bool => $user->can('hr_approve_leave'))
            ->values();
    }

    /** @return Collection<int, User> */
    private function managerOrHr(Leave $leave, User $actor): Collection
    {
        $manager = $this->lineManager($leave, $actor);

        return $manager->isNotEmpty() ? $manager : $this->hrUsers($leave, $actor);
    }

    private function employeeName(Leave $leave): string
    {
        return (string) ($leave->employee?->name ?? 'An employee');
    }

    private function typeName(Leave $leave): string
    {
        return (string) ($leave->holidayStatus?->name ?? 'leave');
    }

    private function when(Leave $leave): string
    {
        $start = $leave->request_date_from ?? $leave->date_from;
        if (! $start) {
            return '';
        }

        $start = Carbon::parse($start);
        $end = Carbon::parse($leave->request_date_to ?? $leave->date_to ?? $start);

        return $start->isSameDay($end)
            ? " on {$start->format('D, d M Y')}"
            : " from {$start->format('D, d M Y')} to {$end->format('D, d M Y')}";
    }

    private function reasonText(?string $reason): string
    {
        $reason = $this->clean($reason);

        return $reason ? " Reason: {$reason}" : '';
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
