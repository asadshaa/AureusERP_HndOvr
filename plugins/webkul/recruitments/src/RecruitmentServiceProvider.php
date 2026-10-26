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
use Webkul\Recruitment\Console\Commands\RemindPostingRemovalsCommand;
use Webkul\Recruitment\Database\Seeders\JobOpeningWorkflowSeeder;
use Webkul\Recruitment\Database\Seeders\ScreeningQuestionSeeder;
use Webkul\Recruitment\Services\ApplicantSourceRegistry;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingLifecycle;
use Webkul\Recruitment\Services\JobPostingLinks;
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
            ])
            ->runsMigrations()
            ->hasCommands([
                RemindPostingRemovalsCommand::class,
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
