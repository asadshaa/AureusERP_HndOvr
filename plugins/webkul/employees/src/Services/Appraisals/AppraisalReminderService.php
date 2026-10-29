<?php

namespace Webkul\Employee\Services\Appraisals;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Models\PerformanceReviewReminder;
use Webkul\Employee\Support\AppraisalKinds;

/**
 * hr:appraisal-reminders (daily): an open self review is reminded to the
 * employee, an open manager review to the reviewer (or the company's HR
 * when there is no line manager): once a few days before the deadline
 * (appraisals.reminder_days_before), on the first day after it, and again
 * every appraisals.overdue_repeat_days days while overdue. Each reminder is
 * claimed by inserting its row (unique review + kind, the kind naming the
 * deadline) before anyone is told, so a re-run or an overlap never reminds
 * twice and a moved deadline arms fresh reminders.
 */
class AppraisalReminderService
{
    public function __construct(
        protected AppraisalNotifier $notifier,
    ) {}

    /** @return array{reviews: int, notified: int, skipped: int} */
    public function send(?int $companyId = null, ?string $date = null): array
    {
        $today = $date !== null
            ? CarbonImmutable::parse($date, AppraisalKinds::timezone())->startOfDay()
            : AppraisalKinds::today();
        $daysBefore = max(0, (int) config('appraisals.reminder_days_before', 3));
        $repeatDays = max(0, (int) config('appraisals.overdue_repeat_days', 7));

        $candidates = PerformanceReview::query()
            ->whereIn('status', [AppraisalKinds::StatusSelfReview, AppraisalKinds::StatusManagerReview])
            ->whereHas('cycle', fn (Builder $cycles) => $cycles->where('status', AppraisalKinds::CycleActive))
            ->whereHas('company', fn (Builder $companies) => $companies->where('is_active', true))
            ->whereHas('employee')
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->with(['cycle', 'employee.user', 'reviewer.user'])
            ->orderBy('id')
            ->get();

        $claims = [];
        $due = 0;

        foreach ($candidates as $review) {
            $step = $review->status === AppraisalKinds::StatusSelfReview ? 'self' : 'manager';
            $deadline = $step === 'self' ? $review->cycle?->self_review_due_on : $review->cycle?->manager_review_due_on;

            if ($deadline === null) {
                continue;
            }

            $deadline = CarbonImmutable::parse($deadline->toDateString(), AppraisalKinds::timezone())->startOfDay();
            $daysLeft = (int) round($today->diffInDays($deadline, false));

            if ($daysLeft >= 0 && $daysLeft <= $daysBefore) {
                $type = 'due_soon';
                $kind = "{$step}_due_soon:{$deadline->toDateString()}";
            } elseif ($daysLeft < 0) {
                $round = $repeatDays > 0 ? intdiv(-$daysLeft - 1, $repeatDays) + 1 : 1;
                $type = 'overdue';
                $kind = "{$step}_overdue:{$deadline->toDateString()}:{$round}";
            } else {
                continue;
            }

            $due++;

            $claimed = PerformanceReviewReminder::query()->insertOrIgnore([
                'company_id' => $review->company_id,
                'review_id'  => $review->getKey(),
                'kind'       => $kind,
                'sent_on'    => $today->toDateString(),
                'created_at' => now(),
            ]);

            if ($claimed === 1) {
                $claims[] = ['review' => $review, 'step' => $step, 'type' => $type, 'due' => $deadline];
            }
        }

        return [
            'reviews'  => count($claims),
            'notified' => $claims === [] ? 0 : $this->notifier->reminders($claims, $today),
            'skipped'  => $due - count($claims),
        ];
    }
}
