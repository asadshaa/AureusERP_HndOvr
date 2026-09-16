<?php

/**
 * Server-side enforcement of "every hierarchy relationship an Employee
 * carries must belong to the same company" -- Employee::
 * assertHierarchyIsSameCompany(), called from a `saving` boot hook so it
 * applies on every write path (Filament, API, tinker), not only when the
 * Filament form happens to scope its Select options correctly.
 *
 * Found while implementing this: three of the six form fields
 * (department_id/team_id/parent_id) were already scoped to the current
 * user's company in the Filament UI, but that was a UI convenience only
 * (rule: authorization must be enforced server-side, not just by hiding UI
 * elements) -- nothing stopped a cross-company id reaching the model
 * directly, and job_id/work_location_id were not even UI-scoped.
 */

use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function onboardingUser(Company $company): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

/**
 * A minimal, valid employee: everything the hierarchy checks care about
 * (company/department/team/job/work location/manager) resolved to the
 * SAME company. Individual tests override one relation at a time to a
 * different company to prove it is rejected.
 */
function baseOnboardingFixture(): array
{
    $company = Company::factory()->create(['is_active' => true]);
    $otherCompany = Company::factory()->create(['is_active' => true]);

    // The three explicit overrides below (manager_id/department_id/
    // location_type) sidestep two unrelated pre-existing bugs found while
    // writing this test, neither of which belongs to Employee onboarding:
    // (1) DepartmentFactory's own default manager_id is Employee::factory(),
    // and EmployeeJobPositionFactory's own default department_id is
    // Department::factory() -- so calling either without an override
    // recurses into a nested Employee::factory()->User::factory() chain
    // that runs inside Laravel's Model::unguarded() (used internally while
    // resolving nested factory relationships), which bypasses Partner's
    // $fillable allowlist and attempts to insert the User's
    // default_company_id column into partners_partners -- a column that
    // does not exist there (Webkul\Security\Models\User::
    // handlePartnerCreation() spreads the User's entire toArray() onto the
    // Partner create() call). (2) WorkLocationFactory's own default
    // location_type is fake()->word, unconstrained against the
    // Webkul\Employee\Enums\WorkLocation backing values (home/office/other).
    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);
    $team = Team::query()->create(['name' => 'Onboarding Team', 'company_id' => $company->id, 'is_active' => true]);
    $job = EmployeeJobPosition::factory()->create(['company_id' => $company->id, 'department_id' => null]);
    $location = WorkLocation::factory()->create(['company_id' => $company->id, 'location_type' => 'office']);
    $manager = Employee::query()->create([
        'company_id' => $company->id,
        'name'       => 'Manager',
        'work_email' => 'manager-'.uniqid().'@example.test',
    ]);
    $user = onboardingUser($company);

    return compact('company', 'otherCompany', 'department', 'team', 'job', 'location', 'manager', 'user');
}

it('onboards a new hire with a fully consistent single-company hierarchy', function () {
    $f = baseOnboardingFixture();

    $employee = Employee::query()->create([
        'company_id'        => $f['company']->id,
        'user_id'           => $f['user']->id,
        'department_id'     => $f['department']->id,
        'team_id'           => $f['team']->id,
        'job_id'            => $f['job']->id,
        'work_location_id'  => $f['location']->id,
        'parent_id'         => $f['manager']->id,
        'employee_type'     => 'full-time',
        'employment_status' => 'active',
        'joining_date'      => now()->toDateString(),
        'name'              => 'New Hire',
        'work_email'        => 'new-hire-'.uniqid().'@example.test',
    ]);

    expect($employee->wasRecentlyCreated)->toBeTrue()
        ->and($employee->company_id)->toBe($f['company']->id)
        ->and($employee->department_id)->toBe($f['department']->id)
        ->and($employee->employment_status)->toBe('active');

    // The record must appear in the manager's own reporting tree.
    expect($f['manager']->directReports()->pluck('id'))->toContain($employee->id);

    // Onboarding creates the record already "active" -- boot() writes the
    // first EmployeeStatusHistory row automatically, so activation isn't a
    // separate step someone has to remember to perform.
    $history = $employee->statusHistory()->first();
    expect($history)->not->toBeNull()
        ->and($history->status)->toBe('active')
        ->and($history->company_id)->toBe($f['company']->id);
});

it('refuses a department from a different company than the employee', function () {
    $f = baseOnboardingFixture();
    $wrongDepartment = Department::factory()->create(['company_id' => $f['otherCompany']->id, 'manager_id' => null]);

    expect(fn () => Employee::query()->create([
        'company_id'    => $f['company']->id,
        'department_id' => $wrongDepartment->id,
        'name'          => 'Bad Hire',
    ]))->toThrow(InvalidArgumentException::class, 'Department belongs to a different company');

    expect(Employee::query()->where('name', 'Bad Hire')->exists())->toBeFalse();
});

it('refuses a team from a different company than the employee', function () {
    $f = baseOnboardingFixture();
    $wrongTeam = Team::query()->create(['name' => 'Other Co Team', 'company_id' => $f['otherCompany']->id, 'is_active' => true]);

    expect(fn () => Employee::query()->create([
        'company_id' => $f['company']->id,
        'team_id'    => $wrongTeam->id,
        'name'       => 'Bad Hire',
    ]))->toThrow(InvalidArgumentException::class, 'Team belongs to a different company');
});

it('refuses a job position from a different company than the employee', function () {
    $f = baseOnboardingFixture();
    $wrongJob = EmployeeJobPosition::factory()->create(['company_id' => $f['otherCompany']->id, 'department_id' => null]);

    expect(fn () => Employee::query()->create([
        'company_id' => $f['company']->id,
        'job_id'     => $wrongJob->id,
        'name'       => 'Bad Hire',
    ]))->toThrow(InvalidArgumentException::class, 'Job position belongs to a different company');
});

it('refuses a work location from a different company than the employee', function () {
    $f = baseOnboardingFixture();
    $wrongLocation = WorkLocation::factory()->create(['company_id' => $f['otherCompany']->id, 'location_type' => 'office']);

    expect(fn () => Employee::query()->create([
        'company_id'       => $f['company']->id,
        'work_location_id' => $wrongLocation->id,
        'name'             => 'Bad Hire',
    ]))->toThrow(InvalidArgumentException::class, 'Work location belongs to a different company');
});

it('refuses a manager from a different company than the employee', function () {
    $f = baseOnboardingFixture();
    $wrongManager = Employee::query()->create([
        'company_id' => $f['otherCompany']->id,
        'name'       => 'Manager Elsewhere',
    ]);

    expect(fn () => Employee::query()->create([
        'company_id' => $f['company']->id,
        'parent_id'  => $wrongManager->id,
        'name'       => 'Bad Hire',
    ]))->toThrow(InvalidArgumentException::class, 'Manager belongs to a different company');
});

it('refuses a coach from a different company than the employee', function () {
    $f = baseOnboardingFixture();
    $wrongCoach = Employee::query()->create([
        'company_id' => $f['otherCompany']->id,
        'name'       => 'Coach Elsewhere',
    ]);

    expect(fn () => Employee::query()->create([
        'company_id' => $f['company']->id,
        'coach_id'   => $wrongCoach->id,
        'name'       => 'Bad Hire',
    ]))->toThrow(InvalidArgumentException::class, 'Coach belongs to a different company');
});

it('refuses linking a user with no access to the employee\'s company', function () {
    $f = baseOnboardingFixture();
    $outsiderUser = onboardingUser($f['otherCompany']);

    expect(fn () => Employee::query()->create([
        'company_id' => $f['company']->id,
        'user_id'    => $outsiderUser->id,
        'name'       => 'Bad Hire',
    ]))->toThrow(InvalidArgumentException::class, 'linked user does not have access');
});

it('allows linking a user via a secondary allowed company, not only the default one', function () {
    $f = baseOnboardingFixture();
    $multiCompanyUser = User::factory()->create(['default_company_id' => $f['otherCompany']->id, 'is_active' => true]);
    // Access to $f['company'] via the pivot, even though it is not their
    // default -- mirrors HrHierarchyService::assertCompanyAccess()'s own
    // default_company_id OR allowedCompanies() rule.
    $multiCompanyUser->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);

    $employee = Employee::query()->create([
        'company_id' => $f['company']->id,
        'user_id'    => $multiCompanyUser->id,
        'name'       => 'Multi-Company Hire',
    ]);

    expect($employee->user_id)->toBe($multiCompanyUser->id);
});

it('enforces the same rule on update, not only on create', function () {
    $f = baseOnboardingFixture();
    $employee = Employee::query()->create([
        'company_id' => $f['company']->id,
        'name'       => 'Existing Hire',
    ]);
    $wrongDepartment = Department::factory()->create(['company_id' => $f['otherCompany']->id, 'manager_id' => null]);

    expect(fn () => $employee->update(['department_id' => $wrongDepartment->id]))
        ->toThrow(InvalidArgumentException::class, 'Department belongs to a different company');

    expect($employee->fresh()->department_id)->toBeNull();
});

it('allows relations left null and does not require every hierarchy field to be set', function () {
    $f = baseOnboardingFixture();

    $employee = Employee::query()->create([
        'company_id' => $f['company']->id,
        'name'       => 'Bare Hire',
    ]);

    expect($employee->wasRecentlyCreated)->toBeTrue()
        ->and($employee->department_id)->toBeNull()
        ->and($employee->parent_id)->toBeNull();
});

it('exposes an employment type select on the onboarding form using the column\'s actual string values', function () {
    // employees_employees.employee_type is a plain string column
    // (migration: string('employee_type')->default('employee')) -- NOT an
    // integer FK, despite Employee::employmentType() declaring a belongsTo
    // into employees_employment_types. That relation is non-functional (it
    // would compare the id column to a string like 'full-time'). This
    // asserts the form field added for onboarding writes the column
    // directly rather than going through that broken relationship.
    $f = baseOnboardingFixture();

    $employee = Employee::query()->create([
        'company_id'    => $f['company']->id,
        'name'          => 'Contractor Hire',
        'employee_type' => 'contractor',
    ]);

    expect($employee->fresh()->employee_type)->toBe('contractor')
        ->and(DB::table('employees_employees')->where('id', $employee->id)->value('employee_type'))->toBe('contractor');
});
