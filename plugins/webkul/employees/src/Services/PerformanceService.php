<?php

namespace Webkul\Employee\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Employee\Models\AppraisalTemplate;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Services\Appraisals\AppraisalNotifier;
use Webkul\Employee\Services\Appraisals\AppraisalScoring;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;

/**
 * Performance cycles and their appraisal forms (HR Parity 3.2):
 *
 * HR launches a cycle (template copied, participants: all active employees
 * or selected departments) -> the employee completes the self review ->
 * the line manager (the employee's manager at launch; HR when there is no
 * manager who can log in) completes the manager review, seeing the self
 * review -> HR may calibrate the final rating with a reason -> HR closes
 * the cycle (or the cycle shares results straight away) -> the employee
 * acknowledges, optionally with a comment. The final score is the weighted
 * average of the item ratings; its band comes from the template.
 *
 * Every step re-checks AppraisalAccess on the locked row, so a page, a
 * bulk action or a stale form can never skip a rule.
 */
class PerformanceService
{
    public function __construct(
        protected AppraisalAccess $access,
        protected AppraisalNotifier $notifier,
    ) {}

    /** @return Collection<int, PerformanceReview> */
    public function launch(PerformanceCycle $cycle, User $actor): Collection
    {
        if ((int) $actor->default_company_id !== (int) $cycle->company_id
            && ! $actor->allowedCompanies()->whereKey($cycle->company_id)->exists()) {
            throw new RuntimeException('The user cannot launch a performance cycle for this company.');
        }

        if ($cycle->status === AppraisalKinds::CycleClosed) {
            throw new RuntimeException('This performance cycle is closed.');
        }

        $created = new EloquentCollection;

        $reviews = DB::transaction(function () use ($cycle, $actor, $created): Collection {
            $cycle = PerformanceCycle::query()->lockForUpdate()->findOrFail($cycle->getKey());

            if (! $cycle->template_snapshot) {
                $template = $cycle->template_id
                    ? AppraisalTemplate::query()->where('company_id', $cycle->company_id)->find($cycle->template_id)
                    : AppraisalTemplate::preferredFor((int) $cycle->company_id);

                if ($template instanceof AppraisalTemplate) {
                    $cycle->forceFill(['template_id' => $template->getKey(), 'template_snapshot' => $template->snapshot()]);
                }
            }

            $reviews = $this->participants($cycle)->map(function (Employee $employee) use ($cycle, $created): PerformanceReview {
                $hasLogin = $employee->user?->is_active === true;
                $reviewerId = $employee->parent_id && (int) $employee->parent_id !== (int) $employee->getKey() && $employee->parent
                    ? (int) $employee->parent_id
                    : null;

                $review = PerformanceReview::query()->firstOrCreate(
                    ['cycle_id' => $cycle->id, 'employee_id' => $employee->id],
                    [
                        'company_id'      => $cycle->company_id,
                        'reviewer_id'     => $reviewerId,
                        'status'          => $hasLogin ? AppraisalKinds::StatusSelfReview : AppraisalKinds::StatusManagerReview,
                        'self_skipped_at' => $hasLogin ? null : now(),
                    ],
                );

                if ($review->wasRecentlyCreated) {
                    $created->push($review->setRelation('employee', $employee)->setRelation('cycle', $cycle));
                }

                return $review;
            });

            $cycle->forceFill([
                'status'      => AppraisalKinds::CycleActive,
                'launched_at' => $cycle->launched_at ?? now(),
                'launched_by' => $cycle->launched_by ?? $actor->getKey(),
            ])->save();

            DB::afterCommit(function () use ($cycle, $created): void {
                if ($created->isNotEmpty()) {
                    $this->notifier->cycleLaunched($cycle, $created);
                }
            });

            return $reviews;
        });

        $cycle->refresh();

        return $reviews;
    }

    /**
     * Who takes part: the company's active employees, or those of the selected departments.
     *
     * @return Collection<int, Employee>
     */
    public function participants(PerformanceCycle $cycle): Collection
    {
        return Employee::query()
            ->where('company_id', $cycle->company_id)
            ->where('is_active', true)
            ->when($cycle->participant_scope === AppraisalKinds::ParticipantsDepartments,
                fn ($query) => $query->whereIn('department_id', array_map('intval', (array) $cycle->department_ids) ?: [0]))
            ->with(['user', 'parent'])
            ->orderBy('id')
            ->get();
    }

    /** Overall-rating self review (cycles without a form, and the original API). */
    public function submitSelfReview(PerformanceReview $review, Employee $employee, float $rating, ?string $comments = null): PerformanceReview
    {
        if ((int) $review->employee_id !== (int) $employee->id || $review->status !== AppraisalKinds::StatusSelfReview || $review->cycle?->isClosed()) {
            throw new RuntimeException('This performance review is not available for employee self-review.');
        }
        $review->update([
            'self_rating'  => $rating,
            'self_comments'=> $comments,
            'status'       => AppraisalKinds::StatusManagerReview,
            'submitted_at' => now(),
        ]);

        $this->notifier->selfReviewSubmitted($review);

        return $review->fresh();
    }

    /**
     * Overall-rating manager review (cycles without a form, and the original API).
     *
     * @param  array{competency_ratings?: ?array, improvement_plan?: ?string, promotion_recommendation?: ?string}  $extra
     */
    public function completeManagerReview(PerformanceReview $review, Employee $reviewer, float $rating, ?string $comments = null, array $extra = []): PerformanceReview
    {
        if ((int) $review->reviewer_id !== (int) $reviewer->id || $review->status !== AppraisalKinds::StatusManagerReview || $review->cycle?->isClosed()) {
            throw new RuntimeException('This employee is not the assigned manager reviewer.');
        }
        $band = AppraisalScoring::band($rating, AppraisalScoring::bands($review->cycle));
        $updates = [
            'manager_rating'      => $rating,
            'manager_comments'    => $comments,
            'final_rating'        => $rating,
            'final_band'          => $band['label'] ?? null,
            'manager_reviewed_by' => $reviewer->user_id,
            'status'              => AppraisalKinds::StatusCompleted,
            'completed_at'        => now(),
        ];
        foreach (['competency_ratings', 'improvement_plan', 'promotion_recommendation'] as $field) {
            if (array_key_exists($field, $extra)) {
                $updates[$field] = $extra[$field];
            }
        }
        $review->update($updates);

        $this->notifier->managerReviewCompleted($review);

        return $review->fresh();
    }

    /**
     * The employee's self review on the appraisal form. A draft keeps what
     * is filled in; submitting needs every item rated and opens the manager
     * review.
     *
     * @param  array{items?: array<string, array{rating?: mixed, comment?: ?string}>, comments?: ?string}  $data
     */
    public function saveSelfReview(PerformanceReview $review, User $actor, array $data, bool $submit): PerformanceReview
    {
        $review = DB::transaction(function () use ($review, $actor, $data, $submit): PerformanceReview {
            $review = $this->locked($review);

            if (! $this->access->canEditSelfReview($actor, $review)) {
                throw new RuntimeException('This self review is not open for you.');
            }

            $items = AppraisalScoring::items($review->cycle);
            $answers = $this->cleanAnswers($items, $data);

            if ($submit) {
                $this->assertComplete($items, $answers);
            }

            $review->forceFill([
                'self_answers'  => $answers,
                'self_comments' => $this->text($data['comments'] ?? null),
                'self_rating'   => $submit ? AppraisalScoring::weightedScore($items, AppraisalScoring::ratingsOf($answers)) : null,
            ] + ($submit ? ['status' => AppraisalKinds::StatusManagerReview, 'submitted_at' => now()] : []))->save();

            return $review;
        });

        if ($submit) {
            $this->notifier->selfReviewSubmitted($review);
        }

        return $review->fresh();
    }

    /**
     * The manager review on the appraisal form, by the reviewer or, when
     * there is no line manager, by HR. Submitting needs every item rated,
     * scores the appraisal and tells the employee.
     *
     * @param  array{items?: array<string, array{rating?: mixed, comment?: ?string}>, comments?: ?string, improvement_plan?: ?string, promotion_recommendation?: ?string, goals?: array<int, array{title?: ?string, description?: ?string, due_date?: ?string, weight?: mixed}>}  $data
     */
    public function saveManagerReview(PerformanceReview $review, User $actor, array $data, bool $submit): PerformanceReview
    {
        $review = DB::transaction(function () use ($review, $actor, $data, $submit): PerformanceReview {
            $review = $this->locked($review);

            if (! $this->access->canEditManagerReview($actor, $review)) {
                throw new RuntimeException('This manager review is not open for you.');
            }

            $items = AppraisalScoring::items($review->cycle);
            $answers = $this->cleanAnswers($items, $data);

            if ($submit) {
                $this->assertComplete($items, $answers);
            }

            $updates = [
                'manager_answers'          => $answers,
                'manager_comments'         => $this->text($data['comments'] ?? null),
                'improvement_plan'         => $this->text($data['improvement_plan'] ?? null),
                'promotion_recommendation' => $this->text($data['promotion_recommendation'] ?? null),
            ];

            if ($submit) {
                $score = AppraisalScoring::weightedScore($items, AppraisalScoring::ratingsOf($answers));
                $updates += [
                    'manager_rating'      => $score,
                    'final_rating'        => $score,
                    'final_band'          => AppraisalScoring::band($score, AppraisalScoring::bands($review->cycle))['label'] ?? null,
                    'manager_reviewed_by' => $actor->getKey(),
                    'status'              => AppraisalKinds::StatusCompleted,
                    'completed_at'        => now(),
                ];
            }

            $review->forceFill($updates)->save();

            if (AppraisalScoring::includesGoals($review->cycle) && array_key_exists('goals', $data)) {
                $this->replaceNextPeriodGoals($review, (array) $data['goals']);
            }

            return $review;
        });

        if ($submit) {
            $this->notifier->managerReviewCompleted($review);
        }

        return $review->fresh();
    }

    /** HR sets the final rating (1-5) with a reason; the manager's score is kept. */
    public function calibrate(PerformanceReview $review, User $actor, float $rating, string $reason): PerformanceReview
    {
        $reason = trim($reason);

        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('The calibrated rating must be between 1 and 5.');
        }

        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Give a reason for the calibration.');
        }

        return DB::transaction(function () use ($review, $actor, $rating, $reason): PerformanceReview {
            $review = $this->locked($review);

            if (! $this->access->canCalibrate($actor, $review)) {
                throw new RuntimeException('This appraisal cannot be calibrated now.');
            }

            $rating = round($rating, 2);

            $review->forceFill([
                'calibrated_rating'  => $rating,
                'calibration_reason' => Str::limit($reason, 2000, ''),
                'calibrated_by'      => $actor->getKey(),
                'calibrated_at'      => now(),
                'final_rating'       => $rating,
                'final_band'         => AppraisalScoring::band($rating, AppraisalScoring::bands($review->cycle))['label'] ?? null,
            ])->save();

            return $review->fresh();
        });
    }

    /** The employee confirms they have read the result, optionally with a comment. */
    public function acknowledge(PerformanceReview $review, User $actor, ?string $comment = null): PerformanceReview
    {
        $review = DB::transaction(function () use ($review, $actor, $comment): PerformanceReview {
            $review = $this->locked($review);

            if (! $this->access->canAcknowledge($actor, $review)) {
                throw new RuntimeException('This appraisal cannot be acknowledged now.');
            }

            $review->forceFill([
                'status'                  => AppraisalKinds::StatusAcknowledged,
                'acknowledged_at'         => now(),
                'acknowledgement_comment' => $this->text($comment),
            ])->save();

            return $review;
        });

        $this->notifier->acknowledged($review);

        return $review->fresh();
    }

    /** HR moves an appraisal to the manager review without a self review. */
    public function skipSelfReview(PerformanceReview $review, User $actor): PerformanceReview
    {
        $review = DB::transaction(function () use ($review, $actor): PerformanceReview {
            $review = $this->locked($review);

            if ($review->status !== AppraisalKinds::StatusSelfReview || ! $this->access->canManageReview($actor, $review)) {
                throw new RuntimeException('The self review of this appraisal cannot be skipped.');
            }

            $review->forceFill(['status' => AppraisalKinds::StatusManagerReview, 'self_skipped_at' => now()])->save();

            return $review;
        });

        $this->notifier->managerReviewOpened($review, 'HR moved this appraisal on without a self review.');

        return $review->fresh();
    }

    /** HR gives the manager review to another employee of the company, or to HR (null). */
    public function reassignReviewer(PerformanceReview $review, User $actor, ?int $reviewerEmployeeId): PerformanceReview
    {
        $review = DB::transaction(function () use ($review, $actor, $reviewerEmployeeId): PerformanceReview {
            $review = $this->locked($review);

            if (! $this->access->canManageReview($actor, $review)) {
                throw new RuntimeException('The reviewer of this appraisal cannot be changed now.');
            }

            if ($reviewerEmployeeId !== null) {
                $reviewer = Employee::query()->where('company_id', $review->company_id)->where('is_active', true)->find($reviewerEmployeeId);

                if (! $reviewer instanceof Employee || (int) $reviewer->getKey() === (int) $review->employee_id) {
                    throw new RuntimeException('Choose another active employee of this company as the reviewer.');
                }
            }

            $review->forceFill(['reviewer_id' => $reviewerEmployeeId])->save();

            return $review->load(['reviewer.user', 'employee']);
        });

        if ($review->status === AppraisalKinds::StatusManagerReview) {
            $this->notifier->managerReviewOpened($review, 'HR made you the reviewer of this appraisal.');
        }

        return $review->fresh();
    }

    /**
     * HR closes the cycle: no more reviews or calibration; reviewed
     * appraisals are shared with the employees, who can acknowledge them.
     *
     * @return array{shared: int, unfinished: int}
     */
    public function closeCycle(PerformanceCycle $cycle, User $actor): array
    {
        $cycle = DB::transaction(function () use ($cycle, $actor): PerformanceCycle {
            $cycle = PerformanceCycle::query()->lockForUpdate()->findOrFail($cycle->getKey());

            if (! $cycle->isActive() || ! $this->access->canManageCycle($actor, $cycle)) {
                throw new RuntimeException('Only a running cycle of your active company can be closed, by HR.');
            }

            $cycle->forceFill(['status' => AppraisalKinds::CycleClosed, 'closed_at' => now(), 'closed_by' => $actor->getKey()])->save();

            return $cycle;
        });

        $toShare = $cycle->showsResultsAfterManagerReview()
            ? new EloquentCollection
            : $cycle->reviews()->where('status', AppraisalKinds::StatusCompleted)->get();

        $this->notifier->resultsShared($cycle, $toShare);

        return [
            'shared'     => $toShare->count(),
            'unfinished' => $cycle->reviews()->whereIn('status', [AppraisalKinds::StatusSelfReview, AppraisalKinds::StatusManagerReview])->count(),
        ];
    }

    /**
     * Appraisal tasks of the user in the company for the home dashboard, in one query.
     *
     * @return array{self: int, self_overdue: int, self_due_on: ?string, manager: int, manager_overdue: int, acknowledge: int}
     */
    public function taskCountsFor(User $user, int $companyId): array
    {
        $empty = ['self' => 0, 'self_overdue' => 0, 'self_due_on' => null, 'manager' => 0, 'manager_overdue' => 0, 'acknowledge' => 0];
        $today = AppraisalKinds::today()->toDateString();
        $userId = (int) $user->getKey();
        $fallback = $this->access->isFallbackHr($user, $companyId);
        $noManager = '(mu.id is null or mu.is_active = 0)';
        $mine = '(m.user_id = ? and mu.is_active = 1)'.($fallback ? " or {$noManager}" : '');
        $notOwn = '(e.user_id is null or e.user_id <> ?)';

        $row = DB::table('employees_performance_reviews as r')
            ->join('employees_performance_cycles as c', 'c.id', '=', 'r.cycle_id')
            ->join('employees_employees as e', 'e.id', '=', 'r.employee_id')
            ->leftJoin('employees_employees as m', fn ($join) => $join->on('m.id', '=', 'r.reviewer_id')->whereNull('m.deleted_at'))
            ->leftJoin('users as mu', 'mu.id', '=', 'm.user_id')
            ->where('r.company_id', $companyId)
            ->whereNull('e.deleted_at')
            ->whereIn('r.status', [AppraisalKinds::StatusSelfReview, AppraisalKinds::StatusManagerReview, AppraisalKinds::StatusCompleted])
            ->whereIn('c.status', [AppraisalKinds::CycleActive, AppraisalKinds::CycleClosed])
            ->where(function ($query) use ($userId, $fallback, $noManager): void {
                $query->where('e.user_id', $userId)->orWhere('m.user_id', $userId);

                if ($fallback) {
                    $query->orWhere(fn ($inner) => $inner->where('r.status', AppraisalKinds::StatusManagerReview)->whereRaw($noManager));
                }
            })
            ->selectRaw(
                "coalesce(sum(case when e.user_id = ? and r.status = 'self_review' and c.status = 'active' then 1 else 0 end), 0) as self_open,
                coalesce(sum(case when e.user_id = ? and r.status = 'self_review' and c.status = 'active' and c.self_review_due_on < ? then 1 else 0 end), 0) as self_overdue,
                min(case when e.user_id = ? and r.status = 'self_review' and c.status = 'active' then c.self_review_due_on end) as self_due_on,
                coalesce(sum(case when r.status = 'manager_review' and c.status = 'active' and {$notOwn} and ({$mine}) then 1 else 0 end), 0) as manager_open,
                coalesce(sum(case when r.status = 'manager_review' and c.status = 'active' and {$notOwn} and ({$mine}) and c.manager_review_due_on < ? then 1 else 0 end), 0) as manager_overdue,
                coalesce(sum(case when e.user_id = ? and r.status = 'completed' and (c.status = 'closed' or c.results_visibility = ?) then 1 else 0 end), 0) as to_acknowledge",
                [$userId, $userId, $today, $userId, $userId, $userId, $userId, $userId, $today, $userId, AppraisalKinds::VisibleAfterManagerReview],
            )
            ->first();

        if (! $row) {
            return $empty;
        }

        return [
            'self'            => (int) $row->self_open,
            'self_overdue'    => (int) $row->self_overdue,
            'self_due_on'     => $row->self_due_on ? (string) $row->self_due_on : null,
            'manager'         => (int) $row->manager_open,
            'manager_overdue' => (int) $row->manager_overdue,
            'acknowledge'     => (int) $row->to_acknowledge,
        ];
    }

    /**
     * Completion and rating distribution of a cycle (HR overview), in two queries.
     *
     * @return array{total: int, statuses: array<string, int>, done: int, percent: int, bands: array<int, array{label: string, color: string, count: int}>, average: ?float, unbanded: int}
     */
    public function cycleStats(PerformanceCycle $cycle): array
    {
        $statuses = $cycle->reviews()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($count): int => (int) $count)->all();
        $total = array_sum($statuses);
        $done = ($statuses[AppraisalKinds::StatusCompleted] ?? 0) + ($statuses[AppraisalKinds::StatusAcknowledged] ?? 0);

        $byBand = $cycle->reviews()->toBase()
            ->whereIn('status', [AppraisalKinds::StatusCompleted, AppraisalKinds::StatusAcknowledged])
            ->selectRaw('final_band, count(*) as total, avg(final_rating) as average')
            ->groupBy('final_band')
            ->get();

        $bands = collect(AppraisalScoring::bands($cycle))
            ->map(fn (array $band): array => ['label' => $band['label'], 'color' => $band['color'], 'count' => (int) ($byBand->firstWhere('final_band', $band['label'])->total ?? 0)])
            ->all();

        $rated = $byBand->filter(fn ($row): bool => $row->average !== null);
        $ratedCount = $rated->sum('total');

        return [
            'total'    => $total,
            'statuses' => $statuses,
            'done'     => $done,
            'percent'  => $total > 0 ? (int) floor($done * 100 / $total) : 0,
            'bands'    => $bands,
            'average'  => $ratedCount > 0 ? round($rated->sum(fn ($row): float => (float) $row->average * (int) $row->total) / $ratedCount, 2) : null,
            'unbanded' => (int) $byBand->whereNull('final_band')->sum('total'),
        ];
    }

    protected function locked(PerformanceReview $review): PerformanceReview
    {
        return PerformanceReview::query()
            ->lockForUpdate()
            ->with(['cycle', 'employee.parent', 'reviewer.user'])
            ->findOrFail($review->getKey());
    }

    /**
     * Ratings 1-5 and comments of the form's items only.
     *
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<string, mixed>  $data
     * @return array{items: array<string, array{rating: ?int, comment: ?string}>}
     */
    protected function cleanAnswers(array $items, array $data): array
    {
        $given = (array) ($data['items'] ?? []);
        $answers = [];

        foreach (array_keys($items) as $key) {
            $answer = is_array($given[$key] ?? null) ? $given[$key] : [];
            $answers[$key] = [
                'rating'  => AppraisalScoring::rating($answer['rating'] ?? null),
                'comment' => $this->text($answer['comment'] ?? null),
            ];
        }

        return ['items' => $answers];
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  array{items: array<string, array{rating: ?int}>}  $answers
     */
    protected function assertComplete(array $items, array $answers): void
    {
        $missing = collect($items)
            ->filter(fn (array $item, string $key): bool => ($answers['items'][$key]['rating'] ?? null) === null)
            ->pluck('title');

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Rate every item before you submit. Not rated yet: '.$missing->take(5)->implode(', ').($missing->count() > 5 ? ' and '.($missing->count() - 5).' more' : '').'.');
        }
    }

    /** @param  array<int, mixed>  $goals */
    protected function replaceNextPeriodGoals(PerformanceReview $review, array $goals): void
    {
        $review->goals()->where('period', AppraisalKinds::GoalNextPeriod)->delete();

        foreach (array_values($goals) as $goal) {
            if (! is_array($goal) || blank($goal['title'] ?? null)) {
                continue;
            }

            $due = filled($goal['due_date'] ?? null) ? rescue(fn () => CarbonImmutable::parse((string) $goal['due_date'])->toDateString(), null, false) : null;

            $review->goals()->create([
                'company_id'  => $review->company_id,
                'period'      => AppraisalKinds::GoalNextPeriod,
                'title'       => Str::limit(trim((string) $goal['title']), 250, ''),
                'description' => $this->text($goal['description'] ?? null),
                'weight'      => is_numeric($goal['weight'] ?? null) ? max(0, min(100, (float) $goal['weight'])) : 0,
                'status'      => 'open',
                'due_date'    => $due,
            ]);
        }
    }

    protected function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return filled($value) ? Str::limit($value, 5000, '') : null;
    }
}
