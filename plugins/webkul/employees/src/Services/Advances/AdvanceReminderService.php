<?php

namespace Webkul\Employee\Services\Advances;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Support\AdvanceKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * hr:advance-reminders (daily): tells an employee that money advanced to them
 * still has to be settled, two days before the settle-by date and then every
 * seven days once it is overdue (the line manager is told about overdue ones
 * too). In-app notifications only, never email. Each notice is keyed per
 * advance and day, so a re-run or an overlap never tells anyone twice.
 */
class AdvanceReminderService
{
    /** Days before the settle-by date for the heads-up. */
    public const HeadsUpDays = 2;

    /** Days between reminders once an advance is overdue. */
    public const OverdueEveryDays = 7;

    public function __construct(
        protected AdvanceService $advances,
        protected HierarchicalNotificationService $notifications,
    ) {}

    /** @return array{advances: int, notified: int} */
    public function send(?int $companyId = null, ?string $date = null): array
    {
        $today = CarbonImmutable::parse($date ?? AdvanceKinds::today(), AdvanceKinds::timezone())->startOfDay();

        $candidates = $this->advances->withBalances(EmployeeRequest::query())
            ->where('status', 'approved')
            ->whereNotNull('accounting_move_id')
            ->whereHas('requestType', fn (Builder $types) => $types->where('category', AdvanceKinds::AdvanceCategory))
            ->whereHas('company', fn (Builder $companies) => $companies->where('is_active', true))
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->with(['employee.user', 'employee.parent.user', 'requestType'])
            ->orderBy('id')
            ->get();

        $due = 0;
        $notified = 0;

        foreach ($candidates as $advance) {
            $summary = $this->advances->summary($advance);

            if ((float) $summary['outstanding'] <= 0 || blank($summary['settle_by'])) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse($summary['settle_by'], AdvanceKinds::timezone())->startOfDay(), false);
            $overdueDays = -$daysLeft;
            $stage = match (true) {
                $daysLeft === self::HeadsUpDays                                                => 'soon',
                $overdueDays >= 1 && ($overdueDays - 1) % self::OverdueEveryDays === 0         => 'overdue',
                default                                                                        => null,
            };

            if ($stage === null) {
                continue;
            }

            $due++;
            $notified += $this->notify($advance, $summary, $stage, $overdueDays, $today);
        }

        return ['advances' => $due, 'notified' => $notified];
    }

    /** @param  array<string, mixed>  $summary */
    private function notify(EmployeeRequest $advance, array $summary, string $stage, int $overdueDays, CarbonImmutable $today): int
    {
        $employee = $advance->employee;
        $amount = number_format((float) $summary['outstanding'], 2);
        $reference = $advance->reference ?: 'advance #'.$advance->id;
        $settleBy = CarbonImmutable::parse($summary['settle_by'])->format('j M Y');
        $key = 'advance_settle_'.$stage.'_'.$advance->id.'_'.$today->toDateString();
        $url = $advance->approvalSubjectUrl();

        $recipients = [];
        if ($employee?->user) {
            $recipients[] = [
                $employee->user,
                $stage === 'soon'
                    ? "Settle your advance by {$settleBy}"
                    : "Your advance is {$overdueDays} ".($overdueDays === 1 ? 'day' : 'days').' overdue to settle',
                $stage === 'soon'
                    ? "{$amount} of {$reference} is still unsettled. Open it and press Settle advance with your receipts, or hand back what you did not spend."
                    : "{$amount} of {$reference} should have been settled by {$settleBy}. Settle it with your receipts now, or hand back the cash. You cannot ask for a new advance until then.",
            ];
        }

        $manager = $employee?->parent?->user;
        if ($stage === 'overdue' && $manager && (int) $manager->id !== (int) $employee?->user?->id && (int) $manager->default_company_id !== 0) {
            $recipients[] = [
                $manager,
                ($employee->name ?? 'A team member').' has an overdue advance',
                "{$amount} of {$reference} was due to be settled by {$settleBy}. Please remind {$employee->name} to settle it.",
            ];
        }

        $sent = 0;
        foreach ($recipients as [$user, $title, $body]) {
            /** @var User $user */
            $notification = Notification::make()
                ->title($title)
                ->body($body)
                ->icon('heroicon-o-banknotes')
                ->color($stage === 'overdue' ? 'danger' : 'warning')
                ->viewData(['employee_request' => $advance->getKey()]);

            $sent += $this->notifications->sendIdempotentNotification(
                $user,
                $url ? $notification->actions([Action::make('open')->label('Open')->url($url)->markAsRead()]) : $notification,
                $key,
                ['event' => 'advance_settlement_reminder', 'stage' => $stage, 'employee_request_id' => $advance->getKey()],
            ) ? 1 : 0;
        }

        return $sent;
    }
}
