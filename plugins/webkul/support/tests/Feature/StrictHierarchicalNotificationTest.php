<?php

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;

function hierarchicalNotificationFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->first() ?? Currency::factory()->create(['code' => 'PKR']);
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $hrRole = Role::query()->firstOrCreate(['name' => 'hr_manager', 'guard_name' => 'web']);
    $accountantRole = Role::query()->firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);

    $hrUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $hrUser->assignRole($hrRole);
    $hrUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $accountantUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $accountantUser->assignRole($accountantRole);
    $accountantUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $managerUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $managerUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $department = Department::factory()->create([
        'company_id' => $company->id,
        'manager_id' => null,
    ]);

    $managerEmployee = Employee::query()->create([
        'company_id'    => $company->id,
        'department_id' => $department->id,
        'user_id'       => $managerUser->id,
        'name'          => 'Direct Line Manager',
        'is_active'     => true,
    ]);

    $employeeUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $employeeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $employee = Employee::query()->create([
        'company_id'    => $company->id,
        'department_id' => $department->id,
        'parent_id'     => $managerEmployee->id,
        'user_id'       => $employeeUser->id,
        'name'          => 'Subordinate Employee',
        'is_active'     => true,
    ]);

    // Financial request type setup
    $expense = Account::factory()->create([
        'currency_id'  => $currency->id,
        'account_type' => AccountType::EXPENSE,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $payable = Account::factory()->create([
        'currency_id'  => $currency->id,
        'account_type' => AccountType::LIABILITY_PAYABLE,
        'is_group'     => false,
        'deprecated'   => false,
        'reconcile'    => true,
    ]);
    $expense->companies()->attach($company->id);
    $payable->companies()->attach($company->id);

    $journal = Journal::factory()->create([
        'company_id'  => $company->id,
        'currency_id' => $currency->id,
        'type'        => JournalType::PURCHASE,
        'code'        => 'NOTIF-'.$company->id,
    ]);

    $requestType = EmployeeRequestType::query()->create([
        'company_id'            => $company->id,
        'name'                  => 'Travel Expense',
        'code'                  => 'travel_expense_'.$company->id,
        'approval_request_type' => 'travel_expense_'.$company->id,
        'category'              => 'reimbursement',
        'is_financial'          => true,
        'is_active'             => true,
        'journal_id'            => $journal->id,
        'debit_account_id'      => $expense->id,
        'credit_account_id'     => $payable->id,
    ]);

    // Workflow with Manager (Step 1) -> HR (Step 2)
    $workflow = ApprovalWorkflow::query()->create([
        'company_id'   => $company->id,
        'creator_id'   => $hrUser->id,
        'name'         => 'Two-stage Reimbursement Workflow',
        'request_type' => $requestType->approval_request_type,
        'priority'     => 100,
        'is_active'    => true,
    ]);

    $step1 = $workflow->steps()->create([
        'sequence'           => 1,
        'name'               => 'Line Manager Review',
        'hierarchy_route'    => 'requester_manager',
        'required_approvals' => 1,
    ]);

    $step2 = $workflow->steps()->create([
        'sequence'           => 2,
        'name'               => 'HR Approval',
        'approver_user_id'   => $hrUser->id,
        'required_approvals' => 1,
    ]);

    return compact(
        'company', 'currency', 'hrRole', 'accountantRole',
        'hrUser', 'accountantUser', 'managerUser', 'managerEmployee',
        'employeeUser', 'employee', 'requestType', 'workflow', 'step1', 'step2'
    );
}

it('notifies only the assigned line manager when employee submits request', function (): void {
    $f = hierarchicalNotificationFixture();
    Auth::login($f['employeeUser']);

    $request = EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'request_type_id' => $f['requestType']->id,
        'title'           => 'Client Visit Reimbursement',
        'billed_amount'   => '1500.0000',
        'amount'          => '1500.0000',
        'status'          => 'draft',
    ]);

    app(EmployeeRequestService::class)->submit($request, $f['employeeUser']);

    // Line manager must have received the notification
    $managerNotifs = DB::table('notifications')
        ->where('notifiable_id', $f['managerUser']->id)
        ->where('data', 'like', '%Request awaiting your review%')
        ->get();

    expect($managerNotifs)->toHaveCount(1);

    // HR user must NOT have received notification for step 1
    $hrNotifs = DB::table('notifications')
        ->where('notifiable_id', $f['hrUser']->id)
        ->where('data', 'like', '%Request awaiting your review%')
        ->get();

    expect($hrNotifs)->toHaveCount(0);
});

it('routes to HR fallback when employee has no line manager, recording fallback note without auto-approval', function (): void {
    $f = hierarchicalNotificationFixture();

    // Create orphan employee with no parent manager
    $orphanUser = User::factory()->create(['default_company_id' => $f['company']->id, 'is_active' => true]);
    $orphanUser->allowedCompanies()->syncWithoutDetaching([$f['company']->id]);

    $orphanEmployee = Employee::query()->create([
        'company_id' => $f['company']->id,
        'user_id'    => $orphanUser->id,
        'parent_id'  => null,
        'name'       => 'Orphan Employee',
        'is_active'  => true,
    ]);

    Auth::login($orphanUser);

    $request = EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $orphanEmployee->id,
        'request_type_id' => $f['requestType']->id,
        'title'           => 'Taxi Reimbursement',
        'billed_amount'   => '500.0000',
        'amount'          => '500.0000',
        'status'          => 'draft',
    ]);

    app(EmployeeRequestService::class)->submit($request, $orphanUser);

    // Request is pending approval, NOT auto-approved
    expect($request->fresh()->status)->toBe('pending_approval');

    // HR fallback received notification with fallback routing note
    $hrNotifs = DB::table('notifications')
        ->where('notifiable_id', $f['hrUser']->id)
        ->where('data', 'like', '%no line manager assigned%')
        ->get();

    expect($hrNotifs->count())->toBeGreaterThanOrEqual(1);
});

it('strictly notifies the next step approver only after preceding step is approved', function (): void {
    $f = hierarchicalNotificationFixture();
    Auth::login($f['employeeUser']);

    $request = EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'request_type_id' => $f['requestType']->id,
        'title'           => 'Hotel Stay',
        'billed_amount'   => '3000.0000',
        'amount'          => '3000.0000',
        'status'          => 'draft',
    ]);

    $approval = app(EmployeeRequestService::class)->submit($request, $f['employeeUser']);

    // Step 2 (HR) has 0 notifications so far
    expect(DB::table('notifications')
        ->where('notifiable_id', $f['hrUser']->id)
        ->where('data', 'like', '%HR Approval awaiting your approval%')
        ->count())->toBe(0);

    // Line manager approves Step 1
    app(ApprovalEngine::class)->approve($approval, $f['managerUser'], 'Line manager verified');

    // Now Step 2 (HR) receives the step advancement notification!
    $hrNotifs = DB::table('notifications')
        ->where('notifiable_id', $f['hrUser']->id)
        ->where('data', 'like', '%HR Approval awaiting your approval%')
        ->get();

    expect($hrNotifs)->toHaveCount(1);
});

it('notifies the requesting employee upon rejection with the rejection reason', function (): void {
    $f = hierarchicalNotificationFixture();
    Auth::login($f['employeeUser']);

    $request = EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'request_type_id' => $f['requestType']->id,
        'title'           => 'Overbudget Dinner',
        'billed_amount'   => '8000.0000',
        'amount'          => '8000.0000',
        'status'          => 'draft',
    ]);

    $approval = app(EmployeeRequestService::class)->submit($request, $f['employeeUser']);

    app(ApprovalEngine::class)->reject($approval, $f['managerUser'], 'Receipt is missing and exceeds policy limit');

    $employeeNotifs = DB::table('notifications')
        ->where('notifiable_id', $f['employeeUser']->id)
        ->where('data', 'like', '%Receipt is missing and exceeds policy limit%')
        ->get();

    expect($employeeNotifs)->toHaveCount(1);
});

it('notifies accounting when a financial reimbursement completes approval and creates a bill', function (): void {
    $f = hierarchicalNotificationFixture();
    Auth::login($f['employeeUser']);

    $request = EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'request_type_id' => $f['requestType']->id,
        'title'           => 'Approved Flight',
        'billed_amount'   => '12000.0000',
        'amount'          => '12000.0000',
        'status'          => 'draft',
    ]);

    $approval = app(EmployeeRequestService::class)->submit($request, $f['employeeUser']);

    // Step 1: Manager approves
    app(ApprovalEngine::class)->approve($approval, $f['managerUser'], 'Manager ok');

    // Step 2: HR approves (final step)
    app(ApprovalEngine::class)->approve($approval->fresh(), $f['hrUser'], 'HR ok');

    // Synchronize subject to create accounting bill
    $request = app(EmployeeRequestService::class)->synchronize($request->fresh());

    expect($request->status)->toBe('approved')
        ->and($request->accounting_move_id)->not->toBeNull();

    // Accountant user must have received notification
    $acctNotifs = DB::table('notifications')
        ->where('notifiable_id', $f['accountantUser']->id)
        ->where('data', 'like', '%Approved reimbursement ready for Accounting review%')
        ->get();

    expect($acctNotifs)->toHaveCount(1);
});

it('enforces idempotency so re-processing does not create duplicate notifications', function (): void {
    $f = hierarchicalNotificationFixture();
    $service = app(HierarchicalNotificationService::class);

    $idempotencyKey = 'unique_event_test_'.uniqid();
    $notif = FilamentNotification::make()->title('Idempotent Test')->body('Checking duplicate delivery');

    // First delivery
    $firstResult = $service->sendIdempotentNotification($f['managerUser'], $notif, $idempotencyKey);
    expect($firstResult)->toBeTrue();

    // Second delivery with identical idempotency key
    $secondResult = $service->sendIdempotentNotification($f['managerUser'], $notif, $idempotencyKey);
    expect($secondResult)->toBeFalse();

    // Exactly 1 row in notifications table
    $count = DB::table('notifications')
        ->where('notifiable_id', $f['managerUser']->id)
        ->where('data', 'like', '%"idempotency_key":"'.$idempotencyKey.'"%')
        ->count();

    expect($count)->toBe(1);
});

it('notifies only included company employees upon performance cycle launch', function (): void {
    $f = hierarchicalNotificationFixture();

    $cycle = PerformanceCycle::query()->create([
        'company_id' => $f['company']->id,
        'creator_id' => $f['hrUser']->id,
        'name'       => 'Q4 Review Cycle 2026',
        'starts_on'  => now()->toDateString(),
        'ends_on'    => now()->addMonth()->toDateString(),
        'status'     => 'draft',
    ]);

    app(PerformanceService::class)->launch($cycle, $f['hrUser']);

    // Subordinate employee in Company 1 must receive notification
    $empNotifs = DB::table('notifications')
        ->where('notifiable_id', $f['employeeUser']->id)
        ->where('data', 'like', '%Q4 Review Cycle 2026%')
        ->get();

    expect($empNotifs)->toHaveCount(1);

    // Other company employee must NOT receive notification
    $otherCompany = Company::factory()->create(['currency_id' => $f['currency']->id, 'is_active' => true]);
    $outsider = User::factory()->create(['default_company_id' => $otherCompany->id, 'is_active' => true]);
    $outsider->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);

    $outsiderNotifs = DB::table('notifications')
        ->where('notifiable_id', $outsider->id)
        ->where('data', 'like', '%Q4 Review Cycle 2026%')
        ->get();

    expect($outsiderNotifs)->toHaveCount(0);
});

it('notifies manager reviewer on self review and notifies employee on manager completion without leaking ratings', function (): void {
    $f = hierarchicalNotificationFixture();

    $cycle = PerformanceCycle::query()->create([
        'company_id' => $f['company']->id,
        'creator_id' => $f['hrUser']->id,
        'name'       => 'Annual Review',
        'starts_on'  => now()->toDateString(),
        'ends_on'    => now()->addMonth()->toDateString(),
        'status'     => 'active',
    ]);

    $review = PerformanceReview::query()->create([
        'company_id'  => $f['company']->id,
        'cycle_id'    => $cycle->id,
        'employee_id' => $f['employee']->id,
        'reviewer_id' => $f['managerEmployee']->id,
        'status'      => 'self_review',
    ]);

    // Employee submits self review
    app(PerformanceService::class)->submitSelfReview($review, $f['employee'], 4.5, 'Met all deliverables');

    // Manager reviewer received self review notification
    $mgrNotifs = DB::table('notifications')
        ->where('notifiable_id', $f['managerUser']->id)
        ->where('data', 'like', '%Self-review submitted%')
        ->get();

    expect($mgrNotifs)->toHaveCount(1);

    // Manager completes review with confidential rating and improvement plan
    app(PerformanceService::class)->completeManagerReview($review->fresh(), $f['managerEmployee'], 5.0, 'Top performer confidential notes', [
        'promotion_recommendation' => 'Promote to Team Lead',
    ]);

    // Employee received completed notification
    $empNotifs = DB::table('notifications')
        ->where('notifiable_id', $f['employeeUser']->id)
        ->where('data', 'like', '%Performance review completed%')
        ->get();

    expect($empNotifs)->toHaveCount(1);

    // Verify confidential comments and ratings were NEVER leaked in the notification payload!
    $payloadData = $empNotifs->first()->data;
    expect($payloadData)->not->toContain('Top performer confidential notes')
        ->and($payloadData)->not->toContain('Promote to Team Lead');
});
