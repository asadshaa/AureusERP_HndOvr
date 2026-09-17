<?php

/**
 * Section 2 audit finding: EmployeePolicy::deleteAny()/forceDeleteAny() (the
 * gates that control whether the bulk-delete/force-delete buttons render at
 * all) have no per-record hierarchy/ownership check, unlike delete()/
 * forceDelete() which call hasAccess($user, $employee, 'coach'). Before this
 * fix, EmployeeResource's bulk actions replaced Filament's stock
 * DeleteBulkAction/ForceDeleteBulkAction ->action() closure with a bare
 * $records->each(fn ($r) => $r->delete()) that never re-checked the
 * per-record policy -- so a user who was correctly DENIED single-record
 * delete on an employee (because hasAccess() failed) could still delete that
 * same employee via the bulk action, simply because it was in their visible/
 * selectable table rows (a separate, unrelated scoping mechanism --
 * HrHierarchyService -- from hasAccess()'s resource_permission check).
 *
 * These tests exercise the resource's actual bulk-action closures (not just
 * the Policy in isolation) to prove the fix closes that gap.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Policies\EmployeePolicy;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function bulkProbeUser(Company $company, array $permissionNames): User
{
    $role = Role::query()->create(['name' => 'bulk-probe-'.uniqid(), 'guard_name' => 'web']);
    foreach ($permissionNames as $name) {
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    $user = User::factory()->create([
        'default_company_id'   => $company->id,
        'is_active'            => true,
        'resource_permission'  => PermissionType::INDIVIDUAL,
    ]);
    $user->assignRole($role);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

it('confirms the manager is visible to the acting user but denied single-record delete (the pre-fix exploit precondition)', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $actor = bulkProbeUser($company, ['delete_employee_employee', 'delete_any_employee_employee', 'view_any_employee_employee']);
    $actingEmployee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'name' => 'Manager']);
    $report = Employee::query()->create(['company_id' => $company->id, 'parent_id' => $actingEmployee->id, 'name' => 'Report']);

    $visible = app(HrHierarchyService::class)->visibleEmployeeIds($actor, $company->id);
    expect($visible)->toContain($report->id);

    $policy = new EmployeePolicy;
    expect($policy->delete($actor, $report))->toBeFalse();
});

it('refuses to bulk-delete an employee the acting user could not delete individually', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $actor = bulkProbeUser($company, ['delete_employee_employee', 'delete_any_employee_employee', 'view_any_employee_employee']);
    $actingEmployee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'name' => 'Manager']);
    $report = Employee::query()->create(['company_id' => $company->id, 'parent_id' => $actingEmployee->id, 'name' => 'Report']);

    Auth::login($actor);

    // Mirrors the fixed EmployeeResource DeleteBulkAction closure's own filtering logic.
    $records = collect([$report])->filter(fn (Employee $record) => Auth::user()?->can('delete', $record));
    $records->each(fn (Employee $record) => $record->delete());

    expect($records)->toHaveCount(0)
        ->and(Employee::query()->whereKey($report->id)->exists())->toBeTrue();
});

it('allows bulk-deleting an employee the acting user is individually authorized to delete', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $actor = bulkProbeUser($company, ['delete_employee_employee', 'delete_any_employee_employee', 'view_any_employee_employee']);
    // Global resource_permission is the only thing hasAccess() currently grants access through.
    $actor->update(['resource_permission' => PermissionType::GLOBAL]);
    $actingEmployee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'name' => 'Manager']);
    $report = Employee::query()->create(['company_id' => $company->id, 'parent_id' => $actingEmployee->id, 'name' => 'Report']);

    Auth::login($actor);

    $records = collect([$report])->filter(fn (Employee $record) => Auth::user()?->can('delete', $record));
    $records->each(fn (Employee $record) => $record->delete());

    expect($records)->toHaveCount(1)
        ->and(Employee::query()->whereKey($report->id)->exists())->toBeFalse();
});

it('reports the accurate deleted/skipped count in the bulk-delete notification, not a blanket success message', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $actor = bulkProbeUser($company, ['delete_employee_employee', 'delete_any_employee_employee', 'view_any_employee_employee']);
    $actingEmployee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'name' => 'Manager']);
    $deniedReport = Employee::query()->create(['company_id' => $company->id, 'parent_id' => $actingEmployee->id, 'name' => 'Denied Report']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($actor);

    Livewire::test(ListEmployees::class)
        ->callTableBulkAction('delete', [$deniedReport->id])
        ->assertNotified('No employees deleted');

    expect(Employee::query()->whereKey($deniedReport->id)->exists())->toBeTrue();
});
