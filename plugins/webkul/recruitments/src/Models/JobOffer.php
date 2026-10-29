<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Security\Models\User;
use Webkul\Support\Contracts\NamesApprovalManager;
use Webkul\Support\Contracts\ReturnableApprovalSubject;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * A job offer to one application (HR parity 3.6). Its status only moves
 * through JobOfferService (approval through the shared ApprovalEngine,
 * request type "job_offer", written back by synchronizeApprovalState()).
 * The salary is kept here, never in the approval request, the chatter or
 * the history, so only the people JobOfferService::canSeeSalary() allows
 * ever see it.
 */
class JobOffer extends Model implements NamesApprovalManager, ReturnableApprovalSubject
{
    public const APPROVAL_REQUEST_TYPE = 'job_offer';

    /** Salary components HR may break the monthly gross into. */
    public const BREAKDOWN_COMPONENTS = [
        'basic'      => 'Basic salary',
        'house_rent' => 'House rent allowance',
        'utilities'  => 'Utilities allowance',
        'allowances' => 'Other allowances',
    ];

    protected $table = 'recruitments_job_offers';

    protected $fillable = [
        'company_id',
        'applicant_id',
        'candidate_id',
        'job_id',
        'department_id',
        'reporting_manager_id',
        'hiring_manager_id',
        'template_id',
        'approval_request_id',
        'employee_id',
        'reference',
        'status',
        'designation',
        'joining_date',
        'expiry_date',
        'currency_id',
        'salary_gross',
        'salary_breakdown',
        'probation_months',
        'working_hours',
        'benefits',
        'internal_notes',
        'requires_ceo_approval',
        'letter_path',
        'letter_generated_at',
        'response_token',
        'sent_at',
        'sent_by',
        'sent_to',
        'responded_at',
        'response_reason',
        'response_ip',
        'response_user_agent',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'expired_at',
        'withdrawn_at',
        'withdrawn_by',
        'withdraw_reason',
        'creator_id',
    ];

    /** The accept/decline token is a secret of the emailed link. */
    protected $hidden = [
        'response_token',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status'                => JobOfferStatus::class,
            'joining_date'          => 'date',
            'expiry_date'           => 'date',
            'salary_gross'          => 'decimal:2',
            'salary_breakdown'      => 'array',
            'probation_months'      => 'integer',
            'requires_ceo_approval' => 'boolean',
            'letter_generated_at'   => 'datetime',
            'sent_at'               => 'datetime',
            'responded_at'          => 'datetime',
            'submitted_at'          => 'datetime',
            'approved_at'           => 'datetime',
            'rejected_at'           => 'datetime',
            'expired_at'            => 'datetime',
            'withdrawn_at'          => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class)->withTrashed();
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hiring_manager_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OfferLetterTemplate::class, 'template_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /**
     * The employee created from the accepted offer. Deliberately not named
     * "employee": ApprovalEngine treats a subject's employee() as the person
     * the request is about.
     */
    public function hiredEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(JobOfferEvent::class, 'job_offer_id')->orderBy('id');
    }

    /** Offers still in play (draft to sent). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), JobOfferStatus::openValues());
    }

    /**
     * Offers whose current approval step is $user's, in one SQL query (no
     * per-request engine checks): a step naming the user or one of their
     * roles, the hiring manager step of their offers, and the HR steps for
     * an HR approver ($isHr). Lists only: the buttons still ask
     * ApprovalEngine::canAct().
     */
    public function scopeAwaitingDecisionBy(Builder $query, User $user, bool $isHr = false): Builder
    {
        $roleIds = $user->loadMissing('roles')->roles->pluck('id')->map(fn ($id): int => (int) $id)->all() ?: [0];
        $offers = $query->getModel()->getTable();

        return $query
            ->where($query->qualifyColumn('status'), JobOfferStatus::PENDING_APPROVAL->value)
            ->whereHas('approvalRequest', fn (Builder $approval): Builder => $approval
                ->where('status', 'pending')
                ->whereExists(fn ($steps) => $steps
                    ->selectRaw('1')
                    ->from('support_approval_steps as current_step')
                    ->whereColumn('current_step.workflow_id', 'support_approval_requests.workflow_id')
                    ->whereColumn('current_step.sequence', 'support_approval_requests.current_step_sequence')
                    ->where(fn ($who) => $who
                        ->where('current_step.approver_user_id', $user->id)
                        ->orWhereIn('current_step.approver_role_id', $roleIds)
                        ->orWhere(fn ($manager) => $manager
                            ->where('current_step.hierarchy_route', 'record_manager')
                            ->where("{$offers}.hiring_manager_id", $user->id))
                        ->when($isHr, fn ($hr) => $hr
                            ->orWhere('current_step.hierarchy_route', 'hr')
                            ->orWhere(fn ($fallback) => $fallback
                                ->where('current_step.hierarchy_route', 'record_manager')
                                ->whereNull("{$offers}.hiring_manager_id"))))));
    }

    public function candidateName(): string
    {
        return (string) ($this->candidate?->name ?? $this->applicant?->candidate?->name ?? __('the candidate'));
    }

    /** The position as offered: the designation, else the job's name. */
    public function positionLabel(): string
    {
        return (string) (filled($this->designation) ? $this->designation : ($this->job?->name ?? __('the position')));
    }

    public function currencyCode(): string
    {
        return (string) ($this->currency?->name ?? $this->company?->currency?->name ?? config('recruitment.offers.currency', 'PKR'));
    }

    /** The salary components HR filled in (component key => amount), in the letter's order. */
    public function breakdown(): array
    {
        $amounts = (array) ($this->salary_breakdown ?? []);

        return collect(array_keys(self::BREAKDOWN_COMPONENTS))
            ->mapWithKeys(fn (string $key): array => [$key => $amounts[$key] ?? null])
            ->filter(fn ($amount): bool => $amount !== null && $amount !== '' && (float) $amount > 0)
            ->all();
    }

    /**
     * The employee fields an accepted offer fills in when HR creates the
     * employee (CandidateConversionService): joining date, salary,
     * department, line manager, designation and the probation end date.
     *
     * @return array<string, mixed>
     */
    public function employeeAttributes(): array
    {
        return array_filter([
            'joining_date'       => $this->joining_date?->toDateString(),
            'base_salary'        => $this->salary_gross !== null ? (string) $this->salary_gross : null,
            'salary_currency_id' => $this->currency_id ?? $this->company?->currency_id,
            'department_id'      => $this->department_id,
            'parent_id'          => $this->reporting_manager_id,
            'job_id'             => $this->job_id,
            'job_title'          => filled($this->designation) ? $this->designation : null,
            'probation_end_date' => $this->joining_date && $this->probation_months
                ? $this->joining_date->copy()->addMonthsNoOverflow((int) $this->probation_months)->subDay()->toDateString()
                : null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * NamesApprovalManager: the hiring manager the "record_manager" step
     * routes to, fixed when the offer is submitted (and again on each
     * resubmission) so the approver does not change under a pending
     * request; null means the engine falls back to HR.
     */
    public function approvalManager(): ?User
    {
        if ($this->submitted_at === null) {
            return $this->resolveHiringManager();
        }

        return $this->hiring_manager_id ? $this->hiringManager : null;
    }

    /**
     * Who the hiring manager is right now: the job's manager, else the
     * recruiter's line manager -- never the person who submitted the offer
     * (nobody approves their own offer).
     */
    public function resolveHiringManager(): ?User
    {
        $this->loadMissing(['job.manager.user', 'job.recruiter.employee.parent.user', 'applicant.recruiter.employee.parent.user', 'approvalRequest', 'creator.employee.parent.user']);

        $requesterId = (int) ($this->approvalRequest?->requester_id ?? $this->creator_id);
        $recruiter = $this->applicant?->recruiter ?? $this->job?->recruiter ?? $this->creator;

        foreach ([$this->job?->manager?->user, $recruiter?->employee?->parent?->user] as $candidate) {
            if ($candidate instanceof User && $candidate->is_active && (int) $candidate->id !== $requesterId) {
                return $candidate;
            }
        }

        return null;
    }

    /** ApprovalSubjectSynchronizer contract: runs inside the engine's decision transaction. */
    public function synchronizeApprovalState(ApprovalRequest $request): void
    {
        app(JobOfferService::class)->synchronize($this, $request);
    }

    /** ReturnableApprovalSubject: the offer's page (edit and resubmit it from there when returned). */
    public function approvalSubjectUrl(?User $recipient = null): ?string
    {
        try {
            return JobOfferResource::getUrl('view', ['record' => $this]);
        } catch (Throwable) {
            return null;
        }
    }
}
