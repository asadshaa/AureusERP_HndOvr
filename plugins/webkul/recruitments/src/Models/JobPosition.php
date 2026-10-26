<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition as BaseJobPosition;
use Webkul\Employee\Models\Skill;
use Webkul\Partner\Models\Industry;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;

class JobPosition extends BaseJobPosition
{
    public const APPROVAL_REQUEST_TYPE = 'recruitment_job_opening';

    public function __construct(array $attributes = [])
    {
        $this->mergeFillable([
            'address_id',
            'manager_id',
            'industry_id',
            'recruiter_id',
            'no_of_hired_employee',
            'date_from',
            'date_to',
        ]);

        $this->mergeCasts([
            'date_from' => 'datetime',
            'date_to'   => 'datetime',
        ]);

        parent::__construct($attributes);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'address_id')->where('sub_type', 'company');
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'job_position_skills', 'job_position_id', 'skill_id');
    }

    public function interviewers()
    {
        return $this->belongsToMany(User::class, 'recruitments_job_position_interviewers', 'job_position_id', 'user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class, 'industry_id');
    }

    protected function noOfEmployee(): Attribute
    {
        return Attribute::make(
            get: function () {
                return once(function () {
                    return $this->employees()
                        ->where('is_active', true)
                        ->count();
                });
            }
        );
    }

    protected function noOfHiredEmployee(): Attribute
    {
        return Attribute::make(
            get: function () {
                return once(function () {
                    return $this->applications()
                        ->where(function ($query) {
                            $query->whereNotNull('date_closed')
                                ->where('is_active', true);
                        })
                        ->count();
                });
            }
        );
    }

    protected function expectedEmployees(): Attribute
    {
        return Attribute::make(
            get: function () {
                $currentEmployees = $this->getAttributeValue('no_of_employee');

                return $currentEmployees + ($this->no_of_recruitment ?? 0);
            }
        );
    }

    public function applications()
    {
        return $this->hasMany(Applicant::class, 'job_id');
    }

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'subject')
            ->where('request_type', self::APPROVAL_REQUEST_TYPE);
    }

    public function postingStatus(): JobPostingStatus
    {
        return JobPostingStatus::fromStored($this->posting_status);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->is_active && $this->postingStatus() === JobPostingStatus::PUBLISHED;
    }

    /**
     * ApprovalSubjectSynchronizer contract: ApprovalEngine calls this inside the
     * decision transaction. Approval makes the job publishable; rejection sends
     * it back to draft so the recruiter can correct and resubmit it.
     */
    public function synchronizeApprovalState(ApprovalRequest $request): void
    {
        if ($request->request_type !== self::APPROVAL_REQUEST_TYPE
            || $this->postingStatus() !== JobPostingStatus::PENDING_APPROVAL) {
            return;
        }

        $target = match ($request->status) {
            'approved' => JobPostingStatus::APPROVED,
            'rejected' => JobPostingStatus::DRAFT,
            default    => null,
        };

        if ($target) {
            $this->update(['posting_status' => $target->value]);
        }
    }

    /**
     * Server-side guard for every save, whatever the caller: a new job starts as
     * a draft, status moves follow JobPostingStatus::transitions(), and the
     * approval states must be backed by the job's latest approval request.
     */
    public function assertPostingTransitionIsAllowed(): void
    {
        if (! $this->isDirty('posting_status')) {
            return;
        }

        $target = JobPostingStatus::tryFrom((string) $this->posting_status)
            ?? throw new InvalidArgumentException("Unknown job posting status [{$this->posting_status}].");

        if (! $this->exists) {
            if ($target !== JobPostingStatus::DRAFT) {
                throw new RuntimeException('A new job opening starts as a draft and must be approved before it is published.');
            }

            return;
        }

        $current = JobPostingStatus::fromStored($this->getOriginal('posting_status'));
        if ($current === $target) {
            return;
        }

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException("A job opening cannot move from {$current->getLabel()} to {$target->getLabel()}.");
        }

        $latestApprovalStatus = $this->approvalRequests()->latest('id')->value('status');

        if ($target === JobPostingStatus::PENDING_APPROVAL && $latestApprovalStatus !== 'pending') {
            throw new RuntimeException('A job opening is pending approval only while its approval request is pending.');
        }

        if ($target === JobPostingStatus::APPROVED && $latestApprovalStatus !== 'approved') {
            throw new RuntimeException('A job opening can only be marked approved by its approval request.');
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(fn (JobPosition $jobPosition) => $jobPosition->assertPostingTransitionIsAllowed());

        static::updated(function ($jobPosition) {
            cache()->forget("job_position_{$jobPosition->id}_employee_count");
            cache()->forget("job_position_{$jobPosition->id}_hired_count");
        });
    }
}
