<?php

namespace Webkul\Employee\Services\Appraisals;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * In-app notifications of the appraisal steps. The step notifications the
 * performance cycles already had (cycle launched, self review submitted,
 * manager review completed) stay in HierarchicalNotificationService, now
 * with a link; this adds the reviewer / HR launch summary, results shared,
 * acknowledged, a skipped self review and the daily reminders. "No line
 * manager" goes to the company's HR approvers
 * (ApprovalEngine::resolveHrApproversForCompany()). Ratings and comments
 * are never put in a notification.
 */
class AppraisalNotifier
{
    public function __construct(
        protected HierarchicalNotificationService $notifications,
        protected ApprovalEngine $approvals,
        protected AppraisalAccess $access,
    ) {}

    /**
     * Employees: their self review is open. Reviewers: how many reports to
     * review and when. HR: the appraisals without a line manager.
     *
     * @param  Collection<int, PerformanceReview>  $reviews  the reviews created by this launch
     */
    public function cycleLaunched(PerformanceCycle $cycle, Collection $reviews): void
    {
        $reviews->loadMissing(['employee.user', 'reviewer.user']);

        $selfReviewers = $reviews->where('status', AppraisalKinds::StatusSelfReview)->pluck('employee')->filter();
        $this->notifications->notifyPerformanceCycleLaunched($cycle, $selfReviewers, $this->myAppraisalsUrl());

        $due = $this->dueText($cycle);

        foreach ($reviews->groupBy(fn (PerformanceReview $review): int => (int) $this->access->managerUser($review)?->getKey()) as $managerId => $mine) {
            if ($managerId === 0) {
                continue;
            }

            $manager = $this->access->managerUser($mine->first());
            $count = $mine->count();

            $this->sendOnce($manager, Notification::make()
                ->info()
                ->icon('heroicon-o-star')
                ->title("Appraisals: {$count} ".Str::plural('report', $count).' to review')
                ->body("Cycle '{$cycle->name}' has started. {$due}")
                ->actions([$this->button('Team appraisals', $this->teamAppraisalsUrl())]), "perf_cycle_{$cycle->id}_reviewer_{$manager->getKey()}", $cycle);
        }

        $withoutManager = $reviews->reject(fn (PerformanceReview $review): bool => $this->access->hasManager($review));

        if ($withoutManager->isNotEmpty()) {
            $count = $withoutManager->count();

            foreach ($this->approvals->resolveHrApproversForCompany((int) $cycle->company_id) as $hr) {
                $this->sendOnce($hr, Notification::make()
                    ->warning()
                    ->icon('heroicon-o-star')
                    ->title("Appraisals: {$count} without a line manager")
                    ->body("In cycle '{$cycle->name}', {$count} ".Str::plural('employee', $count)." {$this->hasHave($count)} no line manager who can log in. HR completes their manager review. {$due}")
                    ->actions([$this->button('Team appraisals', $this->teamAppraisalsUrl())]), "perf_cycle_{$cycle->id}_hr_fallback_{$hr->getKey()}", $cycle);
            }
        }
    }

    /** The employee submitted the self review: the reviewer (or HR) is told. */
    public function selfReviewSubmitted(PerformanceReview $review): void
    {
        $this->notifications->notifyPerformanceSelfReviewSubmitted($review, $this->appraisalUrl($review));
    }

    /** HR moved the appraisal to the manager review without a self review, or gave it to a new reviewer. */
    public function managerReviewOpened(PerformanceReview $review, string $reason): void
    {
        $review->loadMissing(['employee', 'reviewer.user', 'cycle']);
        $name = (string) $review->employee?->name;

        foreach ($this->managerRecipients($review) as $recipient) {
            $this->sendOnce($recipient, Notification::make()
                ->warning()
                ->icon('heroicon-o-star')
                ->title("Appraisal to review: {$name}")
                ->body("{$reason} Please complete the manager review for cycle '{$review->cycle?->name}'.".($this->access->hasManager($review) ? '' : ' [Routed to HR: no line manager]'))
                ->actions([$this->button('Open', $this->appraisalUrl($review))]), "perf_mgr_open_{$review->getKey()}_{$review->reviewer_id}_{$recipient->getKey()}", $review->cycle);
        }
    }

    /** The manager submitted: the employee is told (without the rating). */
    public function managerReviewCompleted(PerformanceReview $review): void
    {
        $visible = $this->access->resultsVisibleToEmployee($review);

        $this->notifications->notifyPerformanceManagerReviewCompleted(
            $review,
            $this->appraisalUrl($review),
            $visible ? 'Please read it and acknowledge it.' : 'You will see it when HR closes the cycle.',
        );
    }

    /**
     * HR closed the cycle: everyone whose appraisal was reviewed can now read it.
     *
     * @param  Collection<int, PerformanceReview>  $reviews
     */
    public function resultsShared(PerformanceCycle $cycle, Collection $reviews): int
    {
        $sent = 0;
        $reviews->loadMissing('employee.user');

        foreach ($reviews as $review) {
            $user = $review->employee?->user;

            if ($user instanceof User && $user->is_active) {
                $sent += $this->sendOnce($user, Notification::make()
                    ->success()
                    ->icon('heroicon-o-star')
                    ->title('Your appraisal is ready')
                    ->body("Your appraisal for cycle '{$cycle->name}' is ready. Please read it and acknowledge it.")
                    ->actions([$this->button('Open', $this->appraisalUrl($review))]), "perf_results_{$review->getKey()}", $cycle) ? 1 : 0;
            }
        }

        return $sent;
    }

    /** The employee acknowledged: the reviewer (or HR) is told. */
    public function acknowledged(PerformanceReview $review): void
    {
        $review->loadMissing(['employee', 'reviewer.user', 'cycle']);
        $name = (string) $review->employee?->name;

        foreach ($this->managerRecipients($review) as $recipient) {
            $this->sendOnce($recipient, Notification::make()
                ->success()
                ->icon('heroicon-o-check-badge')
                ->title("{$name} acknowledged their appraisal")
                ->body("Cycle '{$review->cycle?->name}'".(filled($review->acknowledgement_comment) ? ', with a comment.' : '.'))
                ->actions([$this->button('Open', $this->appraisalUrl($review))]), "perf_ack_{$review->getKey()}_{$recipient->getKey()}", $review->cycle);
        }
    }

    /**
     * The daily reminders, one notification per person.
     *
     * @param  array<int, array{review: PerformanceReview, step: string, type: string, due: CarbonImmutable}>  $claims
     */
    public function reminders(array $claims, CarbonImmutable $today): int
    {
        $byRecipient = [];

        foreach ($claims as $claim) {
            $recipients = $claim['step'] === 'self'
                ? collect([$claim['review']->employee?->user])->filter(fn ($user): bool => $user instanceof User && $user->is_active)
                : $this->managerRecipients($claim['review']);

            foreach ($recipients as $recipient) {
                $byRecipient[$recipient->getKey()]['user'] = $recipient;
                $byRecipient[$recipient->getKey()]['claims'][] = $claim;
            }
        }

        $sent = 0;

        foreach ($byRecipient as $mine) {
            $list = collect($mine['claims']);
            $count = $list->count();
            $overdue = $list->where('type', 'overdue')->count();

            $title = match (true) {
                $overdue === $count => $count === 1 ? 'Appraisal overdue' : "{$count} appraisals overdue",
                $overdue === 0      => $count === 1 ? 'Appraisal due soon' : "{$count} appraisals due soon",
                default             => "{$count} appraisals waiting for you",
            };

            $lines = $list->take(3)->map(function (array $claim): string {
                $who = $claim['step'] === 'self' ? 'Your self review' : $claim['review']->employee?->name.': manager review';
                $when = $claim['type'] === 'overdue' ? 'overdue since '.$claim['due']->format('j M') : 'due '.$claim['due']->format('D j M');

                return "{$who} ({$claim['review']->cycle?->name}), {$when}";
            })->implode('; ');

            $only = $count === 1 ? $list->first()['review'] : null;
            $url = $only ? $this->appraisalUrl($only) : ($list->every(fn (array $claim): bool => $claim['step'] === 'self') ? $this->myAppraisalsUrl() : $this->teamAppraisalsUrl());

            $this->send($mine['user'], Notification::make()
                ->icon($overdue > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-clock')
                ->color($overdue > 0 ? 'danger' : 'warning')
                ->title($title)
                ->body($lines.($count > 3 ? '; and '.($count - 3).' more.' : '.'))
                ->viewData(['appraisal_reminder' => $today->toDateString()])
                ->actions([$this->button('Open', $url)]));
            $sent++;
        }

        return $sent;
    }

    /**
     * Who does the manager review: the reviewer, or the company's HR approvers when there is none.
     *
     * @return Collection<int, User>
     */
    public function managerRecipients(PerformanceReview $review): Collection
    {
        $manager = $this->access->managerUser($review);

        if ($manager instanceof User) {
            return collect([$manager]);
        }

        return $this->approvals->resolveHrApproversForCompany((int) $review->company_id, $review->employee?->user_id ? (int) $review->employee->user_id : null);
    }

    public function appraisalUrl(PerformanceReview $review): ?string
    {
        return Route::has('filament.admin.resources.performance-reviews.view')
            ? route('filament.admin.resources.performance-reviews.view', ['record' => $review->getKey()])
            : null;
    }

    public function myAppraisalsUrl(): ?string
    {
        return Route::has('filament.admin.pages.my-appraisals') ? route('filament.admin.pages.my-appraisals') : null;
    }

    public function teamAppraisalsUrl(): ?string
    {
        return Route::has('filament.admin.pages.team-appraisals') ? route('filament.admin.pages.team-appraisals') : null;
    }

    protected function dueText(PerformanceCycle $cycle): string
    {
        return collect([
            $cycle->self_review_due_on ? 'Self reviews due '.$cycle->self_review_due_on->format('D j M') : null,
            $cycle->manager_review_due_on ? 'manager reviews due '.$cycle->manager_review_due_on->format('D j M') : null,
        ])->filter()->implode(', ').($cycle->self_review_due_on || $cycle->manager_review_due_on ? '.' : '');
    }

    protected function hasHave(int $count): string
    {
        return $count === 1 ? 'has' : 'have';
    }

    protected function button(string $label, ?string $url): Action
    {
        return Action::make(Str::slug($label, '_'))->button()->label($label)->url($url)->visible($url !== null);
    }

    /** One notification per key and person (HierarchicalNotificationService idempotency). */
    protected function sendOnce(User $user, Notification $notification, string $key, ?PerformanceCycle $cycle): bool
    {
        try {
            return $this->notifications->sendIdempotentNotification($user, $notification, $key, [
                'event'      => 'appraisal',
                'cycle_id'   => $cycle?->getKey(),
                'company_id' => $cycle?->company_id,
            ]);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function send(User $user, Notification $notification): void
    {
        try {
            $user->notifyNow($notification->toDatabase());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
