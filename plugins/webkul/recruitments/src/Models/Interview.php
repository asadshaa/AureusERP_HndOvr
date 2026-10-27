<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Webkul\Recruitment\Enums\InterviewMode;
use Webkul\Recruitment\Enums\InterviewStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A scheduled interview of one application (Recruitment Stage 2.3). Times
 * are stored in UTC; ends_at is kept in step with starts_at and
 * duration_minutes on every save. Changes go through InterviewService, which
 * checks permissions, the company and the status rules, and sends the
 * calendar invitations.
 *
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property InterviewStatus $status
 * @property InterviewMode $mode
 */
class Interview extends Model
{
    protected $table = 'recruitments_interviews';

    protected $fillable = [
        'company_id',
        'applicant_id',
        'job_id',
        'stage_id',
        'title',
        'starts_at',
        'duration_minutes',
        'mode',
        'location',
        'status',
        'organizer_id',
        'notes',
        'cancel_reason',
        'sequence',
        'candidate_notified_at',
        'reminded_at',
        'creator_id',
    ];

    protected $casts = [
        'starts_at'             => 'datetime',
        'ends_at'               => 'datetime',
        'duration_minutes'      => 'integer',
        'mode'                  => InterviewMode::class,
        'status'                => InterviewStatus::class,
        'sequence'              => 'integer',
        'candidate_notified_at' => 'datetime',
        'reminded_at'           => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Interview $interview): void {
            if ($interview->starts_at) {
                $interview->ends_at = $interview->starts_at->copy()->addMinutes(max(1, (int) $interview->duration_minutes));
            }
        });
    }

    /**
     * Timezone interview times are entered and shown in (screens, emails):
     * config recruitment.interviews.timezone, else the application timezone.
     * Companies have no timezone of their own yet.
     */
    public static function displayTimezone(): string
    {
        return (string) (config('recruitment.interviews.timezone') ?: config('app.timezone', 'UTC'));
    }

    public function localStart(): Carbon
    {
        return $this->starts_at->copy()->setTimezone(static::displayTimezone());
    }

    public function localEnd(): Carbon
    {
        return $this->ends_at->copy()->setTimezone(static::displayTimezone());
    }

    /** "Mon 2 Nov 2026, 09:00-10:00 (Asia/Karachi)" */
    public function whenLabel(): string
    {
        return $this->localStart()->format('D j M Y, H:i').'-'.$this->localEnd()->format('H:i').' ('.static::displayTimezone().')';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'stage_id');
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** The interviewers (users), with their scorecard on the pivot. */
    public function interviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'recruitments_interview_interviewers', 'interview_id', 'user_id')
            ->withPivot(['rating', 'recommendation', 'feedback', 'submitted_at', 'feedback_requested_at'])
            ->withTimestamps();
    }

    /** One row per interviewer: who they are and their feedback. */
    public function assignments(): HasMany
    {
        return $this->hasMany(InterviewInterviewer::class, 'interview_id');
    }

    public function assignmentFor(User|int $user): ?InterviewInterviewer
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return $this->assignments->firstWhere('user_id', $userId);
    }

    public function hasInterviewer(User|int $user): bool
    {
        return $this->assignmentFor($user) !== null;
    }

    public function hasStarted(): bool
    {
        return $this->starts_at !== null && $this->starts_at->lessThanOrEqualTo(now());
    }

    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->lessThanOrEqualTo(now());
    }

    public function hasSubmittedFeedback(): bool
    {
        return $this->assignments->contains(fn (InterviewInterviewer $assignment): bool => $assignment->hasSubmitted());
    }

    /** Average 1-5 rating of the submitted scorecards, or null when there are none. */
    public function averageRating(): ?float
    {
        $ratings = $this->assignments
            ->filter(fn (InterviewInterviewer $assignment): bool => $assignment->hasSubmitted() && $assignment->rating !== null)
            ->pluck('rating');

        return $ratings->isEmpty() ? null : round((float) $ratings->avg(), 2);
    }

    /** Interviews the user takes part in, as interviewer or organizer. */
    public function scopeWithParticipant(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where($inner->qualifyColumn('organizer_id'), $userId)
            ->orWhereHas('assignments', fn (Builder $assignments): Builder => $assignments->where('user_id', $userId)));
    }

    /** Interviews where the user is an interviewer who still owes feedback. */
    public function scopeAwaitingFeedbackFrom(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return $query
            ->whereIn($query->qualifyColumn('status'), InterviewStatus::feedbackValues())
            ->where($query->qualifyColumn('starts_at'), '<=', now())
            ->whereHas('assignments', fn (Builder $assignments): Builder => $assignments
                ->where('user_id', $userId)
                ->whereNull('submitted_at'));
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), InterviewStatus::SCHEDULED->value)
            ->where($query->qualifyColumn('ends_at'), '>', now());
    }
}
