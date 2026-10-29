<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One employee's appraisal in a performance cycle. self_answers /
 * manager_answers hold the ratings and comments per template item
 * ({"items": {key: {"rating": 4, "comment": "..."}}, "comments": "..."});
 * self_rating / manager_rating are their weighted scores, final_rating the
 * manager score or HR's calibrated one, final_band its rating band.
 */
class PerformanceReview extends Model
{
    protected $table = 'employees_performance_reviews';

    protected $fillable = [
        'company_id', 'cycle_id', 'employee_id', 'reviewer_id', 'self_rating', 'manager_rating',
        'competency_ratings', 'self_comments', 'manager_comments', 'improvement_plan',
        'promotion_recommendation', 'status', 'submitted_at', 'completed_at',
        'self_answers', 'manager_answers', 'final_rating', 'final_band', 'calibrated_rating', 'calibration_reason',
        'calibrated_by', 'calibrated_at', 'manager_reviewed_by', 'self_skipped_at', 'acknowledged_at', 'acknowledgement_comment',
    ];

    protected function casts(): array
    {
        return [
            'self_rating'       => 'decimal:2',
            'manager_rating'    => 'decimal:2',
            'final_rating'      => 'decimal:2',
            'calibrated_rating' => 'decimal:2',
            'competency_ratings'=> 'array',
            'self_answers'      => 'array',
            'manager_answers'   => 'array',
            'submitted_at'      => 'datetime',
            'completed_at'      => 'datetime',
            'calibrated_at'     => 'datetime',
            'self_skipped_at'   => 'datetime',
            'acknowledged_at'   => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    public function calibrator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calibrated_by');
    }

    public function managerReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_reviewed_by');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(PerformanceGoal::class, 'review_id');
    }

    public function nextPeriodGoals(): HasMany
    {
        return $this->goals()->where('period', AppraisalKinds::GoalNextPeriod)->orderBy('id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(PerformanceReviewReminder::class, 'review_id');
    }

    /** The self review has been submitted (or HR moved it on without one). */
    public function selfReviewClosed(): bool
    {
        return $this->submitted_at !== null || $this->self_skipped_at !== null
            || in_array($this->status, [AppraisalKinds::StatusManagerReview, AppraisalKinds::StatusCompleted, AppraisalKinds::StatusAcknowledged], true);
    }

    /** The manager review is done (reviewed or acknowledged). */
    public function isReviewed(): bool
    {
        return in_array($this->status, [AppraisalKinds::StatusCompleted, AppraisalKinds::StatusAcknowledged], true);
    }
}
