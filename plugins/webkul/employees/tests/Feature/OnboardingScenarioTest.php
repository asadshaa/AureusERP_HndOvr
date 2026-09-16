<?php

/**
 * The 8-step onboarding verification scenario, run against real system
 * behavior -- not the intended behavior. Two genuine defects were found
 * while writing this and are asserted on directly (tests 5a/5b), rather
 * than glossed over:
 *
 *   1. EmployeePolicy::update() checks hasAccess($user, $employee, 'coach')
 *      -- but the "manager" a person actually reports to is recorded via
 *      Employee::parent (parent_id), not Employee::coach (coach_id), a
 *      separate and usually-unset field. So a manager holding
 *      update_employee_employee cannot update their own direct report.
 *
 *   2. Even when coach_id IS set, Employee::coach() returns an Employee,
 *      while HasScopedPermissions::hasAccess() compares $owner->id ===
 *      $user->id -- an Employee primary key against a User primary key.
 *      These are different id sequences; in practice they never
 *      legitimately match.
 *
 * Net effect, confirmed empirically (not assumed): unless a user's
 * resource_permission is PermissionType::GLOBAL -- a value nothing in HR
 * ever sets -- EmployeePolicy::update() denies everyone, including
 * legitimate managers. "Unauthorized modification is denied" is true, but
 * so is "authorized modification is also denied," which is a functional
 * defect, not a security feature. Reported, not silently fixed: this task
 * asked to run and report, not to patch unrelated authorization code.
 *
 * Sensitive-field protection (item 4) is UI-rendering only: EmployeeResource
 * gates each salary/bank/personal field with a bare ->visible(fn () =>
 * Auth::user()?->can('hr_view_sensitive_employee_data')) closure. There is
 * no attribute-level guard on the Employee model itself -- anyone who can
 * view the record at all can read every column via Eloquent/API/tinker
 * regardless of that permission. Tested here at the layer that actually
 * enforces it (the permission gate the closures read), with that boundary
 * stated plainly rather than implied to be a data-layer guard.
 */

use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Policies\EmployeePolicy;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function scenarioCompany(): Company
{
    return Company::factory()->create(['is_active' => true]);
}

function scenarioUser(Company $company): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

/** A same-company department/job/location trio, sidestepping the three
 * pre-existing factory bugs fixed in EmployeeOnboardingTest.php (see that
 * file's header for the full explanation): DepartmentFactory's own default
 * manager_id and EmployeeJobPositionFactory's own default department_id
 * each resolve to Employee::factory(), which recurses through a nested
 * User::factory() create() call executed inside Laravel's factory-relation
 * Model::unguarded() window -- bypassing Partner's $fillable allowlist and
 * crashing on a column (default_company_id) that does not exist on
 * partners_partners. WorkLocationFactory's own default location_type is
 * unconstrained fake()->word against a real backing enum.
 */
function scenarioHierarchy(Company $company): array
{
    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);
    $team = Team::query()->create(['name' => 'Scenario Team', 'company_id' => $company->id, 'is_active' => true]);
    $job = EmployeeJobPosition::factory()->create(['company_id' => $company->id, 'department_id' => null]);
    $location = WorkLocation::factory()->create(['company_id' => $company->id, 'location_type' => 'office']);

    return compact('department', 'team', 'job', 'location');
}

function grantPermission(Role $role, string $name): void
{
    $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));

    // Spatie's permission cache is a single process-wide snapshot, not
    // scoped per check. If anything earlier in a test already called
    // ->can() (e.g. HrHierarchyService::visibleEmployeeIds() does,
    // internally), that snapshot is now stale for this new grant --
    // confirmed empirically: without this, a role granted a permission
    // AFTER an earlier unrelated ->can() call still reports false. This
    // mirrors what HrPermissionRegistrar::synchronize() already does in
    // production for the same reason.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

// ---------------------------------------------------------------------
// 1 & 2. Employee in Company A with valid hierarchy; Employee in Company B
// ---------------------------------------------------------------------

it('1. onboards an employee in Company A with a valid department/team/manager', function () {
    $companyA = scenarioCompany();
    $h = scenarioHierarchy($companyA);
    $manager = Employee::query()->create(['company_id' => $companyA->id, 'name' => 'Manager A']);

    $employee = Employee::query()->create([
        'company_id'        => $companyA->id,
        'department_id'     => $h['department']->id,
        'team_id'           => $h['team']->id,
        'job_id'            => $h['job']->id,
        'work_location_id'  => $h['location']->id,
        'parent_id'         => $manager->id,
        'employee_type'     => 'full-time',
        'employment_status' => 'active',
        'joining_date'      => now()->toDateString(),
        'name'              => 'Employee A',
    ]);

    expect($employee->wasRecentlyCreated)->toBeTrue()
        ->and($employee->company_id)->toBe($companyA->id)
        ->and($employee->department_id)->toBe($h['department']->id)
        ->and($employee->team_id)->toBe($h['team']->id)
        ->and($employee->job_id)->toBe($h['job']->id)
        ->and($employee->work_location_id)->toBe($h['location']->id)
        ->and($employee->parent_id)->toBe($manager->id);
});

it('2. onboards a separate employee in Company B, fully isolated from Company A', function () {
    $companyB = scenarioCompany();
    $h = scenarioHierarchy($companyB);

    $employee = Employee::query()->create([
        'company_id'    => $companyB->id,
        'department_id' => $h['department']->id,
        'name'          => 'Employee B',
    ]);

    expect($employee->wasRecentlyCreated)->toBeTrue()
        ->and($employee->company_id)->toBe($companyB->id);

    // Company A's employees list must not include this one.
    expect(Employee::query()->where('company_id', '!=', $companyB->id)->where('name', 'Employee B')->exists())
        ->toBeFalse();
});

// ---------------------------------------------------------------------
// 3. Cross-company manager assignment
// ---------------------------------------------------------------------

it('3. refuses assigning a manager from a different company', function () {
    $companyA = scenarioCompany();
    $companyB = scenarioCompany();
    $managerInB = Employee::query()->create(['company_id' => $companyB->id, 'name' => 'Manager B']);

    expect(fn () => Employee::query()->create([
        'company_id' => $companyA->id,
        'parent_id'  => $managerInB->id,
        'name'       => 'Cross-Company Hire',
    ]))->toThrow(InvalidArgumentException::class, 'Manager belongs to a different company');

    expect(Employee::query()->where('name', 'Cross-Company Hire')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------
// 4. Unauthorized sensitive-field access
// ---------------------------------------------------------------------

it('4a. denies the sensitive-data permission to a role that was not granted it', function () {
    $company = scenarioCompany();
    $role = Role::query()->firstOrCreate(['name' => 'scenario_plain_officer', 'guard_name' => 'web']);
    grantPermission($role, 'view_employee_employee');

    $user = scenarioUser($company);
    $user->assignRole($role);

    // This is the exact closure EmployeeResource's salary/bank/identity
    // fields use: ->visible(fn () => Auth::user()?->can('hr_view_sensitive_employee_data')).
    expect($user->can(HrPermissions::ViewSensitiveEmployeeData))->toBeFalse();
});

it('4b. grants sensitive-field visibility only to a role explicitly given it', function () {
    $company = scenarioCompany();
    $role = Role::query()->firstOrCreate(['name' => 'scenario_sensitive_custodian', 'guard_name' => 'web']);
    grantPermission($role, HrPermissions::ViewSensitiveEmployeeData);

    $user = scenarioUser($company);
    $user->assignRole($role);

    expect($user->can(HrPermissions::ViewSensitiveEmployeeData))->toBeTrue();

    // Boundary: this permission gates FORM FIELD VISIBILITY only. It is not
    // enforced on the Employee model itself -- direct Eloquent access to
    // salary_grade/base_salary/ssnid/etc. is NOT restricted by this
    // permission at all. Demonstrated, not asserted as "protected":
    $employee = Employee::query()->create(['company_id' => $company->id, 'name' => 'Has Salary', 'base_salary' => 90000]);
    $unauthorizedUser = scenarioUser($company);
    // No role assigned at all -- yet the raw attribute is still readable.
    expect($employee->fresh()->base_salary)->not->toBeNull();
});

// ---------------------------------------------------------------------
// 5. Unauthorized employee modification
// ---------------------------------------------------------------------

it('5a. denies modification outright to a user with no update permission', function () {
    $company = scenarioCompany();
    $employee = Employee::query()->create(['company_id' => $company->id, 'name' => 'Protected']);
    $user = scenarioUser($company); // no role, no permission at all

    expect((new EmployeePolicy)->update($user, $employee))->toBeFalse();
});

it('5b. [DEFECT, reported not fixed] also denies a real manager updating their own direct report', function () {
    // EmployeePolicy::update() checks hasAccess($user, $employee, 'coach'),
    // but Employee::coach() (coach_id) is a distinct, usually-unset field
    // from Employee::parent() (parent_id) -- the actual "Manager" the
    // onboarding form and HrHierarchyService both use for reporting-line
    // hierarchy. A manager relationship is recorded via parent_id, which
    // this policy never looks at.
    $company = scenarioCompany();
    $role = Role::query()->firstOrCreate(['name' => 'scenario_manager_role', 'guard_name' => 'web']);
    grantPermission($role, 'update_employee_employee');

    $managerUser = scenarioUser($company);
    $managerUser->assignRole($role);
    $managerEmployee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Real Manager']);
    $directReport = Employee::query()->create(['company_id' => $company->id, 'parent_id' => $managerEmployee->id, 'name' => 'Real Direct Report']);

    expect($managerUser->can('update_employee_employee'))->toBeTrue()
        ->and($directReport->fresh()->parent_id)->toBe($managerEmployee->id) // the hierarchy link is genuinely there
        ->and((new EmployeePolicy)->update($managerUser, $directReport->fresh()))->toBeFalse(); // yet denied
});

it('5c. GLOBAL resource_permission is the only way EmployeePolicy::update() currently grants access', function () {
    $company = scenarioCompany();
    $role = Role::query()->firstOrCreate(['name' => 'scenario_global_role', 'guard_name' => 'web']);
    grantPermission($role, 'update_employee_employee');

    $employee = Employee::query()->create(['company_id' => $company->id, 'name' => 'Anyone']);
    $globalUser = User::factory()->create([
        'default_company_id'   => $company->id,
        'is_active'            => true,
        'resource_permission'  => PermissionType::GLOBAL,
    ]);
    $globalUser->assignRole($role);

    expect((new EmployeePolicy)->update($globalUser, $employee))->toBeTrue();
});

// ---------------------------------------------------------------------
// 6. Employee visibility according to hierarchy
// ---------------------------------------------------------------------

it('6. scopes visibility to the reporting tree unless ViewAllRecords is held', function () {
    $company = scenarioCompany();
    $hierarchy = app(HrHierarchyService::class);

    // The ViewAllRecords role/grant is created and assigned FIRST, before
    // any ->can()/visibleEmployeeIds() call happens in this test. Spatie's
    // permission cache is a single process-wide snapshot: an earlier
    // ->can() check (visibleEmployeeIds() makes one internally) warms it,
    // and calling forgetCachedPermissions() afterward was NOT sufficient
    // to un-stick it reliably here -- confirmed by direct diagnosis, not
    // assumed. Granting before any check is the simplest structure that
    // sidesteps the ordering hazard entirely, and is also the more natural
    // test order regardless.
    $viewAllRole = Role::query()->firstOrCreate(['name' => 'scenario_view_all_role', 'guard_name' => 'web']);
    grantPermission($viewAllRole, HrPermissions::ViewAllRecords);

    $managerUser = scenarioUser($company);
    $managerEmployee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Visibility Manager']);
    $directReport = Employee::query()->create(['company_id' => $company->id, 'parent_id' => $managerEmployee->id, 'name' => 'Visible Report']);
    $unrelated = Employee::query()->create(['company_id' => $company->id, 'name' => 'Invisible Peer']);

    $visible = $hierarchy->visibleEmployeeIds($managerUser, $company->id);

    expect($visible)->toContain($managerEmployee->id)
        ->and($visible)->toContain($directReport->id)
        ->and($visible)->not->toContain($unrelated->id);

    // With ViewAllRecords, everyone in the company becomes visible.
    $auditorUser = scenarioUser($company);
    $auditorUser->assignRole($viewAllRole);

    $allVisible = $hierarchy->visibleEmployeeIds($auditorUser, $company->id);
    expect($allVisible)->toContain($unrelated->id);
});

// ---------------------------------------------------------------------
// 7. Employee linked to a user only where required
// ---------------------------------------------------------------------

it('7a. onboards an employee with no application access at all -- user_id stays null', function () {
    $company = scenarioCompany();

    $employee = Employee::query()->create(['company_id' => $company->id, 'name' => 'No Login Needed']);

    expect($employee->wasRecentlyCreated)->toBeTrue()
        ->and($employee->user_id)->toBeNull();
});

it('7b. links a user only when access is granted, and only within the same company', function () {
    $company = scenarioCompany();
    $user = scenarioUser($company);

    $employee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'name' => 'Needs Login']);

    expect($employee->user_id)->toBe($user->id);
});

it('7c. refuses linking a user with no access to the employee\'s company', function () {
    $company = scenarioCompany();
    $otherCompany = scenarioCompany();
    $outsiderUser = scenarioUser($otherCompany);

    expect(fn () => Employee::query()->create([
        'company_id' => $company->id,
        'user_id'    => $outsiderUser->id,
        'name'       => 'Bad Link',
    ]))->toThrow(InvalidArgumentException::class, 'linked user does not have access');
});

// ---------------------------------------------------------------------
// 8. Status history retained / auditable
// ---------------------------------------------------------------------

it('8. [DEFECT, reported not fixed] retains status history, but creation writes it twice', function () {
    // Real, verified behavior: Employee::boot() writes the correct
    // 'created' history row (new_values only), but
    // handlePartnerCreation()'s nested $employee->save() -- setting
    // partner_id, called from INSIDE the 'saved' event of the original
    // create -- runs before Laravel's syncOriginal() has executed
    // (finishSave() calls syncOriginal() AFTER firing 'saved'). That
    // nested save's dirty-attribute diff therefore still sees
    // employment_status as changed from null, even though nothing in this
    // request actually changed it, so the 'updated' hook ALSO fires and
    // writes a second, spurious history row -- for every employee, on
    // every creation. Traced and confirmed via direct inspection of the
    // two rows' previous_values/new_values/created_at before writing this
    // assertion, rather than assumed.
    $company = scenarioCompany();

    $employee = Employee::query()->create([
        'company_id'        => $company->id,
        'name'              => 'Lifecycle',
        'employment_status' => 'probation',
        'joining_date'      => now()->subMonths(3)->toDateString(),
    ]);

    // Both rows land in the same request, milliseconds apart -- their
    // relative insertion order is not guaranteed, so this checks the SET
    // of shapes present rather than assuming which one comes first.
    $onCreate = $employee->statusHistory()->orderBy('id')->get();
    $previousValueShapes = $onCreate->map(fn ($row) => $row->previous_values)->all();

    expect($onCreate)->toHaveCount(2)
        ->and($onCreate->pluck('status')->unique()->all())->toBe(['probation']) // both rows agree on the actual status
        ->and($previousValueShapes)->toContain(null) // the genuine 'created' row
        ->and($previousValueShapes)->toContain(['employment_status' => null]); // the spurious extra row

    // From here on, real transitions behave correctly -- each ->update()
    // call is a single, deliberate save, so it writes exactly one row.
    $employee->update(['employment_status' => 'active']);
    $employee->update(['employment_status' => 'notice']);
    $employee->update(['employment_status' => 'resigned']);

    $history = $employee->statusHistory()->orderBy('id')->get();

    // The spurious creation-time row's exact insertion point relative to
    // the later ->update() calls is not guaranteed either (observed once
    // landing AFTER all three real transitions, not right after creation)
    // -- so this checks the multiset of recorded statuses, not their
    // position, plus that the FULL sequence of genuine transitions is
    // present somewhere in the history rather than lost or merged.
    expect($history)->toHaveCount(5) // 2 from creation + 3 genuine transitions
        ->and($history->pluck('status')->sort()->values()->all())
        ->toBe(['active', 'notice', 'probation', 'probation', 'resigned']);

    // Each genuine transition still records BOTH the previous and new
    // value -- not just the final state -- which is what makes it
    // auditable at all, the spurious duplicate notwithstanding. Found by
    // content (the actual resigned transition), not by position.
    $resignedTransition = $history->firstWhere('new_values', ['employment_status' => 'resigned']);

    expect($resignedTransition)->not->toBeNull()
        ->and($resignedTransition->previous_values)->toBe(['employment_status' => 'notice'])
        ->and($resignedTransition->company_id)->toBe($company->id);

    // No entry is ever deleted or overwritten when the status changes again.
    expect(Employee::find($employee->id)->statusHistory()->count())->toBe(5);
});
