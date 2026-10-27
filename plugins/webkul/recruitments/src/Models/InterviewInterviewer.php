<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Recruitment\Enums\InterviewRecommendation;
use Webkul\Security\Models\User;

/**
 * One interviewer on one interview, with their own scorecard: rating 1-5,
 * recommendation and notes, given once the interview has started
 * (InterviewService::submitFeedback()).
 */
class InterviewInterviewer extends Model
{
    protected $table = 'recruitments_interview_interviewers';

    protected $fillable = [
        'interview_id',
        'user_id',
        'rating',
        'recommendation',
        'feedback',
        'submitted_at',
        'feedback_requested_at',
    ];

    protected $casts = [
        'rating'                => 'integer',
        'recommendation'        => InterviewRecommendation::class,
        'submitted_at'          => 'datetime',
        'feedback_requested_at' => 'datetime',
    ];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class, 'interview_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hasSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
