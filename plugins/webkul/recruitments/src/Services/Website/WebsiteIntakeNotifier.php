<?php

namespace Webkul\Recruitment\Services\Website;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Enums\ScreeningStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Pages\WebsiteIntake;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\IntakeFailure;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * Tells the recruiter about applications that arrived from the careers
 * website, and the integration managers about ones that could not be
 * imported. Idempotent (HierarchicalNotificationService) and never affects
 * the import: failures are only reported.
 */
class WebsiteIntakeNotifier
{
    public function __construct(
        protected HierarchicalNotificationService $notifications,
        protected ApprovalEngine $approvals,
    ) {}

    /** @param Collection<int, Applicant> $applicants new applications of one pull */
    public function applicationsReceived(Collection $applicants): void
    {
        $this->safely(function () use ($applicants): void {
            foreach ($applicants->groupBy('job_id') as $jobApplicants) {
                /** @var Applicant $first */
                $first = $jobApplicants->first();
                $job = JobPosition::withTrashed()->find($first->job_id);

                if (! $job) {
                    continue;
                }

                $count = $jobApplicants->count();
                $flagged = $jobApplicants->filter(fn (Applicant $applicant): bool => $applicant->screening_status === ScreeningStatus::FLAGGED->value)->count();
                $names = $jobApplicants->map(fn (Applicant $applicant): string => '• '.($applicant->candidate?->name ?? 'Applicant #'.$applicant->id))->implode("\n");

                $title = "{$count} new application(s) from the company website: {$job->name}";
                $body = $names.($flagged > 0 ? "\n\n{$flagged} did not pass the screening questions (see \"Not qualified\")." : '');
                $url = $count === 1
                    ? ApplicantResource::getUrl('view', ['record' => $first], panel: 'admin')
                    : JobPositionResource::getUrl('view', ['record' => $job], panel: 'admin');
                $key = 'recruitment_website_applications_'.$job->id.'_'.sha1($jobApplicants->pluck('id')->sort()->implode(','));

                foreach ($this->recruiters($job) as $user) {
                    $this->send($user, $title, $body, $count === 1 ? 'Open application' : 'Open job', $url, $key, [
                        'event'      => 'website_applications_received',
                        'job_id'     => $job->id,
                        'company_id' => $job->company_id,
                    ]);
                }
            }
        });
    }

    public function failureRecorded(IntakeFailure $failure): void
    {
        $this->safely(function () use ($failure): void {
            $title = 'A website application could not be imported';
            $body = "Applicant: {$failure->candidateLabel()}\nProblem: {$failure->error}\n\nRetry or dismiss it on the Website intake page.";

            foreach ($this->integrationManagers((int) $failure->company_id) as $user) {
                $this->send($user, $title, $body, 'Open Website intake', WebsiteIntake::getUrl(panel: 'admin'), "recruitment_website_intake_failure_{$failure->id}", [
                    'event'      => 'website_intake_failed',
                    'failure_id' => $failure->id,
                    'company_id' => $failure->company_id,
                ]);
            }
        });
    }

    /**
     * The job's recruiter when active, in the company and allowed to see
     * applications; otherwise the company's HR approvers.
     *
     * @return Collection<int, User>
     */
    protected function recruiters(JobPosition $job): Collection
    {
        $recruiter = $job->recruiter;

        if ($recruiter
            && $recruiter->is_active
            && $this->approvals->userCanAccessCompany($recruiter, (int) $job->company_id)
            && ($recruiter->can('view_any_recruitment_applicant') || $recruiter->can(HrPermissions::ManageJobPostings))) {
            return collect([$recruiter]);
        }

        $hr = $this->approvals->resolveHrApproversForCompany((int) $job->company_id)->values();

        if ($hr->isEmpty()) {
            Log::warning("WebsiteIntakeNotifier: nobody can be told about new website applications for job #{$job->id}.");
        }

        return $hr;
    }

    /** @return Collection<int, User> */
    protected function integrationManagers(int $companyId): Collection
    {
        $users = User::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('default_company_id', $companyId)
                ->orWhereHas('allowedCompanies', fn (Builder $companies) => $companies->whereKey($companyId)))
            ->get()
            ->filter(fn (User $user): bool => $user->can(HrPermissions::ManageRecruitmentIntegrations))
            ->values();

        return $users->isNotEmpty() ? $users : $this->approvals->resolveHrApproversForCompany($companyId)->values();
    }

    /** @param array<string, mixed> $audit */
    protected function send(User $user, string $title, string $body, string $actionLabel, string $url, string $key, array $audit): void
    {
        // Names and errors come from the website: escape everything (Filament renders HTML).
        $notification = FilamentNotification::make()
            ->info()
            ->title(e($title))
            ->body(nl2br(e($body)))
            ->actions([
                Action::make('open')->label($actionLabel)->url($url),
            ]);

        $this->notifications->sendIdempotentNotification($user, $notification, $key, $audit);
    }

    protected function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
