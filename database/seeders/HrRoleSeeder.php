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
 * "hr_manager" is the single role explicitly requested to own ALL HR
 * functionality end to end (onboarding, reporting-lines visibility,
 * leave/time-off, recruitment, timesheets, performance, sensitive data)
 * -- distinct from ERP Administrator, which retains system
 * administration but is not meant to be the HR business owner. This
 * name was previously and deliberately left uncreated here specifically
 * because it resolves to HrPermissionRegistrar::synchronize()'s
 * pre-existing, full-access $hrRoles tier (matched by name: "hr",
 * "hr_manager", "hr manager", "human resources", "human resources
 * manager") -- that tier already grants the complete HrPermissions::
 * all() bundle (152 permissions spanning every HR plugin), so no new
 * bundle or registrar logic was needed, only creating the role row
 * itself. "hr_ops_manager" remains the separate, narrower senior-but-
 * not-full-access role from the earlier HR-roles work. Idempotent
 * (firstOrCreate) and safe to re-run.
 */
class HrRoleSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private const ROLE_NAMES = [
        'hr_manager',
        'hr_administrator',
        'hr_ops_manager',
        'hr_officer',
        'sensitive_data_custodian',
        'recruiter',
        'hiring_manager',
        'hr_auditor',
        // Same gap as "hr_manager" originally was: HrPermissionRegistrar::
        // synchronize() already matches a role literally named "manager"
        // (rolesNamed(['manager', 'department_manager', ...])) and grants
        // it HrPermissions::manager() -- but nothing ever created the row.
        // Confirmed live: a plain employee who is someone's
        // Employee.parent_id (the "Line Manager" a Leave/Attendance/Claims
        // request routes hierarchy_route: 'requester_manager' to) could be
        // correctly resolved by ApprovalEngine::canAct() but had no way to
        // reach the Approval Queue page to act on it -- no role granted
        // them that permission, because no role matching this tier existed
        // to grant it to.
        'manager',
    ];

    public function run(): void
    {
        foreach (self::ROLE_NAMES as $name) {
            Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app(HrPermissionRegistrar::class)->synchronize();
    }
}
