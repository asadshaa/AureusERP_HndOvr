<?php

namespace Database\Seeders\Demo;

use App\Services\Demo\DemoRecorder;
use App\Services\Demo\DemoSandbox;
use Closure;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;
use Webkul\Employee\Models\AppraisalTemplate;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Services\Appraisals\AppraisalScoring;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One running appraisal cycle per demo company (HR Parity 3.2), played
 * through the real services: HR launched it 12 days ago, most staff have
 * submitted their self review, line managers have reviewed about half of
 * those, and HR calibrated one. Only demo logins act (a manager who is a
 * real user is left for later). Created once per company (demo key), every
 * row recorded by DemoRecorder so demo:purge removes it.
 */
class DemoAppraisals
{
    public function __construct(
        protected DemoRecorder $recorder,
        protected PerformanceService $performance,
        protected AppraisalAccess $access,
    ) {}

    /**
     * @param  Closure(Carbon, ?User, callable): mixed  $at  runs a callback as a user at a moment (DemoActivitySeeder::at())
     * @return array{created: bool, self: int, manager: int, warnings: array<int, string>}
     */
    public function seed(Company $company, User $hr, Carbon $now, Closure $at): array
    {
        $key = "appraisal-cycle:{$company->id}";
        $result = ['created' => false, 'self' => 0, 'manager' => 0, 'warnings' => []];

        $now = $now->copy()->timezone(AppraisalKinds::timezone());

        if ($existing = $this->recorder->find($key)) {
            $cycle = PerformanceCycle::query()->find($existing->record_id);

            if ($cycle instanceof PerformanceCycle) {
                $this->calibrateOne($cycle, $hr, $now, $at, $result);
            }

            return $result;
        }

        $template = AppraisalTemplate::preferredFor((int) $company->id);
        $launched = $this->weekday($now->copy()->subDays(12))->setTime(10, 15);
        $year = (int) $now->year;

        $cycle = $at($launched->copy()->subHour(), $hr, fn (): PerformanceCycle => $this->recorder->transaction(fn (): PerformanceCycle => PerformanceCycle::query()->create([
            'company_id'            => $company->id,
            'creator_id'            => $hr->id,
            'name'                  => "Annual appraisal {$year}",
            'starts_on'             => "{$year}-01-01",
            'ends_on'               => "{$year}-12-31",
            'status'                => AppraisalKinds::CycleDraft,
            'template_id'           => $template?->getKey(),
            'results_visibility'    => AppraisalKinds::VisibleAfterClose,
            'participant_scope'     => AppraisalKinds::ParticipantsAll,
            'self_review_due_on'    => $launched->copy()->addDays(14)->toDateString(),
            'manager_review_due_on' => $launched->copy()->addDays(24)->toDateString(),
        ])));
        $this->recorder->mark($key, $cycle);

        $at($launched, $hr, fn () => $this->recorder->transaction(fn () => $this->performance->launch($cycle, $hr)));
        $result['created'] = true;

        $cycle->refresh();
        $items = array_keys(AppraisalScoring::items($cycle));
        $reviews = $cycle->reviews()->with(['employee.user', 'reviewer.user'])->orderBy('id')->get();

        foreach ($reviews as $review) {
            $user = $review->employee?->user;
            $r = new Randomizer(new Mt19937(crc32("appraisal|{$company->id}|{$review->employee_id}")));

            // About 70 % of the staff with a demo login have sent their self review.
            if ($review->status !== AppraisalKinds::StatusSelfReview || ! $user instanceof User
                || ! DemoSandbox::isDemoEmail($user->email) || $r->getInt(1, 10) > 7) {
                continue;
            }

            $profile = $r->getInt(2, 4);
            $submitted = $this->weekday($launched->copy()->addDays($r->getInt(1, 9)))->setTime($r->getInt(9, 17), $r->getInt(0, 59));

            try {
                $at($submitted, $user, fn () => $this->recorder->transaction(fn () => $this->performance->saveSelfReview(
                    $review->fresh(),
                    $user,
                    $this->answers($items, $profile + 1, $r, $this->selfComments()) + ['comments' => $this->pick($r, $this->selfOverall())],
                    true,
                )));
                $result['self']++;
            } catch (Throwable $e) {
                $result['warnings'][] = "{$company->name}: self review of {$review->employee?->name} skipped ({$e->getMessage()})";

                continue;
            }

            // The line manager reviewed about half of them, one to four days later.
            $review = $review->fresh(['cycle', 'employee', 'reviewer.user']);
            $manager = $this->access->managerUser($review);
            $reviewedAt = $this->weekday($submitted->copy()->addDays($r->getInt(1, 4)))->setTime($r->getInt(10, 18), $r->getInt(0, 59));

            if (! $manager instanceof User || ! DemoSandbox::isDemoEmail($manager->email) || $r->getInt(1, 10) > 5
                || $reviewedAt->greaterThan($now->copy()->subMinutes(30))) {
                continue;
            }

            try {
                $at($reviewedAt, $manager, fn () => $this->recorder->transaction(fn () => $this->performance->saveManagerReview(
                    $review,
                    $manager,
                    $this->answers($items, $profile, $r, $this->managerComments()) + [
                        'comments'         => $this->pick($r, $this->managerOverall()),
                        'improvement_plan' => $profile <= 2 ? 'Weekly check-ins with the line manager; shadow a senior colleague for a month.' : null,
                        'goals'            => [
                            ['title' => $this->pick($r, $this->goalTitles()), 'due_date' => ($year + 1).'-06-30', 'weight' => 60, 'description' => 'Agreed at the appraisal meeting.'],
                            ['title' => 'Complete one training course relevant to the role', 'due_date' => ($year + 1).'-12-31', 'weight' => 40],
                        ],
                    ],
                    true,
                )));
                $result['manager']++;
            } catch (Throwable $e) {
                $result['warnings'][] = "{$company->name}: manager review of {$review->employee?->name} skipped ({$e->getMessage()})";
            }
        }

        $this->calibrateOne($cycle, $hr, $now, $at, $result);

        return $result;
    }

    /**
     * HR calibrated one reviewed appraisal (never their own) the day after
     * the manager review; nothing when one is calibrated already.
     *
     * @param  array{warnings: array<int, string>}  $result
     */
    protected function calibrateOne(PerformanceCycle $cycle, User $hr, Carbon $now, Closure $at, array &$result): void
    {
        if ($cycle->reviews()->whereNotNull('calibrated_at')->exists()) {
            return;
        }

        $review = $cycle->reviews()
            ->with(['cycle', 'employee', 'reviewer.user'])
            ->where('status', AppraisalKinds::StatusCompleted)
            ->orderBy('completed_at')
            ->get()
            ->first(fn ($review): bool => $this->access->canCalibrate($hr, $review));

        if ($review === null) {
            return;
        }

        $calibratedAt = $this->weekday(Carbon::parse($review->completed_at)->timezone(AppraisalKinds::timezone())->addDay())->setTime(11, 30);
        $calibratedAt = $calibratedAt->min($now->copy()->subMinutes(30));

        try {
            $at($calibratedAt, $hr, fn () => $this->recorder->transaction(fn () => $this->performance->calibrate(
                $review,
                $hr,
                min(5.0, round((float) $review->manager_rating + 0.3, 2)),
                'Calibrated with the department heads: delivered beyond the KPIs on the year-end peak.',
            )));
        } catch (Throwable $e) {
            $result['warnings'][] = "{$cycle->company?->name}: calibration skipped ({$e->getMessage()})";
        }
    }

    /**
     * Ratings around a profile (2-5) for every item, a comment on some.
     *
     * @param  array<int, string>  $items
     * @param  array<int, string>  $comments
     * @return array{items: array<string, array{rating: int, comment: ?string}>}
     */
    protected function answers(array $items, int $profile, Randomizer $r, array $comments): array
    {
        $answers = [];

        foreach ($items as $key) {
            $answers[$key] = [
                'rating'  => max(1, min(5, $profile + $r->getInt(-1, 1))),
                'comment' => $r->getInt(1, 10) <= 3 ? $this->pick($r, $comments) : null,
            ];
        }

        return ['items' => $answers];
    }

    protected function weekday(Carbon $day): Carbon
    {
        while ($day->isWeekend()) {
            $day->addDay();
        }

        return $day;
    }

    /** @param  array<int, string>  $items */
    protected function pick(Randomizer $r, array $items): string
    {
        return $items[$r->getInt(0, count($items) - 1)];
    }

    /** @return array<int, string> */
    protected function selfComments(): array
    {
        return [
            'Handled the extra volume during the peak without delays.',
            'Took on the new port documentation process.',
            'Need more training on the system to be faster.',
            'Covered for the team during leave periods.',
            'Some deadlines slipped in Q2 because of the system change.',
        ];
    }

    /** @return array<int, string> */
    protected function selfOverall(): array
    {
        return [
            'A busy year; I am proud of how the team handled the peak season.',
            'I met most of my targets and want to take on more responsibility next year.',
            'I would like more training on reporting and planning tools.',
            'Good year overall; communication with other departments can improve.',
        ];
    }

    /** @return array<int, string> */
    protected function managerComments(): array
    {
        return [
            'Consistent and reliable.',
            'Good customer feedback this year.',
            'Needs to escalate issues earlier.',
            'Clear improvement since the mid-year review.',
            'Punctuality has been an issue on some weeks.',
        ];
    }

    /** @return array<int, string> */
    protected function managerOverall(): array
    {
        return [
            'Solid contributor; ready for more ownership next year.',
            'Met expectations; focus on planning and follow-through.',
            'Strong year, a dependable member of the team.',
            'Good effort; keep working on accuracy and timeliness.',
        ];
    }

    /** @return array<int, string> */
    protected function goalTitles(): array
    {
        return [
            'Cut documentation errors on shipments by half',
            'Take ownership of the weekly operations report',
            'Train two new joiners on the booking process',
            'Bring average turnaround time under 24 hours',
        ];
    }
}
