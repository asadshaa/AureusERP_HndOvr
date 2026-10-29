<?php

namespace Webkul\Support\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\ApprovalRequestStatus;
use Webkul\Support\Models\ApprovalReminder;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;

/**
 * Reminders for approvals left waiting (approvals:send-reminders, hourly).
 *
 * For a pending request, counted from when its CURRENT step began waiting
 * (current_step_started_at, reset when the step advances or the request is
 * resubmitted) in calendar hours:
 *
 *  - reminder 1 after "after_hours", then one every "every_hours" (counted
 *    from the previous reminder actually sent), up to "max_reminders", to the
 *    approvers who can still act on the step;
 *  - one interval after the last reminder (after "after_hours" when the
 *    workflow allows no reminders) a single escalation to the company's HR
 *    approvers and, when the step is waiting on a line / department / team
 *    manager, to that manager's own line manager.
 *
 * Settings are per workflow (Approval Workflows screen), empty fields falling
 * back to config('approvals.reminders'): 24 h, every 24 h, 3 reminders.
 * A step waiting longer than max_age_days (14) is no longer chased: no
 * reminder and no escalation (the queue still shows how long it waits).
 * Nothing is sent between quiet_start and quiet_end (20:00-09:00) or on
 * Saturday / Sunday, Asia/Karachi: a reminder due then goes out at the next
 * working day's 09:00 run.
 *
 * Idempotent: each reminder number, and the escalation, of one request, step
 * and submission round is claimed once (support_approval_reminders unique
 * key, under a row lock on the request) and every notification carries its
 * own idempotency key, so an overlapping run or a re-run never sends twice.
 */
final class ApprovalReminderService
{
    /** Hierarchy steps whose approver is a manager with a line manager of their own. */
    private const MANAGER_ROUTES = ['requester_manager', 'department_manager', 'team_manager', 'record_manager'];

    public function __construct(
        private ApprovalEngine $engine,
        private HierarchicalNotificationService $notifications,
    ) {}

    /** @return array{enabled: bool, after_hours: int, every_hours: int, max_reminders: int} */
    public function settingsFor(?ApprovalWorkflow $workflow): array
    {
        return [
            'enabled'       => (bool) config('approvals.reminders.enabled', true) && (bool) ($workflow?->reminders_enabled ?? true),
            'after_hours'   => max(1, (int) ($workflow?->remind_after_hours ?: config('approvals.reminders.after_hours', 24))),
            'every_hours'   => max(1, (int) ($workflow?->remind_every_hours ?: config('approvals.reminders.every_hours', 24))),
            'max_reminders' => max(0, (int) ($workflow?->max_reminders ?? config('approvals.reminders.max_reminders', 3))),
        ];
    }

    /** Days after which a waiting step is no longer chased (config approvals.reminders.max_age_days; 0 = no limit). */
    public function maxAgeDays(): int
    {
        return max(0, (int) config('approvals.reminders.max_age_days', 14));
    }

    /** Whether a step waiting since $since is past the cut-off: no reminder or escalation. */
    public function isTooOld(CarbonInterface $since, ?CarbonInterface $now = null): bool
    {
        $days = $this->maxAgeDays();

        return $days > 0 && CarbonImmutable::instance($since)->addDays($days)->lt($now ?? now());
    }

    public function timezone(): string
    {
        return (string) config('approvals.reminders.timezone', 'Asia/Karachi');
    }

    /** Between quiet_start and quiet_end, or on a weekend day, in the reminder timezone. */
    public function isQuietTime(CarbonInterface $moment): bool
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($this->timezone());

        if (in_array($local->dayOfWeek, $this->weekendDays(), true)) {
            return true;
        }

        $minutes = $local->hour * 60 + $local->minute;
        $start = $this->minutesOf((string) config('approvals.reminders.quiet_start', '20:00'));
        $end = $this->minutesOf((string) config('approvals.reminders.quiet_end', '09:00'));

        return $start > $end
            ? ($minutes >= $start || $minutes < $end)
            : ($minutes >= $start && $minutes < $end);
    }

    /** The first moment at or after $due outside the quiet hours and the weekend. */
    public function sendableFrom(CarbonInterface $due): CarbonImmutable
    {
        $local = CarbonImmutable::instance($due)->setTimezone($this->timezone());
        [$endHour, $endMinute] = $this->clock((string) config('approvals.reminders.quiet_end', '09:00'));
        $end = $endHour * 60 + $endMinute;

        for ($guard = 0; $guard < 14 && $this->isQuietTime($local); $guard++) {
            $minutes = $local->hour * 60 + $local->minute;
            $isWeekend = in_array($local->dayOfWeek, $this->weekendDays(), true);

            $local = (! $isWeekend && $minutes < $end)
                ? $local->setTime($endHour, $endMinute)
                : $local->addDay()->setTime($endHour, $endMinute);
        }

        return $local->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * What is due next for a pending request at its current step: the next
     * reminder or the escalation, when it falls due and when it may be sent.
     *
     * @return array{kind: string, number: int, due_at: CarbonImmutable, send_from: CarbonImmutable}|null
     */
    public function nextFor(ApprovalRequest $request, ?CarbonInterface $now = null): ?array
    {
        if ($request->status !== ApprovalRequestStatus::Pending->value) {
            return null;
        }

        $request->loadMissing(['workflow.steps', 'reminders']);
        $step = $request->currentStep();
        $settings = $this->settingsFor($request->workflow);

        if (! $step || ! $settings['enabled']) {
            return null;
        }

        $sent = $request->currentStepReminders();
        if ($sent->contains('kind', ApprovalReminder::KIND_ESCALATION)) {
            return null;
        }

        $reminders = $sent->where('kind', ApprovalReminder::KIND_REMINDER)->sortBy('reminder_number')->values();
        $since = CarbonImmutable::instance($request->current_step_started_at ?? $request->submitted_at ?? $request->created_at ?? now());

        if ($this->isTooOld($since, $now)) {
            return null;
        }
        $last = $reminders->last();

        $due = $last
            ? CarbonImmutable::instance($last->sent_at)->addHours($settings['every_hours'])
            : $since->addHours($settings['after_hours']);

        $isReminder = $reminders->count() < $settings['max_reminders'];

        return [
            'kind'      => $isReminder ? ApprovalReminder::KIND_REMINDER : ApprovalReminder::KIND_ESCALATION,
            'number'    => $isReminder ? $reminders->count() + 1 : 0,
            'due_at'    => $due,
            'send_from' => $this->sendableFrom($due),
        ];
    }

    /** @return array{checked: int, reminded: int, escalated: int, notified: int, quiet: bool} */
    public function run(?CarbonInterface $now = null, ?int $companyId = null, bool $dryRun = false): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $result = ['checked' => 0, 'reminded' => 0, 'escalated' => 0, 'notified' => 0, 'quiet' => false];

        if (! config('approvals.reminders.enabled', true)) {
            return $result;
        }

        if ($this->isQuietTime($now)) {
            $result['quiet'] = true;

            return $result;
        }

        ApprovalRequest::query()
            ->where('status', ApprovalRequestStatus::Pending->value)
            ->whereNotNull('current_step_sequence')
            ->whereHas('workflow', fn ($workflows) => $workflows->where('reminders_enabled', true))
            ->when($this->maxAgeDays() > 0, fn ($query) => $query->where(
                fn ($recent) => $recent->where('current_step_started_at', '>=', $now->subDays($this->maxAgeDays()))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('current_step_started_at')->where('submitted_at', '>=', $now->subDays($this->maxAgeDays()))),
            ))
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->with(['workflow.steps', 'reminders'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $requests) use (&$result, $now, $dryRun): void {
                foreach ($requests as $request) {
                    $result['checked']++;
                    $next = $this->nextFor($request, $now);

                    if (! $next || $now->lt($next['send_from'])) {
                        continue;
                    }

                    $sent = $dryRun ? 0 : $this->deliver($request, $next, $now);

                    if ($sent === null) {
                        continue;
                    }

                    $result[$next['kind'] === ApprovalReminder::KIND_REMINDER ? 'reminded' : 'escalated']++;
                    $result['notified'] += $sent;
                }
            });

        return $result;
    }

    /**
     * Approvers the current step is still waiting on: those who can act now
     * and have not approved it yet in this round.
     *
     * @return Collection<int, User>
     */
    public function approversToRemind(ApprovalRequest $request, ApprovalStep $step): Collection
    {
        $approvedIds = $request->decisions()
            ->where('step_id', $step->id)
            ->where('submission_round', (int) ($request->submission_round ?: 1))
            ->where('decision', 'approved')
            ->pluck('actor_id')
            ->map(fn ($id): int => (int) $id);

        return $this->engine->resolveAuthorizedApprovers($request, $step)
            ->filter(fn (User $user): bool => $user->is_active
                && ! $approvedIds->contains((int) $user->id)
                && $this->engine->canAct($request, $user))
            ->unique('id')
            ->values();
    }

    /**
     * The company's HR approvers, plus the own line manager of a manager the
     * step is waiting on (never the employee the request is about).
     *
     * @return Collection<int, User>
     */
    public function escalationRecipients(ApprovalRequest $request, ApprovalStep $step): Collection
    {
        $companyId = (int) $request->company_id;
        $subjectUserId = $this->engine->subjectEmployeeUserId($request);
        $recipients = $this->engine->resolveHrApproversForCompany($companyId, $subjectUserId);

        if (in_array($step->hierarchy_route, self::MANAGER_ROUTES, true)) {
            $manager = $this->engine->hierarchyManagerFor($request, $step);
            $manager?->loadMissing('employee.parent.user');
            $managersManager = $manager?->employee?->parent?->user;

            if ($managersManager instanceof User
                && $managersManager->is_active
                && (int) $managersManager->id !== (int) $manager->id
                && (int) $managersManager->id !== (int) $subjectUserId
                && $this->engine->userCanAccessCompany($managersManager, $companyId)) {
                $recipients->push($managersManager);
            }
        }

        return $recipients->unique('id')->values();
    }

    /**
     * Claims the reminder / escalation on the locked request and sends it.
     * Null when another run already claimed it or the request moved on.
     *
     * @param  array{kind: string, number: int, due_at: CarbonImmutable, send_from: CarbonImmutable}  $next
     */
    private function deliver(ApprovalRequest $request, array $next, CarbonImmutable $now): ?int
    {
        try {
            return DB::transaction(function () use ($request, $next, $now): ?int {
                $locked = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->first();

                if (! $locked
                    || $locked->status !== ApprovalRequestStatus::Pending->value
                    || (int) $locked->current_step_sequence !== (int) $request->current_step_sequence
                    || (int) $locked->submission_round !== (int) $request->submission_round) {
                    return null;
                }

                $locked->setRelation('workflow', $request->workflow);
                $step = $locked->currentStep();
                if (! $step) {
                    return null;
                }

                $claimed = ApprovalReminder::query()
                    ->where('request_id', $locked->id)
                    ->where('step_id', $step->id)
                    ->where('submission_round', (int) $locked->submission_round)
                    ->where('kind', $next['kind'])
                    ->where('reminder_number', $next['number'])
                    ->exists();
                if ($claimed) {
                    return null;
                }

                $isReminder = $next['kind'] === ApprovalReminder::KIND_REMINDER;
                $recipients = $isReminder ? $this->approversToRemind($locked, $step) : $this->escalationRecipients($locked, $step);

                ApprovalReminder::query()->create([
                    'request_id'       => $locked->id,
                    'step_id'          => $step->id,
                    'submission_round' => (int) $locked->submission_round,
                    'kind'             => $next['kind'],
                    'reminder_number'  => $next['number'],
                    'recipient_ids'    => $recipients->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'sent_at'          => $now,
                ]);

                $settings = $this->settingsFor($locked->workflow);
                $sent = 0;

                foreach ($recipients as $recipient) {
                    $key = $isReminder
                        ? "approval_reminder_{$locked->id}_step_{$step->id}_r{$locked->submission_round}_n{$next['number']}_{$recipient->id}"
                        : "approval_escalation_{$locked->id}_step_{$step->id}_r{$locked->submission_round}_{$recipient->id}";

                    $delivered = $this->notifications->sendIdempotentNotification(
                        $recipient,
                        $this->notification($locked, $step, $next, $settings, $now),
                        $key,
                        [
                            'event'               => $isReminder ? 'approval_reminder' : 'approval_escalation',
                            'approval_request_id' => $locked->id,
                            'step_id'             => $step->id,
                            'submission_round'    => (int) $locked->submission_round,
                            'reminder_number'     => $next['number'],
                            'company_id'          => $locked->company_id,
                        ],
                    );

                    $sent += $delivered ? 1 : 0;
                }

                return $sent;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array{kind: string, number: int}  $next
     * @param  array{after_hours: int, every_hours: int, max_reminders: int}  $settings
     */
    private function notification(ApprovalRequest $request, ApprovalStep $step, array $next, array $settings, CarbonImmutable $now): FilamentNotification
    {
        $request->loadMissing('subject');
        $label = ApprovalLinks::label($request);
        $subject = $request->subject;
        $employeeName = $subject && method_exists($subject, 'employee') ? $subject->employee?->name : null;
        $what = $employeeName ? "{$label} of {$employeeName}" : $label;
        $hours = (int) $request->waitingHours($now);

        if ($next['kind'] === ApprovalReminder::KIND_REMINDER) {
            $notification = FilamentNotification::make()
                ->warning()
                ->icon('heroicon-o-bell-alert')
                ->title("Reminder: {$what} is waiting for your approval")
                ->body("{$what} has been waiting {$hours}h at step '{$step->name}'. Please approve, reject or return it (reminder {$next['number']} of {$settings['max_reminders']}).");
        } else {
            $notification = FilamentNotification::make()
                ->danger()
                ->icon('heroicon-o-exclamation-triangle')
                ->title("Escalated: {$what} waiting {$hours}h")
                ->body("{$what} is still waiting at step '{$step->name}' after {$settings['max_reminders']} reminder(s). ".$this->engine->describeCurrentApprover($request));
        }

        $url = ApprovalLinks::queue($request);

        return $url
            ? $notification->actions([Action::make('open')->button()->label('Open in Approval Queue')->url($url)])
            : $notification;
    }

    /** @return array<int, int> */
    private function weekendDays(): array
    {
        return array_map('intval', (array) config('approvals.reminders.weekend_days', [6, 0]));
    }

    private function minutesOf(string $clock): int
    {
        [$hour, $minute] = $this->clock($clock);

        return $hour * 60 + $minute;
    }

    /** @return array{0: int, 1: int} */
    private function clock(string $clock): array
    {
        $parts = array_map('intval', explode(':', $clock) + [1 => 0]);

        return [$parts[0], $parts[1]];
    }
}
