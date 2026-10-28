<?php

namespace Webkul\TimeOff;

use Filament\Panel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Employee\Models\Employee;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Database\Seeders\AttendanceLeaveTypeSeeder;
use Webkul\TimeOff\Listeners\AllocateDefaultLeaveOnEmployeeCreated;

class TimeOffServiceProvider extends PackageServiceProvider
{
    public static string $name = 'time-off';

    public static string $viewNamespace = 'time-off';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasTranslations()
            ->hasViews()
            ->hasMigrations([
                '2025_01_17_080711_create_time_off_leave_types_table',
                '2025_01_17_080712_create_time_off_leaves_table',
                '2025_01_20_080058_create_time_off_user_leave_types_table',
                '2025_01_20_130725_create_time_off_leave_mandatory_days_table',
                '2025_01_21_073921_create_time_off_leave_accrual_plans_table',
                '2025_01_21_085833_create_time_off_leave_accrual_levels_table',
                '2025_01_22_101656_create_time_off_leave_allocations_table',
                '2025_08_13_120000_alter_private_name_column_in_time_off_leaves_table',
                '2026_08_25_000014_extend_leave_for_shared_approval',
                '2026_10_28_000020_add_cancellation_to_time_off_leaves',
                '2026_10_28_000021_create_time_off_allocation_policies_table',
                '2026_10_28_000022_half_day_uses_casual_leave_balance',
            ])
            ->hasDependencies([
                'employees',
            ])
            ->runsMigrations()
            ->hasSeeder('Webkul\\TimeOff\\Database\\Seeders\\DatabaseSeeder')
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->installDependencies()
                    ->runsMigrations()
                    ->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('time-offs');
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(TimeOffPlugin::make());
        });
    }

    public function packageBooted(): void
    {
        // time-off declares a dependency on employees (see hasDependencies
        // above), never the other way around -- listening for the plain
        // Eloquent "created" event here keeps that one-directional
        // relationship intact instead of the employees plugin needing to
        // know time-off exists at all.
        Event::listen('eloquent.created: '.Employee::class, AllocateDefaultLeaveOnEmployeeCreated::class);

        // A new company gets the attendance leave types (Half day, Short leave, On Duty).
        Company::created(function (Company $company): void {
            try {
                if (Schema::hasTable('time_off_leave_types')) {
                    app(AttendanceLeaveTypeSeeder::class)->provisionForCompany($company);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
