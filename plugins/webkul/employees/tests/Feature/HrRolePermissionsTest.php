<?php

/**
 * Focused tests for the HR role catalogue, mirroring
 * FinanceRolePermissionsTest.php's structure. Deliberately self-contained
 * (its own fixture helpers below, distinctly named) rather than reusing
 * HrPlatformTest.php's hrPlatformUser()/hrPlatformEmployee() -- those are
 * only defined when that file happens to also be loaded in the same Pest
 * run, which running this file standalone (e.g. `pest
 * HrRolePermissionsTest.php`) does not do.
 */

use Database\Seeders\HrRoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Filament\Resources\EmployeeRequestTypeResource;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Services\Security\HrPermissionRegistrar;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function seedHrRoles(): void
{
    app(HrRoleSeeder::class)->run();
}

function hrRoleTestUser(Company $company): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

function hrRoleTestEmployee(Company $company, User $user, string $name, ?Employee $manager = null, ?Department $department = null): Employee
{
    return Employee::query()->create([
        'company_id'        => $company->id,
        'user_id'           => $user->id,
        'department_id'     => $department?->id,
        'parent_id'         => $manager?->id,
        'name'              => $name,
        'work_email'        => $user->email,
        'employee_number'   => 'HRT-'.$company->id.'-'.$user->id,
        'employment_status' => 'active',
        'is_active'         => true,
    ]);
}

it('creates every genuinely-missing HR role exactly once', function (): void {
    seedHrRoles();

    $expectedNames = [
        'hr_manager', 'hr_administrator', 'hr_ops_manager', 'hr_officer',
        'sensitive_data_custodian', 'recruiter', 'hiring_manager', 'hr_auditor',
    ];

    foreach ($expectedNames as $name) {
        expect(Role::query()->where('guard_name', 'web')
            ->whereRaw('LOWER(name) = ?', [$name])->count())->toBe(1);
    }
});

it('grants the role literally named "hr_manager" the complete HrPermissions::all() bundle -- the single HR-functionality owner, distinct from ERP Administrator', function (): void {
    seedHrRoles();

    $role = Role::query()->whereRaw('LOWER(name) = ?', ['hr_manager'])->firstOrFail();
    $grantedCount = DB::table('role_has_permissions')->where('role_id', $role->id)->count();

    expect($grantedCount)->toBe(count(HrPermissions::all()));

    // Spot-check a few permissions spanning different HR plugins/capabilities,
    // not just the count -- a wrong bundle could coincidentally match the count.
    $grantedNames = DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_has_permissions.role_id', $role->id)
        ->pluck('permissions.name');

    expect($grantedNames)->toContain(HrPermissions::ViewAllRecords)
        ->toContain(HrPermissions::ViewSensitiveEmployeeData)
        ->toContain(HrPermissions::ApproveLeave)
        ->toContain('create_employee_employee')
        ->toContain('view_any_time_off_time::off')
        ->toContain('view_any_recruitment_applicant')
        ->toContain('view_any_timesheet_timesheet');
});

it('an hr_manager user sees every employee in their company via HrHierarchyService, same as ViewAllRecords implies', function (): void {
    seedHrRoles();
    $company = Company::factory()->create(['currency_id' => Currency::query()->where('code', 'PKR')->value('id'), 'is_active' => true]);

    $hrManagerUser = hrRoleTestUser($company);
    $hrManagerUser->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['hr_manager'])->firstOrFail());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    hrRoleTestEmployee($company, hrRoleTestUser($company), 'Someone Else');
    hrRoleTestEmployee($company, hrRoleTestUser($company), 'Someone Else Too');

    $visible = app(HrHierarchyService::class)->visibleEmployeeIds($hrManagerUser, $company->id);
    $allCompanyIds = Employee::query()->where('company_id', $company->id)->pluck('id')->sort()->values()->all();

    expect($visible->sort()->values()->all())->toBe($allCompanyIds);
});

it('is idempotent: running the seeder twice creates no duplicate roles or permission grants', function (): void {
    seedHrRoles();
    $roleCountAfterFirst = Role::query()->where('guard_name', 'web')->count();
    $pivotCountAfterFirst = DB::table('role_has_permissions')->count();

    seedHrRoles();

    expect(Role::query()->where('guard_name', 'web')->count())->toBe($roleCountAfterFirst)
        ->and(DB::table('role_has_permissions')->count())->toBe($pivotCountAfterFirst);
});

it('gives HR Administrator config-CRUD permissions but no approvals, ViewAllRecords, or sensitive-data access', function (): void {
    seedHrRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['hr_administrator'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain('create_employee_department')
        ->and($names)->toContain('create_employee_job::position')
        ->and($names)->not->toContain(HrPermissions::ApproveLeave)
        ->and($names)->not->toContain(HrPermissions::ApproveTimesheets)
        ->and($names)->not->toContain(HrPermissions::ViewAllRecords)
        ->and($names)->not->toContain(HrPermissions::ViewSensitiveEmployeeData)
        ->and($names)->not->toContain(HrPermissions::ManageSensitiveEmployeeData);
});

it('gives HR Operations Manager ViewAllRecords and approval authority but not sensitive-data access', function (): void {
    seedHrRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['hr_ops_manager'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain(HrPermissions::ViewAllRecords)
        ->and($names)->toContain(HrPermissions::ApproveLeave)
        ->and($names)->toContain(HrPermissions::ApproveTimesheets)
        ->and($names)->not->toContain(HrPermissions::ViewSensitiveEmployeeData)
        ->and($names)->not->toContain(HrPermissions::ManageSensitiveEmployeeData);
});

it('gives HR Officer basic employee record access but no approvals, no ViewAllRecords, no sensitive data', function (): void {
    seedHrRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['hr_officer'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain('create_employee_employee')
        ->and($names)->not->toContain(HrPermissions::ApproveLeave)
        ->and($names)->not->toContain(HrPermissions::ApproveTimesheets)
        ->and($names)->not->toContain(HrPermissions::ViewAllRecords)
        ->and($names)->not->toContain(HrPermissions::ViewSensitiveEmployeeData);
});

it('gives Recruiter applicant/candidate CRUD but withholds ConvertCandidates', function (): void {
    seedHrRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['recruiter'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain('create_recruitment_applicant')
        ->and($names)->toContain('create_recruitment_candidate')
        ->and($names)->not->toContain(HrPermissions::ConvertCandidates);
});

it('gives Hiring Manager everything Recruiter has plus ConvertCandidates', function (): void {
    seedHrRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['hiring_manager'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain('create_recruitment_applicant')
        ->and($names)->toContain(HrPermissions::ConvertCandidates);
});

it('gives Sensitive-Data Custodian narrow sensitive-field access only', function (): void {
    seedHrRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['sensitive_data_custodian'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain(HrPermissions::ViewSensitiveEmployeeData)
        ->and($names)->toContain(HrPermissions::ManageSensitiveEmployeeData)
        ->and($names)->not->toContain(HrPermissions::ApproveLeave)
        ->and($names)->not->toContain('create_employee_department');
});

it('gives HR Auditor read-only access across HR with zero write permission of any kind', function (): void {
    seedHrRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['hr_auditor'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    $writeVerbs = ['create_', 'update_', 'delete_', 'manage_', 'approve_', 'convert_'];

    expect($names)->toContain(HrPermissions::ViewAllRecords)
        ->and($names)->toContain(HrPermissions::ViewSensitiveEmployeeData)
        ->and($names)->not->toContain(HrPermissions::ManageSensitiveEmployeeData)
        ->and($names->filter(fn (string $n) => collect($writeVerbs)->contains(fn (string $verb) => str_starts_with($n, $verb)))->isEmpty())->toBeTrue();
});

it('closes the AttendanceRecordResource/PerformanceCycleResource/PerformanceReviewResource/EmployeeRequestTypeResource read-only visibility gap for HR Auditor', function (): void {
    // Same root cause and fix as the finance-roles ManualAdjustmentResource
    // gap: these four resources' canViewAny() previously accepted ONLY
    // their "Manage*" permission, so a genuinely read-only role got a 403
    // despite holding the generic Shield view permission (which these
    // resources ignore).
    seedHrRoles();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $auditorUser = hrRoleTestUser($company);
    $auditorUser->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['hr_auditor'])->firstOrFail());

    $officerUser = hrRoleTestUser($company);
    $officerUser->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['hr_officer'])->firstOrFail());

    test()->actingAs($auditorUser);
    expect(AttendanceRecordResource::canViewAny())->toBeTrue()
        ->and(PerformanceCycleResource::canViewAny())->toBeTrue()
        ->and(PerformanceReviewResource::canViewAny())->toBeTrue()
        ->and(EmployeeRequestTypeResource::canViewAny())->toBeTrue();

    // HR Officer has neither Manage* nor View* for these -- genuinely not
    // part of its role -- so all four must stay 403.
    test()->actingAs($officerUser);
    expect(AttendanceRecordResource::canViewAny())->toBeFalse()
        ->and(PerformanceCycleResource::canViewAny())->toBeFalse()
        ->and(PerformanceReviewResource::canViewAny())->toBeFalse()
        ->and(EmployeeRequestTypeResource::canViewAny())->toBeFalse();
});

it('enforces the ViewAllRecords hierarchy boundary: HR Officer sees only their own reporting tree, HR Ops Manager sees everyone', function (): void {
    // HrHierarchyService::visibleEmployeeIds() is the real access-control
    // mechanism for most HR screens -- ViewAllRecords is the bypass.
    // Without it, a role is scoped to "me + my descendants + teams/
    // departments I manage", regardless of any other permission it holds.
    seedHrRoles();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $officerUser = hrRoleTestUser($company);
    $officerUser->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['hr_officer'])->firstOrFail());
    $officerEmployee = hrRoleTestEmployee($company, $officerUser, 'HR Officer Employee');

    $opsManagerUser = hrRoleTestUser($company);
    $opsManagerUser->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['hr_ops_manager'])->firstOrFail());
    hrRoleTestEmployee($company, $opsManagerUser, 'HR Ops Manager Employee');

    $unrelatedUser = hrRoleTestUser($company);
    $unrelatedEmployee = hrRoleTestEmployee($company, $unrelatedUser, 'Unrelated Employee');

    $hierarchy = app(HrHierarchyService::class);

    // HR Officer (no ViewAllRecords): sees only itself, not the unrelated employee.
    expect($hierarchy->visibleEmployeeIds($officerUser, $company->id))->toContain($officerEmployee->id)
        ->and($hierarchy->visibleEmployeeIds($officerUser, $company->id))->not->toContain($unrelatedEmployee->id);

    // HR Ops Manager (has ViewAllRecords): sees everyone in the company.
    expect($hierarchy->visibleEmployeeIds($opsManagerUser, $company->id))->toContain($unrelatedEmployee->id)
        ->and($hierarchy->visibleEmployeeIds($opsManagerUser, $company->id))->toContain($officerEmployee->id);
});

it('redirects to the Employees list after creating an employee, not a view page the creator may not be able to see', function (): void {
    // Found live during manual testing: CreateEmployee used to redirect
    // to the 'view' route unconditionally, which 404s for a role without
    // ViewAllRecords (e.g. HR Officer) when the new hire isn't
    // automatically placed under the creator in HrHierarchyService's
    // reporting tree. 'index' is always reachable by anyone who can see
    // this screen at all.
    seedHrRoles();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $officerUser = hrRoleTestUser($company);
    $officerUser->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['hr_officer'])->firstOrFail());
    hrRoleTestEmployee($company, $officerUser, 'HR Officer Employee');

    $page = new CreateEmployee;
    $redirect = (new ReflectionMethod($page, 'getRedirectUrl'))->invoke($page);

    expect($redirect)->toBe(EmployeeResource::getUrl('index'));
});

it('re-running the registrar synchronize() does not grant new-role permissions to the pre-existing full-access hr tier beyond its own bundle', function (): void {
    seedHrRoles();
    $registrar = app(HrPermissionRegistrar::class);
    $result = $registrar->synchronize();

    expect($result['hr_role_grants'])->toHaveKey('hr_auditor')
        ->and($result['hr_role_grants']['hr_auditor'])->toBeGreaterThanOrEqual(1)
        ->and($result['hr_role_grants']['sensitive_data_custodian'])->toBeGreaterThanOrEqual(1);
});
