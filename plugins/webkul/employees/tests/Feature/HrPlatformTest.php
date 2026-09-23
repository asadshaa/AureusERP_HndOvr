<?php

use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\EmployeeSensitiveChangeService;
use Webkul\Employee\Services\HrAnalyticsService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Services\Security\HrPermissionRegistrar;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource as RecruitmentApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource as RecruitmentJobPositionResource;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Services\ApplicantIntakeService;
use Webkul\Recruitment\Services\CandidateConversionService;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Enums\State as LeaveState;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\Timesheet\Models\Timesheet;
use Webkul\Timesheet\Services\TimesheetWorkflowService;

function hrPlatformUser(Company $company): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

function hrPlatformEmployee(Company $company, User $user, string $name, ?Employee $manager = null, ?Department $department = null): Employee
{
    return Employee::query()->create([
        'company_id'        => $company->id,
        'user_id'           => $user->id,
        'department_id'     => $department?->id,
        'parent_id'         => $manager?->id,
        'name'              => $name,
        'work_email'        => $user->email,
        'employee_number'   => 'EMP-'.$company->id.'-'.$user->id,
        'employment_status' => 'active',
        'is_active'         => true,
    ]);
}

function hrPlatformWorkflow(Company $company, User $creator, User $approver, string $requestType): ApprovalWorkflow
{
    $workflow = ApprovalWorkflow::query()->create([
        'company_id'   => $company->id,
        'creator_id'   => $creator->id,
        'name'         => $requestType.' approval',
        'request_type' => $requestType,
        'priority'     => 100,
        'is_active'    => true,
    ]);
    $workflow->steps()->create([
        'sequence'           => 1,
        'name'               => 'Manager approval',
        'approver_user_id'   => $approver->id,
        'required_approvals' => 1,
    ]);

    return $workflow;
}

it('enforces company team and manager hierarchy and audits approved sensitive employee changes', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $managerUser = hrPlatformUser($company);
    $employeeUser = hrPlatformUser($company);
    $approver = hrPlatformUser($company);
    $outsider = hrPlatformUser($otherCompany);
    $this->actingAs($managerUser);

    $department = Department::query()->create(['company_id' => $company->id, 'name' => 'Operations']);
    $manager = hrPlatformEmployee($company, $managerUser, 'Operations Manager', null, $department);
    $department->update(['manager_id' => $manager->id]);
    $team = Team::query()->create([
        'company_id'          => $company->id,
        'department_id'       => $department->id,
        'manager_employee_id' => $manager->id,
        'name'                => 'Delivery',
        'is_active'           => true,
    ]);
    $employee = hrPlatformEmployee($company, $employeeUser, 'Team Member', $manager, $department);
    $employee->update(['team_id' => $team->id]);
    hrPlatformEmployee($otherCompany, $outsider, 'Outside Employee');

    $visible = app(HrHierarchyService::class)->visibleEmployeeIds($managerUser, $company->id);
    expect($visible)->toContain($manager->id, $employee->id)
        ->and($visible)->not->toContain(Employee::query()->where('company_id', $otherCompany->id)->value('id'));

    hrPlatformWorkflow($company, $managerUser, $approver, 'employee_sensitive_change');
    $request = app(EmployeeSensitiveChangeService::class)->submit($employee, $managerUser, [
        'base_salary'      => '125000.0000',
        'identification_id'=> 'CNIC-TEST-001',
        'name'             => 'Ignored unsafe field',
    ]);
    $request = app(ApprovalEngine::class)->approve($request, $approver, 'HR verified identity and compensation');
    $updated = app(EmployeeSensitiveChangeService::class)->applyApproved($request);

    expect($updated->base_salary)->toBe('125000.0000')
        ->and($updated->identification_id)->toBe('CNIC-TEST-001')
        ->and($updated->name)->toBe('Team Member')
        ->and($request->decisions)->toHaveCount(1)
        ->and($request->decisions->first()->reason)->toBe('HR verified identity and compensation');
});

it('calculates attendance flags and completes self and manager performance review workflow', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $managerUser = hrPlatformUser($company);
    $employeeUser = hrPlatformUser($company);
    $this->actingAs($managerUser);
    $manager = hrPlatformEmployee($company, $managerUser, 'Manager');
    $employee = hrPlatformEmployee($company, $employeeUser, 'Employee', $manager);

    $attendance = AttendanceRecord::query()->create([
        'company_id'      => $company->id,
        'employee_id'     => $employee->id,
        'attendance_date' => '2026-08-01',
        'scheduled_start' => '2026-08-01 09:00:00',
        'scheduled_end'   => '2026-08-01 17:00:00',
        'check_in'        => '2026-08-01 09:15:00',
        'check_out'       => '2026-08-01 16:45:00',
        'source'          => 'manual',
    ]);
    expect((float) $attendance->worked_hours)->toBe(7.5)
        ->and($attendance->late_minutes)->toBe(15)
        ->and($attendance->early_departure_minutes)->toBe(15);

    $cycle = PerformanceCycle::query()->create([
        'company_id' => $company->id,
        'name'       => '2026 Annual Review',
        'starts_on'  => '2026-01-01',
        'ends_on'    => '2026-12-31',
    ]);
    $reviews = app(PerformanceService::class)->launch($cycle, $managerUser);
    $review = $reviews->firstWhere('employee_id', $employee->id);
    $review = app(PerformanceService::class)->submitSelfReview($review, $employee, 4.1, 'Delivered objectives');
    $review = app(PerformanceService::class)->completeManagerReview($review, $manager, 4.4, 'Strong delivery');

    expect($cycle->fresh()->status)->toBe('active')
        ->and($review->status)->toBe('completed')
        ->and((float) $review->self_rating)->toBe(4.1)
        ->and((float) $review->manager_rating)->toBe(4.4);
});

it('routes timesheets for approval and locks an auditable final status', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $managerUser = hrPlatformUser($company);
    $employeeUser = hrPlatformUser($company);
    $this->actingAs($employeeUser);
    $manager = hrPlatformEmployee($company, $managerUser, 'Timesheet Manager');
    hrPlatformEmployee($company, $employeeUser, 'Consultant', $manager);
    hrPlatformWorkflow($company, $employeeUser, $managerUser, 'timesheet_submission');
    $timesheet = Timesheet::query()->create([
        'type'            => 'projects',
        'company_id'      => $company->id,
        'user_id'         => $employeeUser->id,
        'date'            => '2026-08-01',
        'name'            => 'Client delivery',
        'unit_amount'     => '8.0000',
        'is_billable'     => true,
        'workflow_status' => 'draft',
    ]);

    app(TimesheetWorkflowService::class)->submit($timesheet, $employeeUser);
    $approved = app(TimesheetWorkflowService::class)->approve($timesheet->fresh(), $managerUser, 'Hours verified');

    expect($approved->workflow_status)->toBe('approved')
        ->and($approved->approved_by)->toBe($managerUser->id)
        ->and($approved->approvalRequest->status)->toBe('approved')
        ->and($approved->approved_at)->not->toBeNull();
});

it('routes leave through the shared approval engine with company isolation', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $managerUser = hrPlatformUser($company);
    $employeeUser = hrPlatformUser($company);
    $outsider = hrPlatformUser($otherCompany);
    $this->actingAs($employeeUser);
    $manager = hrPlatformEmployee($company, $managerUser, 'Leave Manager');
    $employee = hrPlatformEmployee($company, $employeeUser, 'Leave Employee', $manager);
    hrPlatformWorkflow($company, $employeeUser, $managerUser, 'leave_request');
    $leaveType = LeaveType::query()->create([
        'company_id' => $company->id,
        'name'       => 'Annual Leave',
        'is_active'  => true,
    ]);
    $leave = Leave::query()->create([
        'company_id'          => $company->id,
        'employee_company_id' => $company->id,
        'employee_id'         => $employee->id,
        'user_id'             => $employeeUser->id,
        'holiday_status_id'   => $leaveType->id,
        'request_date_from'   => '2026-08-10',
        'request_date_to'     => '2026-08-11',
        'date_from'           => '2026-08-10',
        'date_to'             => '2026-08-11',
        'number_of_days'      => 2,
        'state'               => LeaveState::CONFIRM,
    ]);

    $service = app(LeaveApprovalService::class);
    $service->submit($leave, $employeeUser);
    expect(fn () => $service->approve($leave->fresh(), $outsider, 'Cross-company attempt'))
        ->toThrow(RuntimeException::class);
    $approved = $service->approve($leave->fresh(), $managerUser, 'Leave balance verified');

    expect($approved->state)->toBe(LeaveState::VALIDATE_TWO)
        ->and($approved->approvalRequest->status)->toBe('approved')
        ->and($approved->first_approver_id)->toBe($manager->id)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->rejection_reason)->toBeNull();
});

it('sends an approved financial employee request to a balanced, posted vendor Bill exactly once', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $employeeUser = hrPlatformUser($company);
    $financeApprover = hrPlatformUser($company);
    $this->actingAs($employeeUser);
    $employee = hrPlatformEmployee($company, $employeeUser, 'Claimant');
    $expense = Account::factory()->create([
        'currency_id' => $currency->id,
        'account_type'=> AccountType::EXPENSE,
        'is_group'    => false,
        'deprecated'  => false,
    ]);
    // LIABILITY_PAYABLE, not LIABILITY_CURRENT: an approved claim posts as a
    // real Bill, whose balancing payable line is auto-resolved from the
    // company's LIABILITY_PAYABLE account -- see EmployeeRequestService::
    // createAccountingDraft(). reconcile => true matches the real company-1
    // "Account Payable" account (211000): without it,
    // MoveLine::computeAmountResidual() forces the residual to 0 and the
    // fresh Bill's payment_state would incorrectly compute as PAID.
    $payable = Account::factory()->create([
        'currency_id' => $currency->id,
        'account_type'=> AccountType::LIABILITY_PAYABLE,
        'is_group'    => false,
        'deprecated'  => false,
        'reconcile'   => true,
    ]);
    $expense->companies()->attach($company->id);
    $payable->companies()->attach($company->id);
    // PURCHASE, not GENERAL: an approved claim posts as a real vendor Bill
    // (move_type = IN_INVOICE), which requires a Purchase-type journal.
    $journal = Journal::factory()->create([
        'company_id'  => $company->id,
        'currency_id' => $currency->id,
        'type'        => JournalType::PURCHASE,
        'code'        => 'HR-'.$company->id,
    ]);
    $type = EmployeeRequestType::query()->create([
        'company_id'           => $company->id,
        'journal_id'           => $journal->id,
        'debit_account_id'     => $expense->id,
        'credit_account_id'    => $payable->id,
        'code'                 => 'EXPENSE-CLAIM',
        'name'                 => 'Expense Claim',
        'category'             => 'reimbursement',
        'approval_request_type'=> 'employee_expense_claim',
        'is_financial'         => true,
        'requires_amount'      => true,
        'is_active'            => true,
    ]);
    hrPlatformWorkflow($company, $employeeUser, $financeApprover, 'employee_expense_claim');
    $employeeRequest = EmployeeRequest::query()->create([
        'company_id'      => $company->id,
        'employee_id'     => $employee->id,
        'request_type_id' => $type->id,
        'requested_by'    => $employeeUser->id,
        'currency_id'     => $currency->id,
        'title'           => 'Client travel reimbursement',
        'amount'          => '5000.0000',
    ]);
    $service = app(EmployeeRequestService::class);
    $service->submit($employeeRequest, $employeeUser);
    $employeeRequest = $service->approve($employeeRequest->fresh(), $financeApprover, 'Receipts verified');
    $originalMoveId = $employeeRequest->accounting_move_id;
    $service->createAccountingDraft($employeeRequest);

    expect($employeeRequest->status)->toBe('approved')
        // Posted immediately via AccountFacade::confirmMove() -- the same
        // mechanism a real Bill's "Confirm" action and Drive-ingested
        // vendor bills use -- not left as an unposted draft. Posting is not
        // paying: no Payment is created here, and registering one remains a
        // fully separate, manual "Register Payment" action.
        ->and($employeeRequest->accountingMove->state)->toBe(MoveState::POSTED)
        ->and($employeeRequest->accountingMove->move_type->value)->toBe('in_invoice')
        ->and((float) $employeeRequest->accountingMove->lines->sum('debit'))->toBe(5000.0)
        ->and((float) $employeeRequest->accountingMove->lines->sum('credit'))->toBe(5000.0)
        ->and($employeeRequest->accountingMove->accounting_source_type)->toBe('employee_request')
        ->and($service->createAccountingDraft($employeeRequest)->accounting_move_id)->toBe($originalMoveId)
        ->and(DB::table('accounts_account_moves')->where('accounting_source_type', 'employee_request')->where('accounting_source_id', $employeeRequest->id)->count())->toBe(1);
});

it('converts a sourced applicant to one company employee without duplicate re-entry and reports HR analytics', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = hrPlatformUser($company);
    $this->actingAs($user);
    $department = Department::query()->create(['company_id' => $company->id, 'name' => 'Technology']);
    $job = EmployeeJobPosition::query()->create([
        'company_id'       => $company->id,
        'department_id'    => $department->id,
        'name'             => 'ERP Engineer',
        'posting_status'   => 'published',
        'published_at'     => now(),
        'posting_channels' => ['company_website', 'linkedin'],
        'is_active'        => true,
    ]);
    $candidate = Candidate::query()->create([
        'company_id'      => $company->id,
        'name'            => 'Qualified Candidate',
        'email_from'      => 'candidate@example.test',
        'phone'           => '03001234567',
        'resume_path'     => 'recruitment/cv/candidate.pdf',
        'source_reference'=> 'LinkedIn campaign 2026',
        'is_active'       => true,
    ]);
    $application = Applicant::query()->create([
        'candidate_id'           => $candidate->id,
        'company_id'             => $company->id,
        'job_id'                 => $job->id,
        'department_id'          => $department->id,
        'external_application_id'=> 'LI-APP-001',
        'source_details'         => 'LinkedIn',
        'screening_score'        => 82,
        'interview_score'        => 88,
        'assessment_score'       => 91,
        'offer_status'           => 'accepted',
        'offer_date'             => '2026-08-01',
        'create_date'            => '2026-07-01',
        'is_active'              => true,
    ]);
    $service = app(CandidateConversionService::class);
    $employee = $service->convert($application);
    $again = $service->convert($application->fresh());
    $analytics = app(HrAnalyticsService::class)->summary($company->id, '2026-01-01', '2026-12-31');

    expect($employee)->not->toBeNull()
        ->and($again->id)->toBe($employee->id)
        ->and($employee->company_id)->toBe($company->id)
        ->and($employee->job_id)->toBe($job->id)
        ->and($application->fresh()->application_status->value)->toBe('hired')
        ->and(Employee::query()->where('partner_id', $candidate->partner_id)->count())->toBe(1)
        ->and($analytics['headcount'])->toBe(1)
        ->and($analytics['recruitment_funnel'])->toHaveCount(1)
        ->and($analytics['average_time_to_hire_days'])->toBeGreaterThanOrEqual(0);
});

it('normalizes idempotent manual and API applicant intake without crossing companies', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = hrPlatformUser($company);
    $this->actingAs($user);
    $job = EmployeeJobPosition::query()->create([
        'company_id' => $company->id,
        'name'       => 'API Intake Role',
        'is_active'  => true,
    ]);
    $otherJob = EmployeeJobPosition::query()->create([
        'company_id' => $otherCompany->id,
        'name'       => 'Other Company Role',
        'is_active'  => true,
    ]);
    $service = app(ApplicantIntakeService::class);
    $application = $service->import('api', [
        'company_id'     => $company->id,
        'application_id' => 'ATS-1001',
        'name'           => 'API Candidate',
        'email'          => 'api-candidate@example.test',
        'phone'          => '03001111111',
        'job_id'         => $job->id,
        'source'         => 'Configured ATS',
    ], $user);
    $again = $service->import('api', [
        'company_id'     => $company->id,
        'application_id' => 'ATS-1001',
        'name'           => 'API Candidate',
        'email'          => 'api-candidate@example.test',
        'phone'          => '03002222222',
        'job_id'         => $job->id,
        'source'         => 'Configured ATS',
    ], $user);

    expect($again->id)->toBe($application->id)
        ->and($again->candidate->phone)->toBe('03002222222')
        ->and($again->applicant_properties['source_provenance']['adapter'])->toBe('api')
        ->and(Applicant::query()->where('company_id', $company->id)->where('external_application_id', 'ATS-1001')->count())->toBe(1)
        ->and(Candidate::query()->where('company_id', $company->id)->where('email_from', 'api-candidate@example.test')->count())->toBe(1);

    expect(fn () => $service->import('manual', [
        'company_id'               => $otherCompany->id,
        'external_application_id'  => 'MANUAL-OTHER-1',
        'candidate_name'           => 'Outside Candidate',
        'candidate_email'          => 'outside@example.test',
        'job_id'                   => $otherJob->id,
    ], $user))->toThrow(RuntimeException::class);
});

it('scopes recruitment and leave resources to the active company and current employee', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = hrPlatformUser($company);
    $otherUser = hrPlatformUser($otherCompany);
    $this->actingAs($user);
    $employee = hrPlatformEmployee($company, $user, 'Scoped Employee');
    $otherEmployee = hrPlatformEmployee($otherCompany, $otherUser, 'Outside Employee');
    $job = EmployeeJobPosition::query()->create(['company_id' => $company->id, 'name' => 'Scoped Job', 'is_active' => true]);
    $otherJob = EmployeeJobPosition::query()->create(['company_id' => $otherCompany->id, 'name' => 'Outside Job', 'is_active' => true]);
    $candidate = Candidate::query()->create([
        'company_id' => $company->id,
        'name'       => 'Scoped Candidate',
        'email_from' => 'scoped@example.test',
        'is_active'  => true,
    ]);
    $otherCandidate = Candidate::query()->create([
        'company_id' => $otherCompany->id,
        'name'       => 'Outside Candidate',
        'email_from' => 'outside-scope@example.test',
        'is_active'  => true,
    ]);
    Applicant::query()->create([
        'company_id'   => $company->id,
        'candidate_id' => $candidate->id,
        'job_id'       => $job->id,
        'is_active'    => true,
    ]);
    Applicant::query()->create([
        'company_id'   => $otherCompany->id,
        'candidate_id' => $otherCandidate->id,
        'job_id'       => $otherJob->id,
        'is_active'    => true,
    ]);
    $leaveType = LeaveType::query()->create(['company_id' => $company->id, 'name' => 'Scoped Leave', 'is_active' => true]);
    Leave::query()->create([
        'company_id'          => $company->id,
        'employee_company_id' => $company->id,
        'employee_id'         => $employee->id,
        'user_id'             => $user->id,
        'holiday_status_id'   => $leaveType->id,
        'state'               => LeaveState::CONFIRM,
    ]);
    Leave::query()->create([
        'company_id'          => $otherCompany->id,
        'employee_company_id' => $otherCompany->id,
        'employee_id'         => $otherEmployee->id,
        'user_id'             => $otherUser->id,
        'state'               => LeaveState::CONFIRM,
    ]);

    expect(RecruitmentJobPositionResource::getEloquentQuery()->pluck('company_id')->unique()->all())->toBe([$company->id])
        ->and(RecruitmentApplicantResource::getEloquentQuery()->pluck('company_id')->unique()->all())->toBe([$company->id])
        ->and(TimeOffResource::getEloquentQuery()->pluck('company_id')->unique()->all())->toBe([$company->id])
        ->and(MyTimeOffResource::getEloquentQuery()->pluck('employee_id')->all())->toBe([$employee->id]);
});

it('renders the integrated HR Filament pages for an administrator', function (): void {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $admin = hrPlatformUser($company);
    $admin->assignRole(Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail());
    $this->actingAs($admin);

    foreach ([
        '/admin/attendance-records',
        '/admin/performance-cycles',
        '/admin/performance-reviews',
        '/admin/employee-request-types',
        '/admin/employee-requests',
        '/admin/hr-analytics',
        '/admin/timesheets',
        '/admin/recruitment',
        '/admin/recruitments/configurations/job-positions',
        '/admin/recruitments/applications/applicants',
        '/admin/time-off/management/time-offs',
    ] as $path) {
        $this->get($path)->assertOk();
    }
});

it('registers HR permissions for administrators and preserves the manager subset', function (): void {
    $admin = Role::query()->firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    $manager = Role::query()->firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
    $result = app(HrPermissionRegistrar::class)->synchronize();

    expect($result['permissions'])->toBe(count(HrPermissions::all()))
        ->and($admin->fresh()->hasAllPermissions(HrPermissions::all()))->toBeTrue()
        ->and($manager->fresh()->hasAllPermissions(HrPermissions::manager()))->toBeTrue()
        ->and($manager->fresh()->hasPermissionTo(HrPermissions::ManageSensitiveEmployeeData))->toBeFalse();
});
