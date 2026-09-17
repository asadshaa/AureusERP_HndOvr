<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;
use Webkul\Security\Models\Role;

/**
 * Creates the finance role catalogue's genuinely-missing roles (see the
 * "Aureus ERP — Finance Roles & Permissions Implementation" spec's Step 2
 * mapping table) as empty Role shells, then delegates the actual
 * permission grants to AccountingPermissionRegistrar::synchronize() --
 * exactly the same two-step "create the role row, then let the registrar
 * grant by name" pattern DemoAccountingSeeder already uses for
 * 'accountant'/'accounting_manager'.
 *
 * Deliberately does NOT create a role for ERP Administrator, Accountant,
 * or Finance Manager: those already exist (Admin, accountant,
 * accounting_manager respectively) and the spec's "CRITICAL DUPLICATION
 * RULE" forbids creating a duplicate. Idempotent (firstOrCreate) and safe
 * to re-run.
 */
class FinanceRoleSeeder extends Seeder
{
    /**
     * Canonical role names for the 11 roles with no existing functional
     * equivalent. Kept lowercase/snake_case to match the storage
     * convention already used by 'accounting_manager' (Role::getNameAttribute()
     * ucfirsts on read, so this displays as "Finance_operator" etc. in the
     * admin UI -- identical to how "Accounting_manager" already displays
     * today).
     *
     * @var array<int, string>
     */
    private const ROLE_NAMES = [
        'finance_operator',
        'ap_officer',
        'ar_officer',
        'treasury_officer',
        'reconciliation_officer',
        'tax_officer',
        'controller',
        'fpa_analyst',
        'vp_finance',
        'cfo',
        'internal_auditor',
    ];

    public function run(): void
    {
        foreach (self::ROLE_NAMES as $name) {
            Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app(AccountingPermissionRegistrar::class)->synchronize();
    }
}
