<?php

/**
 * Regression tests for the name/id lookup index in Webkul\Security\PermissionRegistrar.
 *
 * The index must answer every lookup exactly like the original linear scan, and must never
 * outlive the permissions collection it was built from (cache flushes, collection clears).
 */

use Illuminate\Support\Collection as SupportCollection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\PermissionRegistrar as SpatiePermissionRegistrar;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function permissionIndexTestUser(): User
{
    $company = Company::factory()->create([
        'currency_id' => Currency::query()->where('code', 'PKR')->value('id'),
        'is_active'   => true,
    ]);

    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

function permissionIndexTestPermission(string $suffix, string $guard = 'web'): Permission
{
    return Permission::query()->create(['name' => 'perm_index_test_'.$suffix.'_'.uniqid(), 'guard_name' => $guard]);
}

function permissionIndexFlush(): void
{
    app(SpatiePermissionRegistrar::class)->forgetCachedPermissions();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * The original linear filter, used as the reference result.
 *
 * @param  array<string, mixed>  $params
 * @return array<int|string, mixed>
 */
function permissionIndexLinearIds(array $params, bool $onlyOne): array
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->getPermissions();

    /** @var SupportCollection $all */
    $all = (fn () => $this->permissions)->call($registrar);

    $matches = $all->filter(function (array $permission) use ($params): bool {
        foreach ($params as $attribute => $value) {
            if ($permission[$attribute] != $value) {
                return false;
            }
        }

        return true;
    });

    if ($onlyOne) {
        return $matches->isEmpty() ? [] : [0 => $matches->first()['id']];
    }

    return $matches->map(fn (array $permission) => $permission['id'])->all();
}

/**
 * @param  array<string, mixed>  $params
 * @return array<int|string, mixed>
 */
function permissionIndexLookupIds(array $params, bool $onlyOne): array
{
    return app(PermissionRegistrar::class)->getPermissions($params, $onlyOne)->map(fn ($permission) => $permission->id)->all();
}

it('grants a directly assigned permission and denies an unassigned one', function (): void {
    $direct = permissionIndexTestPermission('direct');
    $other = permissionIndexTestPermission('other');
    $user = permissionIndexTestUser();
    $user->givePermissionTo($direct);
    permissionIndexFlush();

    expect($user->fresh()->can($direct->name))->toBeTrue()
        ->and($user->fresh()->can($other->name))->toBeFalse()
        ->and(Permission::findByName($direct->name, 'web')->id)->toBe($direct->id)
        ->and(Permission::findById($direct->id, 'web')->name)->toBe($direct->name);
});

it('grants a permission inherited through a role', function (): void {
    $viaRole = permissionIndexTestPermission('role');
    $role = Role::query()->create(['name' => 'perm_index_test_role_'.uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($viaRole);
    $user = permissionIndexTestUser();
    $user->assignRole($role);
    permissionIndexFlush();

    $permission = Permission::findByName($viaRole->name, 'web');

    expect($user->fresh()->can($viaRole->name))->toBeTrue()
        ->and($permission->roles->pluck('id')->all())->toContain($role->id);
});

it('does not match a permission registered for another guard', function (): void {
    $sanctumOnly = permissionIndexTestPermission('sanctum', 'sanctum');
    $user = permissionIndexTestUser();
    permissionIndexFlush();

    expect($user->fresh()->can($sanctumOnly->name))->toBeFalse()
        ->and(permissionIndexLookupIds(['name' => $sanctumOnly->name, 'guard_name' => 'web'], true))->toBe([])
        ->and(permissionIndexLookupIds(['name' => $sanctumOnly->name, 'guard_name' => 'sanctum'], true))->toBe([0 => $sanctumOnly->id])
        ->and(fn () => Permission::findByName($sanctumOnly->name, 'web'))->toThrow(PermissionDoesNotExist::class);
});

it('sees permissions created after the index was built once the cache is flushed', function (): void {
    $user = permissionIndexTestUser();
    $existing = permissionIndexTestPermission('existing');
    permissionIndexFlush();

    // Builds the lookup index from the current collection.
    expect($user->fresh()->can($existing->name))->toBeFalse();

    $created = permissionIndexTestPermission('created_later');
    $user->givePermissionTo($created);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($user->fresh()->can($created->name))->toBeTrue()
        ->and(Permission::findByName($created->name, 'web')->id)->toBe($created->id);

    $createdAfterClear = permissionIndexTestPermission('created_after_clear');
    $user->givePermissionTo($createdAfterClear);
    app(SpatiePermissionRegistrar::class)->forgetCachedPermissions();
    app(PermissionRegistrar::class)->clearPermissionsCollection();

    expect($user->fresh()->can($createdAfterClear->name))->toBeTrue();
});

it('returns exactly what the linear filter returns for every lookup shape', function (): void {
    $webPermission = permissionIndexTestPermission('shape');
    $sanctumPermission = permissionIndexTestPermission('shape_sanctum', 'sanctum');
    permissionIndexFlush();

    $cases = [
        ['name' => $webPermission->name, 'guard_name' => 'web'],
        ['name'       => $webPermission->name, 'guard_name' => 'sanctum'],
        ['name'       => $sanctumPermission->name, 'guard_name' => 'sanctum'],
        ['id'         => $webPermission->id, 'guard_name' => 'web'],
        ['id'         => $webPermission->id, 'guard_name' => 'sanctum'],
        ['id'         => (string) $webPermission->id, 'guard_name' => 'web'],
        ['name'       => 'perm_index_test_missing_'.uniqid(), 'guard_name' => 'web'],
        ['id'         => PHP_INT_MAX, 'guard_name' => 'web'],
        ['name'       => $webPermission->name],
        ['guard_name' => 'sanctum'],
        [],
    ];

    foreach ($cases as $params) {
        foreach ([true, false] as $onlyOne) {
            expect(permissionIndexLookupIds($params, $onlyOne))
                ->toBe(permissionIndexLinearIds($params, $onlyOne), json_encode($params).' onlyOne='.(int) $onlyOne);
        }
    }
});
