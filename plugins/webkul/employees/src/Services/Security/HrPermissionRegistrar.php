<?php

namespace Webkul\Employee\Services\Security;

use Illuminate\Support\Facades\DB;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\PermissionRegistrar;

class HrPermissionRegistrar
{
    /**
     * Role-name -> permission-bundle map for the HR role catalogue.
     * Extends the same name-matched mechanism already used for the
     * admin/hr/manager tiers below, following the exact pattern
     * AccountingPermissionRegistrar established for finance roles. A
     * role is only ever granted a bundle here if a Role row with one of
     * these names already exists (created by HrRoleSeeder); this
     * registrar never creates roles itself.
     *
     * @return array<string, array{names: array<int, string>, permissions: array<int, string>}>
     */
    private function hrRoleBundles(): array
    {
        return [
            'hr_administrator' => [
                'names'       => ['hr_administrator', 'hr administrator'],
                'permissions' => HrPermissions::hrAdministrator(),
            ],
            // Deliberately NOT named "hr_manager"/"hr manager" -- those
            // names already match the pre-existing $hrRoles tier below
            // and get the FULL HrPermissions::all() (admin-equivalent)
            // bundle. "HR Operations Manager" is this work's genuinely
            // new, correctly-scoped senior-but-not-admin role.
            'hr_ops_manager' => [
                'names'       => ['hr_ops_manager', 'hr ops manager', 'hr operations manager'],
                'permissions' => HrPermissions::hrManager(),
            ],
            'hr_officer' => [
                'names'       => ['hr_officer', 'hr officer'],
                'permissions' => HrPermissions::hrOfficer(),
            ],
            'sensitive_data_custodian' => [
                'names'       => ['sensitive_data_custodian', 'sensitive data custodian'],
                'permissions' => HrPermissions::sensitiveDataCustodian(),
            ],
            'recruiter' => [
                'names'       => ['recruiter'],
                'permissions' => HrPermissions::recruiter(),
            ],
            'hiring_manager' => [
                'names'       => ['hiring_manager', 'hiring manager'],
                'permissions' => HrPermissions::hiringManager(),
            ],
            'hr_auditor' => [
                'names'       => ['hr_auditor', 'hr auditor'],
                'permissions' => HrPermissions::hrAuditor(),
            ],
        ];
    }

    /** @return array{permissions: int, admin_roles: int, hr_roles: int, manager_roles: int, hr_role_grants: array<string, int>} */
    public function synchronize(): array
    {
        $now = now();
        $bundles = $this->hrRoleBundles();

        $names = collect(HrPermissions::all())->unique()->values();
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
        $hrRoles = $this->rolesNamed(['hr', 'hr_manager', 'hr manager', 'human resources', 'human resources manager']);
        $managerRoles = $this->rolesNamed(['manager', 'department_manager', 'department manager', 'team_manager', 'team manager']);
        $this->grant($adminRoles->pluck('id')->all(), $permissionIds->values()->all());
        $this->grant($hrRoles->pluck('id')->all(), $permissionIds->values()->all());
        $this->grant($managerRoles->pluck('id')->all(), $permissionIds->only(HrPermissions::manager())->values()->all());

        $hrRoleGrants = [];
        foreach ($bundles as $key => $bundle) {
            $roles = $this->rolesNamed($bundle['names']);
            $this->grant($roles->pluck('id')->all(), $permissionIds->only($bundle['permissions'])->values()->all());
            $hrRoleGrants[$key] = $roles->count();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'permissions'    => $permissionIds->count(),
            'admin_roles'    => $adminRoles->count(),
            'hr_roles'       => $hrRoles->count(),
            'manager_roles'  => $managerRoles->count(),
            'hr_role_grants' => $hrRoleGrants,
        ];
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
