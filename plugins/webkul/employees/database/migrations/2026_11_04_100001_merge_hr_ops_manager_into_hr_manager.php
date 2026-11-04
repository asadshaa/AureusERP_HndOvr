<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar as SpatiePermissionRegistrar;
use Webkul\Security\PermissionRegistrar;

/**
 * HR is one role: "hr_ops_manager" is merged into "hr_manager", which already
 * holds every permission the ops role had. Each existing user who held the ops
 * role is given hr_manager (if they do not have it), then the ops role's
 * model_has_roles and role_has_permissions rows and the role itself are
 * removed. Assignments pointing at users that no longer exist are dropped, not
 * transferred. Idempotent; the counts are written to the log.
 *
 * down() recreates the role empty: the users who held it are NOT restored, and
 * its permissions come back only if a registrar bundle grants them again.
 */
return new class extends Migration
{
    private const OPS_ROLE_NAMES = ['hr_ops_manager', 'hr ops manager', 'hr operations manager'];

    public function up(): void
    {
        $tables = config('permission.table_names');
        $rolesTable = $tables['roles'] ?? 'roles';
        $modelHasRolesTable = $tables['model_has_roles'] ?? 'model_has_roles';
        $roleHasPermissionsTable = $tables['role_has_permissions'] ?? 'role_has_permissions';

        if (! Schema::hasTable($rolesTable) || ! Schema::hasTable($modelHasRolesTable)) {
            return;
        }

        $opsRoleIds = DB::table($rolesTable)
            ->where('guard_name', 'web')
            ->whereIn(DB::raw('LOWER(name)'), self::OPS_ROLE_NAMES)
            ->pluck('id');

        $counts = ['ops_roles' => $opsRoleIds->count(), 'reassigned' => 0, 'already_hr_manager' => 0, 'orphan_assignments' => 0, 'assignments_removed' => 0, 'permission_rows_removed' => 0];

        if ($opsRoleIds->isEmpty()) {
            Log::info('Merge hr_ops_manager into hr_manager: nothing to merge.', $counts);

            return;
        }

        DB::transaction(function () use ($opsRoleIds, $rolesTable, $modelHasRolesTable, $roleHasPermissionsTable, &$counts): void {
            $assignments = DB::table($modelHasRolesTable)->whereIn('role_id', $opsRoleIds)->get();
            $hrManagerRoleId = null;

            foreach ($assignments as $assignment) {
                $modelClass = $assignment->model_type;
                $modelExists = class_exists($modelClass)
                    && $modelClass::query()->withoutGlobalScopes()->whereKey($assignment->model_id)->exists();

                if (! $modelExists) {
                    $counts['orphan_assignments']++;

                    continue;
                }

                $hrManagerRoleId ??= DB::table($rolesTable)->where('guard_name', 'web')->where('name', 'hr_manager')->value('id')
                    ?? DB::table($rolesTable)->insertGetId(['name' => 'hr_manager', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

                $alreadyHasRole = DB::table($modelHasRolesTable)
                    ->where('role_id', $hrManagerRoleId)
                    ->where('model_type', $assignment->model_type)
                    ->where('model_id', $assignment->model_id)
                    ->exists();

                if ($alreadyHasRole) {
                    $counts['already_hr_manager']++;

                    continue;
                }

                DB::table($modelHasRolesTable)->insert([
                    'role_id'    => $hrManagerRoleId,
                    'model_type' => $assignment->model_type,
                    'model_id'   => $assignment->model_id,
                ]);
                $counts['reassigned']++;
            }

            $counts['assignments_removed'] = DB::table($modelHasRolesTable)->whereIn('role_id', $opsRoleIds)->delete();

            if (Schema::hasTable($roleHasPermissionsTable)) {
                $counts['permission_rows_removed'] = DB::table($roleHasPermissionsTable)->whereIn('role_id', $opsRoleIds)->delete();
            }

            DB::table($rolesTable)->whereIn('id', $opsRoleIds)->delete();
        });

        $this->forgetPermissionCaches();

        Log::info('Merge hr_ops_manager into hr_manager.', $counts);
    }

    public function down(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        if (! Schema::hasTable($rolesTable)) {
            return;
        }

        if (! DB::table($rolesTable)->where('guard_name', 'web')->where('name', 'hr_ops_manager')->exists()) {
            DB::table($rolesTable)->insert(['name' => 'hr_ops_manager', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->forgetPermissionCaches();
    }

    private function forgetPermissionCaches(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(SpatiePermissionRegistrar::class)->forgetCachedPermissions();
    }
};
