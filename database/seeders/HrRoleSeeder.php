<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Employee\Services\Security\HrPermissionRegistrar;
use Webkul\Security\Models\Role;

/**
 * Creates the HR role catalogue's genuinely-missing roles, then
 * delegates the actual permission grants to
 * HrPermissionRegistrar::synchronize() -- the same two-step pattern
 * FinanceRoleSeeder already established for the finance role catalogue.
 *
 * Deliberately does NOT create a role literally named "hr_manager" (or
 * any of its name variants): that name already resolves to the
 * pre-existing, full-access $hrRoles tier in HrPermissionRegistrar (see
 * that class's synchronize()) -- creating one here would just be another
 * admin-equivalent role under a different guise. "hr_ops_manager" is
 * this work's genuinely new, correctly-scoped senior HR role. Idempotent
 * (firstOrCreate) and safe to re-run.
 */
class HrRoleSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private const ROLE_NAMES = [
        'hr_administrator',
        'hr_ops_manager',
        'hr_officer',
        'sensitive_data_custodian',
        'recruiter',
        'hiring_manager',
        'hr_auditor',
    ];

    public function run(): void
    {
        foreach (self::ROLE_NAMES as $name) {
            Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app(HrPermissionRegistrar::class)->synchronize();
    }
}
