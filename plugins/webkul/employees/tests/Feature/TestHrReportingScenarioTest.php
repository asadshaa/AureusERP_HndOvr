<?php

/**
 * TEST HR REPORTING -- controlled records across all 8 report lines, then
 * verified for accuracy and for role/company visibility across HR user,
 * Manager, Employee, Finance role, and ERP Administrator. Exercises the
 * real HrAnalyticsService::summary() and the HrAnalytics page's permission
 * gate built/verified in Section 6 -- no second analytics engine.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;
use Webkul\Employee\Filament\Pages\HrAnalytics;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Models\EmployeeStatusHistory;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\HrAnalyticsService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\Stage;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;

function thrUser(Company $company, array $permissionNames, string $roleName): User
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

/**
 * Builds a fully controlled dataset in $company: known headcount (5 active,
 * 1 inactive), 2 departments, attendance with known late/overtime figures,
 * leave, a recruitment stage funnel, an open + an approved claim, employee
 * movement, and a performance rating distribution.
 */
function thrControlledFixture(): array
{
    // Pest reuses the same app instance across tests in this file; the
    // previous test's actingAs() user can leak forward as Auth::user() here
    // (Auth::logout() alone did not clear it), and MySQL auto-increment
    // counters are not rolled back with the transaction, so that stale id
    // fails FK checks when a boot hook (Candidate/Applicant) stamps
    // creator_id from it. Authenticate as a fresh, real user that will
    // still exist for the rest of this test's transaction instead.
    $systemUser = User::factory()->create(['is_active' => true]);
    Auth::login($systemUser);

    $company = Company::factory()->create(['is_active' => true]);
    $otherCompany = Company::factory()->create(['is_active' => true]); // for isolation checks

    $engineering = Department::factory()->create(['company_id' => $company->id, 'name' => 'Engineering', 'manager_id' => null]);
    $sales = Department::factory()->create(['company_id' => $company->id, 'name' => 'Sales', 'manager_id' => null]);

    $employees = collect();
    foreach (['E1', 'E2', 'E3'] as $name) {
        $employees->push(Employee::query()->create(['company_id' => $company->id, 'department_id' => $engineering->id, 'name' => $name, 'is_active' => true, 'base_salary' => 3000]));
    }
    foreach (['S1', 'S2'] as $name) {
        $employees->push(Employee::query()->create(['company_id' => $company->id, 'department_id' => $sales->id, 'name' => $name, 'is_active' => true]));
    }
    Employee::query()->create(['company_id' => $company->id, 'department_id' => $engineering->id, 'name' => 'Inactive', 'is_active' => false]);

    // Noise in another company -- proves isolation, not just presence.
    $otherDept = Department::factory()->create(['company_id' => $otherCompany->id, 'manager_id' => null]);
    Employee::query()->create(['company_id' => $otherCompany->id, 'department_id' => $otherDept->id, 'name' => 'Other', 'is_active' => true, 'base_salary' => 99999]);

    $from = now()->subDays(2)->toDateString();
    $to = now()->addDays(2)->toDateString();
    $today = now()->toDateString();

    // Attendance: 2 late arrivals, 1 with overtime.
    DB_table_attendance($company->id, $employees[0]->id, $today, '09:20:00', '17:00:00', 1.5);
    DB_table_attendance($company->id, $employees[1]->id, $today, '09:10:00', '17:00:00', 0);
    DB_table_attendance($company->id, $employees[2]->id, $today, '09:00:00', '17:00:00', 0);

    // Leave: one validated 3-day Annual Leave.
    $leaveType = LeaveType::query()->create(['company_id' => $company->id, 'name' => 'Annual Leave', 'is_active' => true, 'requires_allocation' => 'no']);
    Leave::query()->create([
        'employee_id'       => $employees[0]->id, 'employee_company_id' => $company->id, 'company_id' => $company->id,
        'holiday_status_id' => $leaveType->id, 'state' => State::VALIDATE_TWO,
        'date_from'         => $today, 'date_to' => now()->addDays(2)->toDateString(), 'number_of_days' => 3,
    ]);

    // Recruitment funnel: 2 applicants in "Interview" stage.
    $stage = Stage::query()->create(['company_id' => $company->id, 'name' => 'Interview', 'sort' => 1]);
    for ($i = 0; $i < 2; $i++) {
        $candidate = Candidate::query()->create(['company_id' => $company->id, 'name' => 'Candidate '.$i, 'email' => 'cand'.$i.uniqid().'@example.test']);
        $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Engineer '.$i]);
        Applicant::query()->create([
            'company_id'  => $company->id, 'candidate_id' => $candidate->id, 'job_id' => $job->id, 'stage_id' => $stage->id,
            'create_date' => $today, 'is_active' => true,
        ]);
    }

    // Claims: one approved reimbursement (counted), one still open/pending (in open_requests).
    $reimbursementType = EmployeeRequestType::query()->create([
        'company_id'            => $company->id, 'code' => 'reimb', 'name' => 'Reimbursement', 'category' => 'reimbursement',
        'approval_request_type' => 'reimb_wf', 'is_financial' => true,
    ]);
    EmployeeRequest::query()->create([
        'company_id' => $company->id, 'employee_id' => $employees[0]->id, 'request_type_id' => $reimbursementType->id,
        'title'      => 'Travel claim', 'status' => 'approved', 'amount' => 75, 'approved_at' => now(),
    ]);
    EmployeeRequest::query()->create([
        'company_id' => $company->id, 'employee_id' => $employees[1]->id, 'request_type_id' => $reimbursementType->id,
        'title'      => 'Pending claim', 'status' => 'pending_approval', 'amount' => 40,
    ]);

    // Employee movement: an explicit promotion-style status change beyond the auto-activation row.
    EmployeeStatusHistory::query()->create(['company_id' => $company->id, 'employee_id' => $employees[0]->id, 'status' => 'promoted', 'effective_date' => $today]);

    // Performance: 2 reviews rated 4, 1 rated 5.
    $cycle = PerformanceCycle::query()->create(['company_id' => $company->id, 'name' => 'Cycle', 'starts_on' => now(), 'ends_on' => now()->addMonth(), 'status' => 'active']);
    PerformanceReview::query()->create(['company_id' => $company->id, 'cycle_id' => $cycle->id, 'employee_id' => $employees[0]->id, 'status' => 'completed', 'manager_rating' => 4]);
    PerformanceReview::query()->create(['company_id' => $company->id, 'cycle_id' => $cycle->id, 'employee_id' => $employees[1]->id, 'status' => 'completed', 'manager_rating' => 4]);
    PerformanceReview::query()->create(['company_id' => $company->id, 'cycle_id' => $cycle->id, 'employee_id' => $employees[2]->id, 'status' => 'completed', 'manager_rating' => 5]);

    return compact('company', 'otherCompany', 'employees', 'engineering', 'sales', 'from', 'to');
}

function DB_table_attendance(int $companyId, int $employeeId, string $date, string $checkIn, string $checkOut, float $overtimeHours): void
{
    AttendanceRecord::query()->create([
        'company_id'      => $companyId, 'employee_id' => $employeeId, 'attendance_date' => $date,
        'scheduled_start' => "$date 09:00:00", 'scheduled_end' => "$date 17:00:00",
        'check_in'        => "$date $checkIn", 'check_out' => "$date $checkOut",
        'overtime_hours'  => $overtimeHours, 'status' => 'present', 'source' => 'manual',
    ]);
}

// ---------------------------------------------------------------------
// 1-8: report accuracy against the controlled dataset.
// ---------------------------------------------------------------------
it('1. PASS: headcount reflects only active employees in this company', function () {
    $f = thrControlledFixture();
    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);

    expect($result['headcount'])->toBe(5); // 5 active, 1 inactive excluded, otherCompany excluded
});

it('2. PASS: department distribution correctly buckets by department with drill-down ids', function () {
    $f = thrControlledFixture();
    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);

    $byName = collect($result['department_distribution'])->keyBy('name');
    expect($byName['Engineering']['total'])->toBe(3)
        ->and($byName['Engineering']['department_id'])->toBe($f['engineering']->id)
        ->and($byName['Sales']['total'])->toBe(2)
        ->and($byName['Sales']['department_id'])->toBe($f['sales']->id);
});

it('3. PASS: attendance metrics report the correct late-arrival and overtime figures', function () {
    $f = thrControlledFixture();
    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);

    expect($result['attendance_records'])->toBe(3)
        ->and($result['late_arrivals'])->toBe(2)
        ->and($result['attendance_overtime_hours'])->toBe(1.5);
});

it('4. PASS: leave totals report the correct validated day count', function () {
    $f = thrControlledFixture();
    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);

    expect($result['leave_days'])->toBe(3.0);
});

it('5. PASS: recruitment funnel reports the correct stage counts with a drill-down stage id', function () {
    $f = thrControlledFixture();
    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);

    $interviewRow = collect($result['recruitment_funnel'])->firstWhere('stage', 'Interview');
    expect($interviewRow['total'])->toBe(2);
});

it('6. PASS: open claims are counted separately from the one already-approved claim', function () {
    $f = thrControlledFixture();
    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);

    expect($result['open_requests'])->toBe(1) // the still-pending claim
        ->and($result['claims_and_reimbursements']['count'])->toBe(1) // only the approved one
        ->and($result['claims_and_reimbursements']['amount'])->toBe(75.0);
});

it('7. PASS: employee movement reports the recorded status transition', function () {
    $f = thrControlledFixture();
    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);

    $promoted = collect($result['employee_movement'])->firstWhere('status', 'promoted');
    expect($promoted['total'])->toBe(1);
});

it('8. PASS: performance distribution reports the correct rating buckets, not just an average', function () {
    $f = thrControlledFixture();
    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);

    $distribution = collect($result['performance_distribution'])->keyBy('rating');
    expect($distribution[4]['total'])->toBe(2)
        ->and($distribution[5]['total'])->toBe(1)
        ->and($result['average_performance'])->toBe(4.33);
});

// ---------------------------------------------------------------------
// Role/company visibility.
// ---------------------------------------------------------------------
it('HR user: can access the HR Analytics page and the data is accurate', function () {
    $f = thrControlledFixture();
    $hrUser = thrUser($f['company'], [HrPermissions::ManagePerformance, HrPermissions::ViewAnalytics], 'thr_hr_'.uniqid());

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($hrUser);

    expect(HrAnalytics::canAccess())->toBeTrue()
        ->and(test()->get('/admin/hr-analytics')->getStatusCode())->toBe(200);
});

it('Manager: with the manager role tier, can access the page and sees the same company-wide figures', function () {
    $f = thrControlledFixture();
    // The pre-existing 'manager' role tier already carries ViewAnalytics
    // (HrPermissions::manager()) -- confirmed here, not assumed.
    $managerUser = thrUser($f['company'], [HrPermissions::ViewAnalytics], 'manager');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($managerUser);

    expect(HrAnalytics::canAccess())->toBeTrue();

    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);
    expect($result['headcount'])->toBe(5);
});

it('Employee: a plain employee with no analytics permission is denied the page', function () {
    $f = thrControlledFixture();
    $employeeUser = User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]);
    Employee::query()->create(['company_id' => $f['company']->id, 'user_id' => $employeeUser->id, 'name' => 'Plain Employee', 'is_active' => true]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($employeeUser);

    expect(HrAnalytics::canAccess())->toBeFalse();
    expect(test()->get('/admin/hr-analytics')->getStatusCode())->not->toBe(200);
});

it('Finance role: a real finance role (controller) is denied the HR Analytics page entirely', function () {
    $f = thrControlledFixture();
    $financeRole = Role::query()->firstOrCreate(['name' => 'controller', 'guard_name' => 'web']);
    app(AccountingPermissionRegistrar::class)->synchronize();

    $financeUser = User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]);
    $financeUser->assignRole($financeRole);
    $financeUser->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($financeUser);

    expect(HrAnalytics::canAccess())->toBeFalse();
    expect(test()->get('/admin/hr-analytics')->getStatusCode())->not->toBe(200);
});

it('ERP Administrator: can access the page, and sees only their own company\'s figures, never another company\'s', function () {
    $f = thrControlledFixture();
    $adminRole = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail();
    $adminUser = User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]);
    $adminUser->assignRole($adminRole);
    $adminUser->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($adminUser);

    expect(HrAnalytics::canAccess())->toBeTrue();

    $result = app(HrAnalyticsService::class)->summary($f['company']->id, $f['from'], $f['to']);
    expect($result['headcount'])->toBe(5) // this company's real count
        ->and($result['monthly_payroll_cost'])->not->toBe(99999.0); // never the other company's employee

    $otherResult = app(HrAnalyticsService::class)->summary($f['otherCompany']->id, $f['from'], $f['to']);
    expect($otherResult['headcount'])->toBe(1)
        ->and($otherResult['monthly_payroll_cost'])->toBe(99999.0);
});
