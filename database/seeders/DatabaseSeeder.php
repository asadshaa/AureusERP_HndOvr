<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Employee\Database\Seeders\ClaimsWorkflowSeeder;
use Webkul\Employee\Database\Seeders\SensitiveChangeWorkflowSeeder;
use Webkul\PluginManager\Database\Seeders\PluginSeeder;
use Webkul\Recruitment\Database\Seeders\RecruitmentWorkflowSeeder;
use Webkul\Security\Database\Seeders\DatabaseSeeder as SecurityDatabaseSeeder;
use Webkul\Support\Database\Seeders\DatabaseSeeder as SupportDatabaseSeeder;
use Webkul\TimeOff\Database\Seeders\LeaveWorkflowSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SecurityDatabaseSeeder::class,
            SupportDatabaseSeeder::class,
            PluginSeeder::class,
            PermissionSeeder::class,
            FinanceRoleSeeder::class,
            HrRoleSeeder::class,
            LeaveWorkflowSeeder::class,
            AttendanceWorkflowSeeder::class,
            RecruitmentWorkflowSeeder::class,
            ClaimsWorkflowSeeder::class,
            SensitiveChangeWorkflowSeeder::class,
        ]);
    }
}
