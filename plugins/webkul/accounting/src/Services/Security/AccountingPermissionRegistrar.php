<?php

namespace Webkul\Accounting\Services\Security;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\PermissionRegistrar;

class AccountingPermissionRegistrar
{
    /**
     * Role-name -> permission-bundle map for the finance role catalogue.
     * Deliberately name-matched, exactly like the pre-existing
     * admin/manager/accountant tiers below -- extending the established
     * mechanism rather than inventing a new one. A role is only ever
     * granted a bundle here if a Role row with one of these names already
     * exists (created by FinanceRoleSeeder, or by hand); this registrar
     * never creates roles itself.
     *
     * @return array<string, array{names: array<int, string>, permissions: array<int, string>}>
     */
    private function financeRoleBundles(): array
    {
        return [
            'finance_operator' => [
                'names'       => ['finance_operator', 'finance operator'],
                'permissions' => AccountingPermissions::financeOperator(),
            ],
            'ap_officer' => [
                'names'       => ['ap_officer', 'ap officer', 'accounts_payable_officer', 'accounts payable officer'],
                'permissions' => AccountingPermissions::apOfficer(),
            ],
            'ar_officer' => [
                'names'       => ['ar_officer', 'ar officer', 'accounts_receivable_officer', 'accounts receivable officer'],
                'permissions' => AccountingPermissions::arOfficer(),
            ],
            'treasury_officer' => [
                'names'       => ['treasury_officer', 'treasury officer'],
                'permissions' => AccountingPermissions::treasuryOfficer(),
            ],
            'reconciliation_officer' => [
                'names'       => ['reconciliation_officer', 'reconciliation officer'],
                'permissions' => AccountingPermissions::reconciliationOfficer(),
            ],
            'tax_officer' => [
                'names'       => ['tax_officer', 'tax officer'],
                'permissions' => AccountingPermissions::taxOfficer(),
            ],
            'controller' => [
                'names'       => ['controller'],
                'permissions' => AccountingPermissions::controller(),
            ],
            'fpa_analyst' => [
                'names'       => ['fpa_analyst', 'fpa analyst', 'fp&a_analyst', 'fp&a analyst'],
                'permissions' => AccountingPermissions::fpaAnalyst(),
            ],
            'vp_finance' => [
                'names'       => ['vp_finance', 'vp finance'],
                'permissions' => AccountingPermissions::vpFinance(),
            ],
            'cfo' => [
                'names'       => ['cfo'],
                'permissions' => AccountingPermissions::cfo(),
            ],
            'internal_auditor' => [
                'names'       => ['internal_auditor', 'internal auditor', 'auditor'],
                'permissions' => AccountingPermissions::internalAuditor(),
            ],
            'external_auditor' => [
                'names'       => ['external_auditor', 'external auditor'],
                'permissions' => AccountingPermissions::externalAuditor(),
            ],
        ];
    }

    /**
     * @return array{permissions: int, admin_roles: int, manager_roles: int, accountant_roles: int, finance_role_grants: array<string, int>}
     */
    public function synchronize(): array
    {
        $now = now();
        $bundles = $this->financeRoleBundles();

        $names = collect(AccountingPermissions::all())->unique()->values();

        Permission::query()->insertOrIgnore($names->map(fn (string $name): array => [
            'name'       => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $permissionIds = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->pluck('id', 'name');

        $adminRoles = $this->rolesNamed(Role::getSystemRoleNames());
        $managerRoles = $this->rolesNamed(['accounting_manager', 'accounting manager', 'finance_manager', 'finance manager']);
        $accountantRoles = $this->rolesNamed(['accountant', 'accounting', 'finance_user', 'finance user']);

        $this->grant($adminRoles->pluck('id')->all(), $permissionIds->values()->all());

        // The curated AccountingPermissions::all() list above only covers
        // hand-defined "business action" permissions (post journal, review
        // bank transactions, manage FS tags, ...). It never included the
        // separate, auto-generated per-resource Filament permissions
        // (view/create/update on Journal Entries, Chart of Accounts, FS
        // Tags, Bank Statements, Currencies, Products, Payment Terms,
        // Report Templates, ...) -- Admin only has those through an
        // unrelated, plugin-agnostic "grant literally everything" sync
        // elsewhere, which this registrar never touches. Confirmed live: an
        // Accounting_manager user could post/approve/pay but could not even
        // *view* the Chart of Accounts or Journal Entries screens. Manager
        // tier is meant to be full parity with Admin for this plugin, so it
        // gets both sets; accountant tier deliberately keeps the narrower,
        // curated bundle only (segregation of duties).
        $this->grant($managerRoles->pluck('id')->all(), $permissionIds->values()->all());
        $this->grant($managerRoles->pluck('id')->all(), $this->accountingResourcePermissionIds()->all());

        $this->grant(
            $accountantRoles->pluck('id')->all(),
            $permissionIds->only(AccountingPermissions::accountant())->values()->all(),
        );

        $financeRoleGrants = [];
        foreach ($bundles as $key => $bundle) {
            $roles = $this->rolesNamed($bundle['names']);

            $this->grant(
                $roles->pluck('id')->all(),
                $permissionIds->only($bundle['permissions'])->values()->all(),
            );

            $financeRoleGrants[$key] = $roles->count();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'permissions'         => $permissionIds->count(),
            'admin_roles'         => $adminRoles->count(),
            'manager_roles'       => $managerRoles->count(),
            'accountant_roles'    => $accountantRoles->count(),
            'finance_role_grants' => $financeRoleGrants,
        ];
    }

    /**
     * Every real Permission row whose resource belongs to the accounting
     * plugin -- the auto-generated Filament/Shield per-resource
     * permissions (view_any_accounting_journal::entry,
     * view_accounting_fs::tag, create_accounting_bill, ...), which live
     * outside the hand-curated AccountingPermissions::all() list entirely.
     * Matched by the same "accounting" token boundary Admin's own
     * permission set was compared against to find this gap in the first
     * place -- not a guess, verified against the live permissions table.
     *
     * @return Collection<int, int>
     */
    private function accountingResourcePermissionIds()
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->where(function ($query): void {
                $query->where('name', 'like', 'accounting\_%')
                    ->orWhere('name', 'like', '%\_accounting\_%')
                    ->orWhere('name', 'like', 'page\_accounting%')
                    ->orWhere('name', 'like', 'widget\_accounting%');
            })
            ->pluck('id');
    }

    private function rolesNamed(array $names)
    {
        $normalized = collect($names)->map(fn (string $name): string => mb_strtolower(trim($name)))->unique();

        return Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->filter(fn (Role $role): bool => $normalized->contains(mb_strtolower((string) $role->getRawOriginal('name'))));
    }

    private function grant(array $roleIds, array $permissionIds): void
    {
        if ($roleIds === [] || $permissionIds === []) {
            return;
        }

        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
            }
        }

        DB::table(config('permission.table_names.role_has_permissions'))->insertOrIgnore($rows);
    }
}
