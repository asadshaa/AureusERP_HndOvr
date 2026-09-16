<?php

namespace Webkul\Employee\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Security\Models\User;

class PerformanceService
{
    /** @return Collection<int, PerformanceReview> */
    public function launch(PerformanceCycle $cycle, User $actor): Collection
    {
        if ((int) $actor->default_company_id !== (int) $cycle->company_id
            && ! $actor->allowedCompanies()->whereKey($cycle->company_id)->exists()) {
            throw new RuntimeException('The user cannot launch a performance cycle for this company.');
        }

        return DB::transaction(function () use ($cycle): Collection {
            $employees = Employee::query()
                ->where('company_id', $cycle->company_id)
                ->where('is_active', true)
                ->get();
            $reviews = $employees->map(function (Employee $employee) use ($cycle): PerformanceReview {
                return PerformanceReview::query()->firstOrCreate(
                    ['cycle_id' => $cycle->id, 'employee_id' => $employee->id],
                    [
                        'company_id' => $cycle->company_id,
                        'reviewer_id'=> $employee->parent_id ?? $employee->department?->manager_id,
                        'status'     => 'self_review',
                    ],
                );
            });
            $cycle->update(['status' => 'active']);

            return $reviews;
        });
    }

    public function submitSelfReview(PerformanceReview $review, Employee $employee, float $rating, ?string $comments = null): PerformanceReview
    {
        if ((int) $review->employee_id !== (int) $employee->id || $review->status !== 'self_review') {
            throw new RuntimeException('This performance review is not available for employee self-review.');
        }
        $review->update([
            'self_rating'  => $rating,
            'self_comments'=> $comments,
            'status'       => 'manager_review',
            'submitted_at' => now(),
        ]);

        return $review->fresh();
    }

    /**
     * @param  array{competency_ratings?: ?array, improvement_plan?: ?string, promotion_recommendation?: ?string}  $extra
     */
    public function completeManagerReview(PerformanceReview $review, Employee $reviewer, float $rating, ?string $comments = null, array $extra = []): PerformanceReview
    {
        if ((int) $review->reviewer_id !== (int) $reviewer->id || $review->status !== 'manager_review') {
            throw new RuntimeException('This employee is not the assigned manager reviewer.');
        }
        $updates = [
            'manager_rating'   => $rating,
            'manager_comments' => $comments,
            'status'           => 'completed',
            'completed_at'     => now(),
        ];
        foreach (['competency_ratings', 'improvement_plan', 'promotion_recommendation'] as $field) {
            if (array_key_exists($field, $extra)) {
                $updates[$field] = $extra[$field];
            }
        }
        $review->update($updates);

        return $review->fresh();
    }
}
