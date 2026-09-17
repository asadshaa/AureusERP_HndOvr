<?php

/**
 * Section 6 ("IMPLEMENTATION SECTION 6 -- HR REPORTING & ANALYTICS") --
 * exercises the existing HrAnalyticsService::summary() and its Filament
 * page gate (page_employees_hr_analytics). No second analytics engine: this
 * only verifies what already existed, plus the four genuinely missing
 * report lines added here (performance *distribution* vs. just an average,
 * a claims/reimbursements breakdown using EmployeeRequestType's own
 * existing category values, employee movement/status via the existing
 * EmployeeStatusHistory audit trail, and a payroll-data-honesty flag so a
 * $0 sum over an all-NULL base_salary column doesn't read as a confirmed
 * zero payroll), plus drill-down ids on the grouped breakdowns.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;
use Webkul\Employee\Filament\Pages\HrAnalytics;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Models\EmployeeStatusHistory;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\HrAnalyticsService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function analyticsUser(Company $company, array $permissionNames, string $roleName): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($permissionNames as $name) {
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->assignRole($role);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

// ---------------------------------------------------------------------
// Company scope: two companies, fully isolated results.
// ---------------------------------------------------------------------
it('scopes every metric to the given company only', function () {
    $companyA = Company::factory()->create(['is_active' => true]);
    $companyB = Company::factory()->create(['is_active' => true]);

    $deptA = Department::factory()->create(['company_id' => $companyA->id, 'manager_id' => null]);
    Employee::query()->create(['company_id' => $companyA->id, 'department_id' => $deptA->id, 'name' => 'A1', 'is_active' => true, 'base_salary' => 5000]);
    Employee::query()->create(['company_id' => $companyA->id, 'department_id' => $deptA->id, 'name' => 'A2', 'is_active' => true]);

    $deptB = Department::factory()->create(['company_id' => $companyB->id, 'manager_id' => null]);
    Employee::query()->create(['company_id' => $companyB->id, 'department_id' => $deptB->id, 'name' => 'B1', 'is_active' => true, 'base_salary' => 9999]);

    $from = now()->startOfYear()->toDateString();
    $to = now()->endOfYear()->toDateString();
    $service = app(HrAnalyticsService::class);

    $resultA = $service->summary($companyA->id, $from, $to);
    $resultB = $service->summary($companyB->id, $from, $to);

    expect($resultA['headcount'])->toBe(2)
        ->and($resultB['headcount'])->toBe(1)
        ->and($resultA['monthly_payroll_cost'])->toBe(5000.0)
        ->and($resultB['monthly_payroll_cost'])->toBe(9999.0);
});

// ---------------------------------------------------------------------
// Headcount / department distribution, with drill-down department_id.
// ---------------------------------------------------------------------
it('reports headcount and department distribution with drill-down department ids', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $engineering = Department::factory()->create(['company_id' => $company->id, 'name' => 'Engineering', 'manager_id' => null]);
    Employee::query()->create(['company_id' => $company->id, 'department_id' => $engineering->id, 'name' => 'E1', 'is_active' => true]);
    Employee::query()->create(['company_id' => $company->id, 'department_id' => $engineering->id, 'name' => 'E2', 'is_active' => true]);
    Employee::query()->create(['company_id' => $company->id, 'department_id' => null, 'name' => 'Unassigned Person', 'is_active' => true]);
    Employee::query()->create(['company_id' => $company->id, 'department_id' => $engineering->id, 'name' => 'Inactive', 'is_active' => false]);

    $result = app(HrAnalyticsService::class)->summary($company->id, now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString());

    expect($result['headcount'])->toBe(3);
    $engineeringRow = collect($result['department_distribution'])->firstWhere('name', 'Engineering');
    expect($engineeringRow['total'])->toBe(2)
        ->and($engineeringRow['department_id'])->toBe($engineering->id);
    $unassignedRow = collect($result['department_distribution'])->firstWhere('name', 'Unassigned');
    expect($unassignedRow['total'])->toBe(1)
        ->and($unassignedRow['department_id'])->toBeNull();
});

// ---------------------------------------------------------------------
// Payroll honesty: distinguishes "no salary data" from a real $0.
// ---------------------------------------------------------------------
it('does not present a confirmed zero payroll when no employee has salary data recorded', function () {
    $company = Company::factory()->create(['is_active' => true]);
    Employee::query()->create(['company_id' => $company->id, 'name' => 'No Salary', 'is_active' => true]);

    $result = app(HrAnalyticsService::class)->summary($company->id, now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString());

    expect($result['monthly_payroll_cost'])->toBe(0.0)
        ->and($result['employees_with_salary_data'])->toBe(0);
});

it('reports a real payroll figure with a nonzero employees_with_salary_data count when salary data exists', function () {
    $company = Company::factory()->create(['is_active' => true]);
    Employee::query()->create(['company_id' => $company->id, 'name' => 'Paid', 'is_active' => true, 'base_salary' => 4000]);
    Employee::query()->create(['company_id' => $company->id, 'name' => 'Unrecorded', 'is_active' => true]);

    $result = app(HrAnalyticsService::class)->summary($company->id, now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString());

    expect($result['monthly_payroll_cost'])->toBe(4000.0)
        ->and($result['employees_with_salary_data'])->toBe(1);
});

// ---------------------------------------------------------------------
// Performance distribution (not just an average).
// ---------------------------------------------------------------------
it('reports a performance rating distribution, not only an average', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $cycle = PerformanceCycle::query()->create(['company_id' => $company->id, 'name' => 'Cycle', 'starts_on' => now(), 'ends_on' => now()->addMonth(), 'status' => 'active']);

    $e1 = Employee::query()->create(['company_id' => $company->id, 'name' => 'E1', 'is_active' => true]);
    $e2 = Employee::query()->create(['company_id' => $company->id, 'name' => 'E2', 'is_active' => true]);
    $e3 = Employee::query()->create(['company_id' => $company->id, 'name' => 'E3', 'is_active' => true]);

    PerformanceReview::query()->create(['company_id' => $company->id, 'cycle_id' => $cycle->id, 'employee_id' => $e1->id, 'status' => 'completed', 'manager_rating' => 4]);
    PerformanceReview::query()->create(['company_id' => $company->id, 'cycle_id' => $cycle->id, 'employee_id' => $e2->id, 'status' => 'completed', 'manager_rating' => 4]);
    PerformanceReview::query()->create(['company_id' => $company->id, 'cycle_id' => $cycle->id, 'employee_id' => $e3->id, 'status' => 'completed', 'manager_rating' => 5]);

    $result = app(HrAnalyticsService::class)->summary($company->id, now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString());

    expect($result['average_performance'])->toBe(4.33);
    $distribution = collect($result['performance_distribution'])->keyBy('rating');
    expect($distribution[4]['total'])->toBe(2)
        ->and($distribution[5]['total'])->toBe(1);
});

// ---------------------------------------------------------------------
// Claims/reimbursements breakdown, distinct from the generic financial total.
// ---------------------------------------------------------------------
it('reports claims and reimbursements separately from the generic financial-request total', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $employee = Employee::query()->create(['company_id' => $company->id, 'name' => 'E1', 'is_active' => true]);

    $reimbursementType = EmployeeRequestType::query()->create([
        'company_id'            => $company->id, 'code' => 'reimb', 'name' => 'Reimbursement', 'category' => 'reimbursement',
        'approval_request_type' => 'reimb', 'is_financial' => true,
    ]);
    $otherFinancialType = EmployeeRequestType::query()->create([
        'company_id'            => $company->id, 'code' => 'advance', 'name' => 'Salary Advance', 'category' => 'advance',
        'approval_request_type' => 'advance', 'is_financial' => true,
    ]);

    $now = now();
    EmployeeRequest::query()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'request_type_id' => $reimbursementType->id,
        'title'      => 'Travel reimbursement', 'status' => 'approved', 'amount' => 150, 'approved_at' => $now,
    ]);
    EmployeeRequest::query()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'request_type_id' => $otherFinancialType->id,
        'title'      => 'Advance', 'status' => 'approved', 'amount' => 1000, 'approved_at' => $now,
    ]);

    $result = app(HrAnalyticsService::class)->summary($company->id, $now->copy()->subDay()->toDateString(), $now->copy()->addDay()->toDateString());

    expect($result['claims_and_reimbursements']['count'])->toBe(1)
        ->and($result['claims_and_reimbursements']['amount'])->toBe(150.0)
        ->and($result['approved_financial_requests'])->toBe(1150.0);
});

// ---------------------------------------------------------------------
// Employee movement/status, via the existing EmployeeStatusHistory audit trail.
// ---------------------------------------------------------------------
it('reports employee movement/status from the existing status history audit trail', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $employee = Employee::query()->create(['company_id' => $company->id, 'name' => 'E1', 'is_active' => true]);
    $now = now();

    // Employee::create() itself already writes one initial status-history row
    // via the model's own saved() hook -- a genuine activation event, so it's
    // correctly counted as movement too, not just the two we add explicitly.
    $baselineActive = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->where('status', 'active')->count();

    EmployeeStatusHistory::query()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'status' => 'active', 'effective_date' => $now]);
    EmployeeStatusHistory::query()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'status' => 'terminated', 'effective_date' => $now]);

    $result = app(HrAnalyticsService::class)->summary($company->id, $now->copy()->subDay()->toDateString(), $now->copy()->addDay()->toDateString());

    $movement = collect($result['employee_movement'])->keyBy('status');
    expect($movement['active']['total'])->toBe($baselineActive + 1)
        ->and($movement['terminated']['total'])->toBe(1);
});

// ---------------------------------------------------------------------
// Permission gating: the HR Analytics page is denied to unauthorized
// finance/operational roles, and allowed to a genuine ViewAnalytics holder.
// ---------------------------------------------------------------------
it('denies the HR Analytics page to a real finance role (controller) with no HR analytics permission', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $financeRole = Role::query()->firstOrCreate(['name' => 'controller', 'guard_name' => 'web']);
    app(AccountingPermissionRegistrar::class)->synchronize();

    $financeUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $financeUser->assignRole($financeRole);
    $financeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($financeUser->can(HrPermissions::ViewAnalytics))->toBeFalse();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($financeUser);

    expect(HrAnalytics::canAccess())->toBeFalse();

    $status = test()->get('/admin/hr-analytics')->getStatusCode();
    expect($status)->not->toBe(200);
});

it('allows the HR Analytics page to a user holding hr_view_analytics (ManagePerformance-equivalent HR tier)', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $hrUser = analyticsUser($company, [HrPermissions::ViewAnalytics], 'analytics_hr_'.uniqid());

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($hrUser);

    expect(HrAnalytics::canAccess())->toBeTrue();

    $status = test()->get('/admin/hr-analytics')->getStatusCode();
    expect($status)->toBe(200);
});

it('confirms the HR Analytics page pulls data scoped to Auth::user() default_company_id, not a hardcoded or request-supplied company', function () {
    $companyA = Company::factory()->create(['is_active' => true]);
    $companyB = Company::factory()->create(['is_active' => true]);
    Employee::query()->create(['company_id' => $companyA->id, 'name' => 'A', 'is_active' => true]);
    Employee::query()->create(['company_id' => $companyB->id, 'name' => 'B1', 'is_active' => true]);
    Employee::query()->create(['company_id' => $companyB->id, 'name' => 'B2', 'is_active' => true]);

    $hrUser = analyticsUser($companyB, [HrPermissions::ViewAnalytics], 'analytics_hr2_'.uniqid());
    Auth::login($hrUser);

    $page = app(HrAnalytics::class);
    $page->mount();
    $method = new ReflectionMethod($page, 'getViewData');
    $method->setAccessible(true);
    $data = $method->invoke($page);

    expect($data['analytics']['headcount'])->toBe(2);
});
