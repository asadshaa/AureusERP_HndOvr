<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Recruitment\Enums\ApplicationStatus;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\Stage;
use Webkul\Security\Models\User;

/**
 * One application per candidate per job (Recruitment Stage 2.2): the same
 * candidate can apply to several jobs, each with its own pipeline history.
 * Applying again to the same job never creates a duplicate; a refused or
 * archived application for that job is reopened instead.
 */
class CandidateApplicationService
{
    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_REOPENED = 'reopened';

    public const OUTCOME_EXISTING = 'existing';

    /** @return array{applicant: Applicant, outcome: string} */
    public function applyToJob(Candidate $candidate, JobPosition $job, User $actor): array
    {
        $companyId = (int) ($candidate->company_id ?? $actor->default_company_id);

        if ($job->company_id && (int) $job->company_id !== $companyId) {
            throw new RuntimeException('The job belongs to a different company than the candidate.');
        }

        return DB::transaction(function () use ($candidate, $job, $actor, $companyId): array {
            $existing = Applicant::withTrashed()
                ->where('company_id', $companyId)
                ->where('candidate_id', $candidate->id)
                ->where('job_id', $job->id)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $existing) {
                $applicant = Applicant::query()->create([
                    'company_id'    => $companyId,
                    'candidate_id'  => $candidate->id,
                    'job_id'        => $job->id,
                    'department_id' => $job->department_id,
                    'stage_id'      => $this->defaultStageId($companyId),
                    'recruiter_id'  => $job->recruiter_id ?? $actor->id,
                    'creator_id'    => $actor->id,
                    'create_date'   => now(),
                    'is_active'     => true,
                ]);

                return ['applicant' => $applicant, 'outcome' => self::OUTCOME_CREATED];
            }

            if (in_array($existing->application_status, [ApplicationStatus::REFUSED, ApplicationStatus::ARCHIVED], true)) {
                $existing->reopen();
                $existing->update(['recruiter_id' => $existing->recruiter_id ?? $actor->id]);

                return ['applicant' => $existing->refresh(), 'outcome' => self::OUTCOME_REOPENED];
            }

            // Ongoing or already hired for this job: keep the one application.
            return ['applicant' => $existing, 'outcome' => self::OUTCOME_EXISTING];
        });
    }

    protected function defaultStageId(int $companyId): ?int
    {
        return Stage::query()
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
            ->orderByDesc('is_default')
            ->orderBy('sort')
            ->value('id');
    }
}
