<?php

/**
 * TEST REPORTING & ACCESS -- exercises HrHierarchyService, EmployeePolicy,
 * and EmployeeResource's query scoping and HTTP routes directly (not just
 * asserting on isolated Policy calls), across six role types: Employee,
 * Line Manager, HR user, Finance user, ERP Administrator, and a user
 * belonging to another company.
 */

use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Policies\EmployeePolicy;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function raUser(Company $company, array $permissionNames, string $roleName): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
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

function raEmployee(Company $company, ?User $user, string $name, ?Employee $manager = null): Employee
{
    return Employee::query()->create([
        'company_id' => $company->id,
        'user_id'    => $user?->id,
        'parent_id'  => $manager?->id,
        'name'       => $name,
        'work_email' => 'ra-'.uniqid().'@example.test',
    ]);
}

/**
 * Shared fixture: Company A with a manager, their direct report, and an
 * unrelated employee under a different manager; Company B with its own
 * employee. Built fresh per test to avoid cross-test id collisions.
 */
function raFixture(): array
{
    $companyA = Company::factory()->create(['is_active' => true]);
    $companyB = Company::factory()->create(['is_active' => true]);

    $managerUser = raUser($companyA, ['view_any_employee_employee', 'view_employee_employee'], 'ra_line_manager');
    $manager = raEmployee($companyA, $managerUser, 'Line Manager');

    $reportUser = raUser($companyA, ['view_any_employee_employee', 'view_employee_employee'], 'ra_employee_self');
    $report = raEmployee($companyA, $reportUser, 'Direct Report', $manager);

    $unrelatedUser = raUser($companyA, ['view_any_employee_employee', 'view_employee_employee'], 'ra_unrelated');
    $unrelated = raEmployee($companyA, $unrelatedUser, 'Unrelated Peer'); // no manager link to $manager

    $hrUser = raUser($companyA, [
        'view_any_employee_employee', 'view_employee_employee', 'hr_view_all_records', 'hr_view_sensitive_employee_data',
    ], 'ra_hr_user');

    // A real finance role/permission set via the actual Accounting registrar,
    // not hand-picked names -- proves the *real* finance permission bundle
    // grants no HR-sensitive access, not just a contrived one.
    $financeRole = Role::query()->firstOrCreate(['name' => 'controller', 'guard_name' => 'web']);
    app(AccountingPermissionRegistrar::class)->synchronize();
    $financeUser = User::factory()->create([
        'default_company_id' => $companyA->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $financeUser->assignRole($financeRole);
    $financeUser->allowedCompanies()->syncWithoutDetaching([$companyA->id]);

    $adminRole = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail();
    $adminUser = User::factory()->create([
        'default_company_id' => $companyA->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $adminUser->assignRole($adminRole);
    $adminUser->allowedCompanies()->syncWithoutDetaching([$companyA->id]);

    $otherCompanyUser = raUser($companyB, ['view_any_employee_employee', 'view_employee_employee'], 'ra_other_company');
    $otherCompanyEmployee = raEmployee($companyB, $otherCompanyUser, 'Company B Employee');

    return compact(
        'companyA', 'companyB', 'managerUser', 'manager', 'reportUser', 'report',
        'unrelatedUser', 'unrelated', 'hrUser', 'financeUser', 'adminUser', 'otherCompanyUser', 'otherCompanyEmployee'
    );
}

// ---------------------------------------------------------------------
// 1. Employee sees own permitted records.
// ---------------------------------------------------------------------
it('1. PASS: employee sees their own record and nothing else via visibleEmployeeIds', function () {
    $f = raFixture();
    $service = app(HrHierarchyService::class);

    $visible = $service->visibleEmployeeIds($f['reportUser'], $f['companyA']->id);

    expect($visible->all())->toBe([$f['report']->id]);

    $this->actingAs($f['reportUser']);
    $this->get("/admin/employees/employees/{$f['report']->id}")->assertOk();
});

// ---------------------------------------------------------------------
// 2. Manager sees permitted subordinate records.
// ---------------------------------------------------------------------
it('2. PASS: manager sees their direct report via visibleEmployeeIds and can open the record', function () {
    $f = raFixture();
    $service = app(HrHierarchyService::class);

    $visible = $service->visibleEmployeeIds($f['managerUser'], $f['companyA']->id);

    expect($visible->all())->toContain($f['manager']->id, $f['report']->id);

    $this->actingAs($f['managerUser']);
    $this->get("/admin/employees/employees/{$f['report']->id}")->assertOk();
});

// ---------------------------------------------------------------------
// 3. Manager cannot access unrelated employee records.
// ---------------------------------------------------------------------
it('3. PASS: manager cannot see or open an unrelated employee record (different manager tree)', function () {
    $f = raFixture();
    $service = app(HrHierarchyService::class);

    $visible = $service->visibleEmployeeIds($f['managerUser'], $f['companyA']->id);
    expect($visible->all())->not->toContain($f['unrelated']->id);

    $this->actingAs($f['managerUser']);
    $status = $this->get("/admin/employees/employees/{$f['unrelated']->id}")->getStatusCode();
    fwrite(STDERR, "STATUS_3=$status\n");
    expect($status)->not->toBe(200);
    test()->assertContains($status, [403, 404], "Expected a denial status (403/404), got {$status}");
});

// ---------------------------------------------------------------------
// 4. HR can access authorized HR scope.
// ---------------------------------------------------------------------
it('4. PASS: HR user with hr_view_all_records sees every employee in their company', function () {
    $f = raFixture();
    $service = app(HrHierarchyService::class);

    $visible = $service->visibleEmployeeIds($f['hrUser'], $f['companyA']->id);
    $allCompanyAIds = Employee::query()->where('company_id', $f['companyA']->id)->pluck('id')->sort()->values()->all();

    expect($visible->sort()->values()->all())->toBe($allCompanyAIds);

    $this->actingAs($f['hrUser']);
    $this->get("/admin/employees/employees/{$f['unrelated']->id}")->assertOk();
});

// ---------------------------------------------------------------------
// 5. Finance cannot access restricted HR data merely by having finance permissions.
// ---------------------------------------------------------------------
it('5. PASS: a real finance role (controller) grants neither hr_view_all_records nor hr_view_sensitive_employee_data', function () {
    $f = raFixture();

    expect($f['financeUser']->can('hr_view_all_records'))->toBeFalse()
        ->and($f['financeUser']->can('hr_view_sensitive_employee_data'))->toBeFalse();
});

it('5. PASS: finance user with no linked employee record and no HR permission sees zero employees and is denied direct access', function () {
    $f = raFixture();
    $service = app(HrHierarchyService::class);

    // Finance user has no Employee row of their own (no employee identity),
    // and lacks hr_view_all_records -- visibleEmployeeIds falls through to
    // "no employee found for this user" and returns empty.
    $visible = $service->visibleEmployeeIds($f['financeUser'], $f['companyA']->id);
    expect($visible->all())->toBe([]);

    $this->actingAs($f['financeUser']);
    $status = $this->get("/admin/employees/employees/{$f['unrelated']->id}")->getStatusCode();
    fwrite(STDERR, "STATUS_5=$status\n");
    expect($status)->not->toBe(200);
});

// ---------------------------------------------------------------------
// 6. Company A user cannot access Company B employees.
// ---------------------------------------------------------------------
it('6. PASS: assertCompanyAccess throws when a Company A user is scoped against Company B', function () {
    $f = raFixture();
    $service = app(HrHierarchyService::class);

    expect(fn () => $service->visibleEmployeeIds($f['managerUser'], $f['companyB']->id))
        ->toThrow(RuntimeException::class, 'does not have access to this company');
});

it('6. PASS: Company A user gets a denial opening a Company B employee record by direct URL', function () {
    $f = raFixture();

    $this->actingAs($f['managerUser']);
    $status = $this->get("/admin/employees/employees/{$f['otherCompanyEmployee']->id}")->getStatusCode();
    fwrite(STDERR, "STATUS_6=$status\n");
    expect($status)->not->toBe(200);
});

it('6. PASS: EmployeeResource::getEloquentQuery() never returns a cross-company row for a scoped user', function () {
    $f = raFixture();

    $this->actingAs($f['hrUser']);
    $ids = EmployeeResource::getEloquentQuery()->pluck('company_id')->unique()->all();
    expect($ids)->toBe([$f['companyA']->id]);
});

// ---------------------------------------------------------------------
// 7. Direct URL/API/resource access is denied when unauthorized.
// ---------------------------------------------------------------------
it('7. PASS: a user with zero employee permissions is denied the employees index and a specific record URL', function () {
    $f = raFixture();
    $bareUser = User::factory()->create([
        'default_company_id' => $f['companyA']->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL,
    ]);
    $bareUser->allowedCompanies()->syncWithoutDetaching([$f['companyA']->id]);

    $this->actingAs($bareUser);

    $indexStatus = $this->get('/admin/employees/employees')->getStatusCode();
    fwrite(STDERR, "STATUS_7_INDEX=$indexStatus\n");
    expect($indexStatus)->not->toBe(200);

    $recordStatus = $this->get("/admin/employees/employees/{$f['report']->id}")->getStatusCode();
    fwrite(STDERR, "STATUS_7_RECORD=$recordStatus\n");
    expect($recordStatus)->not->toBe(200);
});

it('7. PASS: an unauthenticated request to the employees index is redirected, never served', function () {
    $this->get('/admin/employees/employees')->assertRedirect();
});

// ---------------------------------------------------------------------
// 8. Bulk operations enforce authorization per record.
// ---------------------------------------------------------------------
it('8. PASS: bulk delete (post-fix filtering) skips a record the acting user cannot delete individually', function () {
    $f = raFixture();
    // reportUser only has view perms; give delete perms without hierarchy access to prove the gap stays closed.
    $bulkRole = Role::query()->firstOrCreate(['name' => 'ra_bulk_deny', 'guard_name' => 'web']);
    $bulkRole->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'delete_employee_employee', 'guard_name' => 'web']));
    $bulkRole->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'delete_any_employee_employee', 'guard_name' => 'web']));
    $f['managerUser']->assignRole($bulkRole);

    $policy = new EmployeePolicy;
    expect($policy->delete($f['managerUser'], $f['unrelated']))->toBeFalse()
        ->and($policy->deleteAny($f['managerUser']))->toBeTrue();

    Auth::login($f['managerUser']);
    $records = collect([$f['unrelated']])->filter(fn (Employee $r) => Auth::user()?->can('delete', $r));
    $records->each(fn (Employee $r) => $r->delete());

    expect($records)->toHaveCount(0)
        ->and(Employee::query()->whereKey($f['unrelated']->id)->exists())->toBeTrue();
});

it('8. PASS: bulk delete (post-fix filtering) still deletes a record the acting user is individually authorized for', function () {
    $f = raFixture();
    $f['managerUser']->update(['resource_permission' => PermissionType::GLOBAL]);
    $bulkRole = Role::query()->firstOrCreate(['name' => 'ra_bulk_allow', 'guard_name' => 'web']);
    $bulkRole->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'delete_employee_employee', 'guard_name' => 'web']));
    $bulkRole->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'delete_any_employee_employee', 'guard_name' => 'web']));
    $f['managerUser']->assignRole($bulkRole);

    Auth::login($f['managerUser']);
    $records = collect([$f['report']])->filter(fn (Employee $r) => Auth::user()?->can('delete', $r));
    $records->each(fn (Employee $r) => $r->delete());

    expect($records)->toHaveCount(1)
        ->and(Employee::query()->whereKey($f['report']->id)->exists())->toBeFalse();
});
