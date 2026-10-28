<?php

namespace Webkul\Employee;

use Filament\Panel;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Employee\Console\Commands\AutoCompleteShiftsCommand;
use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\Verifiers\GpsGeofenceVerifier;
use Webkul\Employee\Services\Attendance\Verifiers\RemoteExemptVerifier;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;
use Webkul\Security\Models\Team;
use Webkul\Support\Models\Company;

class EmployeeServiceProvider extends PackageServiceProvider
{
    public static string $name = 'employees';

    public static string $viewNamespace = 'employees';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasViews()
            ->hasTranslations()
            ->hasCommands([
                AutoCompleteShiftsCommand::class,
            ])
            ->hasMigrations([
                '2024_12_11_045350_create_employees_work_locations_table',
                '2024_12_11_051916_create_employees_departments_table',
                '2024_12_11_054555_create_employees_categories_table',
                '2024_12_11_073130_create_employees_employment_types_table',
                '2024_12_11_075004_create_employees_skill_types_table',
                '2024_12_11_075011_create_employees_skill_levels_table',
                '2024_12_11_075017_create_employees_skills_table',
                '2024_12_11_081046_create_employees_job_positions_table',
                '2024_12_11_100426_create_employees_calendars_table',
                '2024_12_11_100435_create_employees_calendar_attendances_table',
                '2024_12_11_100442_create_employees_calendar_leaves_table',
                '2024_12_11_120605_create_employees_departure_reasons_table',
                '2024_12_12_063353_create_employees_employees_table',
                '2024_12_12_063354_create_employees_employee_skills_table',
                '2024_12_12_140840_create_employees_employee_categories_table',
                '2024_12_16_065746_create_employees_employee_resume_line_types_table',
                '2024_12_16_070029_create_employees_employee_resumes_table',
                '2025_01_08_104443_add_manager_id_to_employees_departments_table',
                '2025_01_15_045708_create_job_position_skills_table',
                '2025_01_24_052852_add_department_id_to_activity_plans_table',
                '2025_08_20_082638_add_unique_user_id_to_employees_employees_table',
                '2026_08_25_000010_extend_hr_master_and_hierarchy',
                '2026_08_25_000011_create_hr_operations_tables',
                // Also missing from this list -- found live during a fresh-
                // database regression run, where every claim submission
                // failed with "Unknown column 'billed_amount'" because this
                // migration had never run on a genuine fresh install. It
                // existed in the long-lived dev database only because it was
                // migrated manually at the time it was written.
                '2026_09_16_000001_add_claim_fields_to_employees_requests_table',
                '2026_09_24_123304_add_unique_constraints_to_employees_identity_fields',
                '2026_09_30_100001_add_geofence_columns_to_employees_work_locations_table',
                '2026_09_30_100002_create_employees_employee_work_location_assignments_table',
                '2026_09_30_100003_create_employees_attendance_verifications_table',
                '2026_09_30_100004_add_verification_columns_to_employees_attendance_records_table',
                '2026_10_28_100001_create_employees_attendance_intervals_table',
                '2026_10_28_100002_create_employees_attendance_policies_table',
            ])
            ->runsMigrations()
            ->hasSeeder('Webkul\\Employee\\Database\Seeders\\DatabaseSeeder')
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->runsMigrations()
                    ->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('employees');
    }

    public function packageBooted(): void
    {
        // HrHierarchyService remembers visibility per request; forget it when the hierarchy changes.
        foreach ([Employee::class, Department::class, Team::class] as $hierarchyModel) {
            $hierarchyModel::saved(fn () => app(HrHierarchyService::class)->flushCache());
            $hierarchyModel::deleted(fn () => app(HrHierarchyService::class)->flushCache());
        }

        // A company created after the initial seed must still get its attendance
        // correction workflow, otherwise every correction / missed-day request in
        // that company is refused ("No active approval workflow is configured").
        Company::created(function (Company $company): void {
            try {
                if (Schema::hasTable('employees_request_types') && Schema::hasTable('support_approval_workflows')) {
                    app(AttendanceWorkflowSeeder::class)->provisionForCompany($company);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(HrHierarchyService::class);

        $this->app->tag([
            GpsGeofenceVerifier::class,
            RemoteExemptVerifier::class,
        ], 'attendance.verifiers');

        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(EmployeePlugin::make());
        });
    }
}
