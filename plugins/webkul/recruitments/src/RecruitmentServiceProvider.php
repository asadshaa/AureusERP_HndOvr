<?php

namespace Webkul\Recruitment;

use Filament\Panel;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;
use Webkul\Recruitment\Console\Commands\AnalyseCvsCommand;
use Webkul\Recruitment\Console\Commands\PullWebsiteApplicationsCommand;
use Webkul\Recruitment\Console\Commands\RemindPostingRemovalsCommand;
use Webkul\Recruitment\Console\Commands\SendInterviewRemindersCommand;
use Webkul\Recruitment\Database\Seeders\JobOpeningWorkflowSeeder;
use Webkul\Recruitment\Database\Seeders\ScreeningQuestionSeeder;
use Webkul\Recruitment\Jobs\AnalyseApplicantCv;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPostingEvent;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Services\ApplicantSourceRegistry;
use Webkul\Recruitment\Services\InterviewService;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingLifecycle;
use Webkul\Recruitment\Services\JobPostingLinks;
use Webkul\Recruitment\Services\RecruitmentMailer;
use Webkul\Recruitment\Services\Website\CompanyWebsitePublisher;
use Webkul\Recruitment\Services\Website\WebsiteDerivedChannelPublisher;
use Webkul\Recruitment\Services\Website\WebsiteListingRefresher;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

class RecruitmentServiceProvider extends PackageServiceProvider
{
    public static string $name = 'recruitments';

    public static string $viewNamespace = 'recruitments';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasViews()
            ->hasTranslations()
            ->hasMigrations([
                '2025_01_06_133002_create_recruitments_stages_table',
                '2025_01_07_053021_create_recruitments_stages_jobs_table',
                '2025_01_09_071817_create_recruitments_degrees_table',
                '2025_01_09_082748_create_recruitments_refuse_reasons_table',
                '2025_01_09_095909_create_recruitments_applicant_categories_table',
                '2025_01_09_125852_create_recruitments_candidates_table',
                '2025_01_10_045048_create_recruitments_candidate_applicant_categories_table',
                '2025_01_10_082944_create_recruitments_candidate_skills_table',
                '2025_01_10_115422_create_recruitments_applicants_table',
                '2025_01_13_072547_create_recruitments_applicant_interviewers_table',
                '2025_01_13_075926_create_recruitments_applicant_applicant_categories_table',
                '2025_01_14_080159_add_is_default_column_stages_table',
                '2025_01_14_143102_add_columns_to_employees_job_positions_table',
                '2025_01_16_081327_create_recruitments_job_position_interviewers_table',
                '2026_08_25_000013_extend_recruitment_pipeline',
                '2026_10_26_000001_create_recruitments_job_postings_tables',
                '2026_10_26_000002_backfill_recruitments_job_postings',
                '2026_10_27_000001_create_recruitments_screening_tables',
                '2026_10_28_000001_add_public_job_fields_to_job_positions',
                '2026_10_29_000001_create_recruitments_website_tables',
                '2026_10_30_000001_create_recruitments_interviews_tables',
            ])
            ->runsMigrations()
            ->hasCommands([
                RemindPostingRemovalsCommand::class,
                AnalyseCvsCommand::class,
                PullWebsiteApplicationsCommand::class,
                SendInterviewRemindersCommand::class,
            ])
            ->hasDependencies([
                'employees',
            ])
            ->hasSeeder('Webkul\\Recruitment\\Database\Seeders\\DatabaseSeeder')
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->installDependencies()
                    ->runsMigrations()
                    ->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('recruitments');
    }

    public function packageBooted(): void
    {
        // A company created after the initial seed still needs its job opening
        // approval workflow; without it no job in that company can be published.
        Company::created(function (Company $company): void {
            try {
                if (Schema::hasTable('support_approval_workflows')) {
                    app(JobOpeningWorkflowSeeder::class)->provisionForCompany($company);
                }

                if (Schema::hasTable('recruitments_screening_questions')) {
                    app(ScreeningQuestionSeeder::class)->provisionForCompany($company);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });

        $this->registerJobPostingGuardsOnBaseJobModel();
        $this->registerCvAnalysisTriggers();
        $this->registerWebsitePublishers();
        $this->registerApplicationConfirmationTrigger();
        $this->registerInterviewClosureTrigger();
    }

    /**
     * Company careers website (automation plan A3): the "Company website"
     * channel lists jobs through the company's WordPress connection; Google
     * for Jobs and the Indeed feed follow that listing. Edits of a listed job
     * (or of its screening questions) are pushed again after commit.
     */
    protected function registerWebsitePublishers(): void
    {
        $registry = $this->app->make(JobPostingChannelRegistry::class);
        $registry->registerPublisher($this->app->make(CompanyWebsitePublisher::class));
        $registry->registerPublisher(new WebsiteDerivedChannelPublisher(WebsiteDerivedChannelPublisher::GOOGLE));
        $registry->registerPublisher(new WebsiteDerivedChannelPublisher(WebsiteDerivedChannelPublisher::INDEED_FEED));

        JobPostingEvent::created(fn (JobPostingEvent $event) => WebsiteDerivedChannelPublisher::followWebsiteListing($event));

        $tableExists = null;
        $ready = function () use (&$tableExists): bool {
            return $tableExists ??= Schema::hasTable('recruitments_job_postings');
        };

        JobPosition::updated(function (JobPosition $job) use ($ready): void {
            if ($ready()) {
                WebsiteListingRefresher::jobUpdated($job);
            }
        });

        EmployeeJobPosition::updated(function (EmployeeJobPosition $job) use ($ready): void {
            if ($ready()) {
                WebsiteListingRefresher::jobUpdated($job);
            }
        });

        JobQuestion::saved(fn (JobQuestion $question) => WebsiteListingRefresher::questionChanged($question));
        JobQuestion::deleted(fn (JobQuestion $question) => WebsiteListingRefresher::questionChanged($question));
    }

    /**
     * Stage 2.3: refusing or archiving an application, from any screen,
     * cancels its upcoming interviews (interviewers get the cancellation; the
     * candidate is not emailed). Needs a signed-in user allowed to change the
     * application; otherwise the interviews stay for HR to cancel by hand.
     */
    protected function registerInterviewClosureTrigger(): void
    {
        Applicant::updated(function (Applicant $applicant): void {
            $refused = $applicant->wasChanged('refuse_reason_id') && $applicant->refuse_reason_id;
            $archived = $applicant->wasChanged('is_active') && ! $applicant->is_active;
            $actor = CurrentCompany::user();

            if ((! $refused && ! $archived) || ! $actor || ! Schema::hasTable('recruitments_interviews')) {
                return;
            }

            try {
                app(InterviewService::class)->cancelForClosedApplication($applicant, $refused ? __('The application was refused.') : __('The application was archived.'), $actor);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Stage 2.6: an application that arrives through one of the configured
     * intake sources (the careers website by default; not bulk imports) gets
     * the queued "application received" email once.
     */
    protected function registerApplicationConfirmationTrigger(): void
    {
        Applicant::created(function (Applicant $applicant): void {
            $source = $applicant->applicant_properties['source_provenance']['adapter'] ?? null;

            if ($source && in_array($source, (array) config('recruitment.mail.confirm_sources', []), true)) {
                try {
                    app(RecruitmentMailer::class)->confirmApplication($applicant);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /**
     * CV analyser (automation plan A5): score a new application, an application
     * moved to another job, and every application of a candidate whose CV
     * changed. Queued after commit; the analyser saves quietly, so this never
     * loops.
     */
    protected function registerCvAnalysisTriggers(): void
    {
        $enabled = fn (): bool => (bool) config('recruitment.cv_analysis.enabled') && (bool) config('recruitment.cv_analysis.auto');

        Applicant::created(function (Applicant $applicant) use ($enabled): void {
            if ($enabled() && $applicant->job_id) {
                AnalyseApplicantCv::dispatch($applicant->id);
            }
        });

        Applicant::updated(function (Applicant $applicant) use ($enabled): void {
            if ($enabled() && $applicant->job_id && $applicant->wasChanged('job_id')) {
                AnalyseApplicantCv::dispatch($applicant->id);
            }
        });

        Candidate::updated(function (Candidate $candidate) use ($enabled): void {
            if (! $enabled() || ! $candidate->wasChanged('resume_path')) {
                return;
            }

            Applicant::query()
                ->where('candidate_id', $candidate->id)
                ->whereNotNull('job_id')
                ->pluck('id')
                ->each(fn ($applicantId) => AnalyseApplicantCv::dispatch((int) $applicantId));
        });
    }

    /**
     * Jobs are also saved through the employees plugin's EmployeeJobPosition,
     * whose model events JobPosition::boot() does not see: register the same
     * job-ad guards there, so deleting a job or moving it to another company
     * through either model cannot orphan or leak its ads.
     */
    protected function registerJobPostingGuardsOnBaseJobModel(): void
    {
        $tableExists = null;
        $ready = function () use (&$tableExists): bool {
            return $tableExists ??= Schema::hasTable('recruitments_job_postings');
        };

        EmployeeJobPosition::saving(function (EmployeeJobPosition $job) use ($ready): void {
            if ($ready()) {
                app(JobPostingLifecycle::class)->assertCompanyChangeIsAllowed($job);
            }
        });

        EmployeeJobPosition::deleting(fn (EmployeeJobPosition $job) => $ready() && ! app(JobPostingLifecycle::class)->guardJobDeletion($job) ? false : null);

        EmployeeJobPosition::deleted(function (EmployeeJobPosition $job) use ($ready): void {
            if ($ready()) {
                app(JobPostingLifecycle::class)->handleJobDeleted($job);
            }
        });

        EmployeeJobPosition::restored(function (EmployeeJobPosition $job) use ($ready): void {
            if ($ready()) {
                app(JobPostingLifecycle::class)->handleJobRestored($job);
            }
        });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ApplicantSourceRegistry::class);
        $this->app->singleton(JobPostingChannelRegistry::class);
        $this->app->singleton(JobPostingLinks::class);

        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(RecruitmentPlugin::make());
        });
    }
}
