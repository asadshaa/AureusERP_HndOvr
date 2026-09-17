<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Enums\ApplicationStatus;
use Webkul\Recruitment\Services\CandidateConversionService;
use Webkul\Recruitment\Traits\HasApplicationStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UTMMedium;
use Webkul\Support\Models\UTMSource;

class Applicant extends Model
{
    use HasApplicationStatus, HasChatter, HasLogActivity, SoftDeletes;

    public const ACTIVITY_PLAN_PLUGIN = 'recruitments';

    protected $table = 'recruitments_applicants';

    protected $fillable = [
        'source_id',
        'medium_id',
        'external_application_id',
        'source_details',
        'candidate_id',
        'stage_id',
        'last_stage_id',
        'company_id',
        'recruiter_id',
        'job_id',
        'department_id',
        'refuse_reason_id',
        'state',
        'creator_id',
        'email_cc',
        'priority',
        'salary_proposed_extra',
        'salary_expected_extra',
        'applicant_properties',
        'applicant_notes',
        'is_active',
        'create_date',
        'date_closed',
        'date_opened',
        'date_last_stage_updated',
        'refuse_date',
        'probability',
        'screening_score',
        'interview_score',
        'assessment_score',
        'offer_status',
        'offer_date',
        'salary_proposed',
        'salary_expected',
        'delay_close',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'create_date'             => 'date',
        'date_closed'             => 'date',
        'date_opened'             => 'date',
        'date_last_stage_updated' => 'date',
        'refuse_date'             => 'date',
        'applicant_properties'    => 'json',
        'probability'             => 'double',
        'screening_score'         => 'double',
        'interview_score'         => 'double',
        'assessment_score'        => 'double',
        'offer_date'              => 'date',
        'salary_proposed'         => 'double',
        'salary_expected'         => 'double',
        'delay_close'             => 'double',
    ];

    /**
     * Transient, non-persisted signals computed by handleApplicationUpdation()
     * for EditApplicant::afterSave() to act on (send a confirmation email,
     * sync/notify interviewers). These must stay real declared properties --
     * assigning to an undeclared property name on an Eloquent model routes
     * through __set()/setAttribute() instead of plain PHP property
     * assignment, which silently adds it to $attributes and made the next
     * save() attempt to write a nonexistent "interviewerChanges"/
     * "notificationData" column, crashing on any update where the
     * interviewer relation happened to be loaded.
     */
    public array $notificationData = [];

    public array $interviewerChanges = [];

    protected $appends = [
        'application_status',
    ];

    public function getModelTitle(): string
    {
        return __('recruitments::models/applicant.title');
    }

    public function getLogAttributeLabels(): array
    {
        return [
            'company.name'      => __('recruitments::models/applicant.log-attributes.company'),
            'candidate.name'    => __('recruitments::models/applicant.log-attributes.candidate'),
            'job.name'          => __('recruitments::models/applicant.log-attributes.job'),
            'department.name'   => __('recruitments::models/applicant.log-attributes.department'),
            'stage.name'        => __('recruitments::models/applicant.log-attributes.stage'),
            'recruiter.name'    => __('recruitments::models/applicant.log-attributes.recruiter'),
            'creator.name'      => __('recruitments::models/applicant.log-attributes.creator'),
            'refuseReason.name' => __('recruitments::models/applicant.log-attributes.refuse-reason'),
            'state'             => __('recruitments::models/applicant.log-attributes.state'),
            'screening_score'   => __('recruitments::models/applicant.log-attributes.screening-score'),
            'interview_score'   => __('recruitments::models/applicant.log-attributes.interview-score'),
            'assessment_score'  => __('recruitments::models/applicant.log-attributes.assessment-score'),
            'offer_status'      => __('recruitments::models/applicant.log-attributes.offer-status'),
            'offer_date'        => __('recruitments::models/applicant.log-attributes.offer-date'),
            'priority'          => __('recruitments::models/applicant.log-attributes.priority'),
            'is_active'         => __('recruitments::models/applicant.log-attributes.is-active'),
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(UTMSource::class);
    }

    public function medium(): BelongsTo
    {
        return $this->belongsTo(UTMMedium::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function skills(): HasManyThrough
    {
        return $this->hasManyThrough(
            CandidateSkill::class,
            Candidate::class,
            'id',
            'candidate_id',
            'candidate_id',
            'id'
        );
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function lastStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'last_stage_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }

    public function interviewer()
    {
        return $this->belongsToMany(User::class, 'recruitments_applicant_interviewers', 'applicant_id', 'interviewer_id');
    }

    public function categories()
    {
        return $this->belongsToMany(ApplicantCategory::class, 'recruitments_applicant_applicant_categories', 'applicant_id', 'category_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function refuseReason(): BelongsTo
    {
        return $this->belongsTo(RefuseReason::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public static function getStatusOptions(): array
    {
        return ApplicationStatus::options();
    }

    public function setAsHired(): bool
    {
        return $this->updateStatus(ApplicationStatus::HIRED->value);
    }

    public function setAsRefused(int $refuseReasonId): bool
    {
        return $this->updateStatus(ApplicationStatus::REFUSED->value, [
            'refuse_reason_id' => $refuseReasonId,
        ]);
    }

    public function setAsArchived(): bool
    {
        return $this->updateStatus(ApplicationStatus::ARCHIVED->value);
    }

    public function reopen(): bool
    {
        return $this->updateStatus(ApplicationStatus::ONGOING->value);
    }

    public function updateStage(array $data): bool
    {
        return $this->update($data);
    }

    public function getApplicationStatusAttribute(): ?ApplicationStatus
    {
        if ($this->refuse_reason_id) {
            return ApplicationStatus::REFUSED;
        } elseif (! $this->is_active || $this->deleted_at) {
            return ApplicationStatus::ARCHIVED;
        } elseif ($this->date_closed) {
            return ApplicationStatus::HIRED;
        } else {
            return ApplicationStatus::ONGOING;
        }
    }

    public function createEmployee(): ?Employee
    {
        return app(CandidateConversionService::class)->convert($this);
    }

    public function handleApplicationCreation(): void
    {
        $authUser = Auth::user();

        $this->creator_id ??= $authUser?->id;

        $this->company_id ??= $authUser?->default_company_id;

        // The recruitments_applicants migration defaults this column to
        // false, so any creation path other than ListApplicants' header
        // action (the only place that currently sets it explicitly) would
        // otherwise produce an application whose application_status resolves
        // straight to ARCHIVED (see getApplicationStatusAttribute()) despite
        // never having been rejected or closed. Defaulting it here, the same
        // way creator_id/company_id are defaulted above, makes a plain,
        // unqualified create() behave correctly everywhere -- including
        // CandidateConversionService and any future non-UI entry point --
        // without relying on every call-site to remember to pass it.
        $this->is_active ??= true;
    }

    public function handleApplicationUpdation(): void
    {
        $original = $this->getRawOriginal();

        if ($this->isDirty('recruiter_id')) {
            $this->date_opened = now();
        }

        if ($this->isDirty('stage_id')) {
            $this->date_last_stage_updated = now();

            if (empty($original['stage_id'])) {
                $this->notificationData = $this->getAttributes();
            } else {
                $this->last_stage_id = $original['stage_id'];
            }
        }

        if ($this->relationLoaded('interviewer')) {
            $oldInterviewers = collect(
                $this->interviewer->pluck('id')
            );

            $newInterviewers = collect(
                $this->recruitments_applicant_interviewers ?? []
            );

            if (
                $oldInterviewers->isNotEmpty()
                || $newInterviewers->isNotEmpty()
            ) {
                $this->interviewerChanges = [
                    'old' => $oldInterviewers,
                    'new' => $newInterviewers,
                ];
            }
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($applicant) {
            $applicant->handleApplicationCreation();
        });

        static::updating(function ($applicant) {
            $applicant->handleApplicationUpdation();
        });
    }
}
