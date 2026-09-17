<?php

/**
 * CLAIMS / REIMBURSEMENT ACCEPTANCE TEST -- run against real data through
 * the Section 8 implementation (EmployeeRequestType/EmployeeRequest/
 * ApprovalEngine/EmployeeRequestService), one numbered TEST per the
 * acceptance script. Each test creates its own claim rather than sharing
 * state across tests, since Pest/DatabaseTransactions rolls back between
 * tests in this file.
 */

use Database\Seeders\FinanceRoleSeeder;
use Database\Seeders\HrRoleSeeder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Employee\Database\Seeders\ClaimsWorkflowSeeder;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

/**
 * "Create a test employee and valid hierarchy" -- a claimant reporting to a
 * real Line Manager via Employee.parent_id (the People category's
 * documented "Level 2" routing), in a company with real, company-linked
 * Expenses/Accounts Payable accounts and a general journal, so the claim
 * type actually resolves is_financial=true -- the same "is there really
 * somewhere for this to post" condition a production company would need,
 * not a shortcut that makes the accounting checks vacuously true.
 */
function claimsAcceptanceFixture(): array
{
    $systemUser = User::factory()->create(['is_active' => true]);
    Auth::login($systemUser);

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);

    $managerUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $manager = Employee::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'user_id' => $managerUser->id, 'name' => 'Line Manager']);
    $managerUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $employeeUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $employee = Employee::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'parent_id' => $manager->id, 'user_id' => $employeeUser->id, 'name' => 'Acceptance Claimant']);
    $employeeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    app(HrRoleSeeder::class)->run();
    app(FinanceRoleSeeder::class)->run();
    app(ClaimsWorkflowSeeder::class)->run();

    $expense = Account::factory()->create(['currency_id' => $currency->id, 'account_type' => AccountType::EXPENSE, 'is_group' => false, 'deprecated' => false]);
    $payable = Account::factory()->create(['currency_id' => $currency->id, 'account_type' => AccountType::LIABILITY_CURRENT, 'is_group' => false, 'deprecated' => false]);
    $expense->companies()->attach($company->id);
    $payable->companies()->attach($company->id);
    $journal = Journal::factory()->create(['company_id' => $company->id, 'currency_id' => $currency->id, 'type' => JournalType::GENERAL, 'code' => 'ACCEPT-'.$company->id]);

    $requestType = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', 'claim_people')->firstOrFail();
    $requestType->update(['is_financial' => true, 'journal_id' => $journal->id, 'debit_account_id' => $expense->id, 'credit_account_id' => $payable->id]);

    $hrUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $hrUser->assignRole(Role::query()->where('name', 'hr_manager')->where('guard_name', 'web')->firstOrFail());
    $hrUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $controllerUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $controllerUser->assignRole(Role::query()->where('name', 'controller')->where('guard_name', 'web')->firstOrFail());
    $controllerUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    // A second, unrelated reporting line in the same company -- not under
    // `manager` at all -- so "unrelated employee claim is not visible" has
    // a real employee to check against, not just an absence of data.
    $unrelatedManagerUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $unrelatedManager = Employee::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'user_id' => $unrelatedManagerUser->id, 'name' => 'Unrelated Manager']);
    $unrelatedEmployeeUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $unrelatedEmployee = Employee::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'parent_id' => $unrelatedManager->id, 'user_id' => $unrelatedEmployeeUser->id, 'name' => 'Unrelated Claimant']);
    $unrelatedEmployeeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return compact('company', 'department', 'manager', 'managerUser', 'employee', 'employeeUser', 'hrUser', 'controllerUser', 'unrelatedEmployee', 'unrelatedEmployeeUser', 'requestType');
}

/**
 * The exact PKR 5,000 People claim from TEST 1, factored out so later
 * TESTs (submit, approve, ...) start from the same known-good Draft instead
 * of re-typing its field list. Save Draft only -- no submit() call, exactly
 * what CreateAction::make()->label('Save Draft') does.
 */
function createAcceptancePeopleClaim(array $f): EmployeeRequest
{
    return EmployeeRequest::query()->create([
        'company_id'           => $f['company']->id,
        'employee_id'          => $f['employee']->id,
        'request_type_id'      => $f['requestType']->id,
        'title'                => 'Team event claim',
        'nature_of_expense'    => 'Team Event',
        'billed_amount'        => '5000.0000',
        'income_tax_deduction' => '0.0000',
        'sales_tax_deduction'  => '0.0000',
        'amount'               => '5000.0000', // Net payment = billed amount, no deductions entered
        'account_title'        => 'Acceptance Claimant',
        'iban'                 => 'PK36SCBL0000001123456702',
        'bank_name'            => 'Standard Chartered',
        'attachments'          => ['employees/requests/receipt.pdf'], // People Claim requires a supporting document
    ]);
}

// ---------------------------------------------------------------------
// TEST 1 -- Draft.
// ---------------------------------------------------------------------
it('TEST 1 PASS: a claim saved as Draft creates no approval request, no accounting posting, and no financial settlement', function () {
    $f = claimsAcceptanceFixture();
    Auth::login($f['employeeUser']);

    // "Nature = valid configured expense type" -- People's real seeded options.
    expect($f['requestType']->getExpenseNatures())->toContain('Team Event');
    // "Approval Type = Claims Approval" -- the family this type's approval routing belongs to.
    expect($f['requestType']->is_financial)->toBeTrue();

    $moveCountBefore = Move::query()->count();
    $approvalRequestCountBefore = ApprovalRequest::query()->count();

    $claim = createAcceptancePeopleClaim($f);

    // Claim saved as Draft.
    expect($claim->fresh())
        ->status->toBe('draft')
        ->billed_amount->toEqual('5000.0000')
        ->amount->toEqual('5000.0000')
        ->nature_of_expense->toBe('Team Event')
        // No approval request created.
        ->approval_request_id->toBeNull()
        // No Accounting posting.
        ->accounting_move_id->toBeNull()
        // No financial settlement.
        ->posted_to_accounting_at->toBeNull()
        ->submitted_at->toBeNull()
        ->approved_at->toBeNull();

    // Not just the FK columns -- confirm no row was actually created anywhere else either.
    expect(ApprovalRequest::query()->count())->toBe($approvalRequestCountBefore)
        ->and(Move::query()->count())->toBe($moveCountBefore);
});

// ---------------------------------------------------------------------
// TEST 2 -- Submit.
// ---------------------------------------------------------------------
it('TEST 2 PASS: submitting the claim starts HR review under the correct workflow, with approval history begun', function () {
    $f = claimsAcceptanceFixture();
    Auth::login($f['employeeUser']);
    $claim = createAcceptancePeopleClaim($f);

    $peopleWorkflow = ApprovalWorkflow::query()->where('company_id', $f['company']->id)->where('request_type', 'claim_people')->firstOrFail();
    $techWorkflow = ApprovalWorkflow::query()->where('company_id', $f['company']->id)->where('request_type', 'claim_tech')->firstOrFail();

    app(EmployeeRequestService::class)->submit($claim, $f['employeeUser']);
    $claim->refresh();

    // Draft becomes Submitted.
    expect($claim->status)->toBe('pending_approval')
        ->and($claim->submitted_at)->not->toBeNull()
        ->and($claim->approval_request_id)->not->toBeNull();

    $approval = $claim->approvalRequest;

    // Correct approval workflow is selected -- the People one specifically,
    // not Tech's (different hierarchy_route) or any other category's.
    expect($approval->workflow_id)->toBe($peopleWorkflow->id)
        ->and($approval->workflow_id)->not->toBe($techWorkflow->id)
        ->and($approval->workflow->request_type)->toBe('claim_people');

    // HR review/approval workflow begins: the request is pending at the
    // workflow's first step, which is HR Review, and the seeded HR
    // reviewer can act on it right now -- not just a status string, the
    // live routing genuinely points at HR.
    expect($approval->status)->toBe('pending')
        ->and($approval->current_step_sequence)->toBe(1)
        ->and($approval->currentStep()->name)->toBe('HR Review')
        ->and(app(ApprovalEngine::class)->canAct($approval, $f['hrUser']))->toBeTrue()
        // The Line Manager (a later step) cannot act yet -- confirms this
        // is genuinely sequential, not just an open free-for-all.
        ->and(app(ApprovalEngine::class)->canAct($approval, $f['managerUser']))->toBeFalse();

    // Approval history created: the ApprovalRequest is itself the start of
    // that history (requester + submission time recorded), with its
    // decision trail ready and, correctly, still empty -- nobody has acted
    // yet at this point in the script.
    expect($approval->requester_id)->toBe($f['employeeUser']->id)
        ->and($approval->decisions)->toHaveCount(0);
});

// ---------------------------------------------------------------------
// TEST 3 -- Level 1 approver / Line Manager.
//
// IMPORTANT, read before the assertions below: the script asks to log in
// as a "Level 1 approver" and separately expects the workflow to reach
// "Level 3" after the Line Manager. Neither exists. Level 1 (Mehwish) and
// Level 3 (Raza Afzal) are two of the four missing named actors reported
// at the end of Section 8 -- they were deliberately never wired into the
// seeded workflow because those people do not exist in this database, and
// ApprovalStep requires a real approver_user_id/role/hierarchy_route, not
// a placeholder. The People workflow's real, seeded sequence is:
//   1. HR Review            (role hr_manager)      -- done in TEST 2
//   2. Line Manager Review  (hierarchy_route)       -- tested below
//   3. Finance Final Processing (role controller)   -- the real next step
//   4. VP Finance Oversight (role vp_finance, amount-gated)
// So this test exercises the real step 2 (Line Manager, which the script's
// own second block also calls "Line Manager" and separately expects to
// exist) and confirms the workflow advances to the real step 3 (Finance
// Final Processing) rather than asserting a "Level 3" that was never built.
// ---------------------------------------------------------------------
it('TEST 3 PASS: the Line Manager sees only their own report\'s claim, can approve it, and the workflow advances to Finance', function () {
    $f = claimsAcceptanceFixture();
    Auth::login($f['employeeUser']);
    $claim = createAcceptancePeopleClaim($f);
    app(EmployeeRequestService::class)->submit($claim, $f['employeeUser']);
    app(EmployeeRequestService::class)->approve($claim->fresh(), $f['hrUser'], 'HR reviewed');
    $claim->refresh();

    // A second claim from an unrelated employee, for the isolation check.
    Auth::login($f['unrelatedEmployeeUser']);
    $unrelatedClaim = EmployeeRequest::query()->create([
        'company_id'    => $f['company']->id, 'employee_id' => $f['unrelatedEmployee']->id, 'request_type_id' => $f['requestType']->id,
        'title'         => 'Unrelated claim', 'billed_amount' => '1000.0000', 'amount' => '1000.0000',
        'account_title' => 'Unrelated', 'iban' => 'PK00TEST0000000000000000', 'bank_name' => 'Test Bank',
        'attachments'   => ['employees/requests/receipt.pdf'],
    ]);
    app(EmployeeRequestService::class)->submit($unrelatedClaim, $f['unrelatedEmployeeUser']);

    // "claim appears in approval queue" / "unrelated employee claim is not
    // visible" -- the actual scoping EmployeeRequestResource applies.
    Auth::login($f['managerUser']);
    $visibleToManager = EmployeeRequestResource::getEloquentQuery()->pluck('id');
    expect($visibleToManager)->toContain($claim->id)
        ->and($visibleToManager)->not->toContain($unrelatedClaim->id);

    // "employee/request details visible according to permission" -- the
    // Line Manager can see the claim (checked above), but is not the
    // claimant and does not hold ViewSensitiveEmployeeData, so the bank
    // detail section's visibility gate evaluates false for them (see
    // EmployeeRequestResource::formComponents()'s $canSeeBankDetails).
    expect($f['managerUser']->can(HrPermissions::ViewSensitiveEmployeeData))->toBeFalse()
        ->and((int) $f['employee']->user_id)->not->toBe((int) $f['managerUser']->id);

    // "approver can approve/reject/return as configured" -- approve/reject
    // are both real (canAct() true, decide() works, tested below and in
    // ClaimsWorkflowTest's rejection test). "Return" is not implemented
    // anywhere in ApprovalEngine -- confirmed by reading it during Section
    // 8: only 'approved'/'rejected' decisions exist, no distinct "returned"
    // status. Not something this test can pass; noting it as a real gap.
    expect(app(ApprovalEngine::class)->canAct($claim->approvalRequest, $f['managerUser']))->toBeTrue();

    // Approve.
    app(EmployeeRequestService::class)->approve($claim->fresh(), $f['managerUser'], 'Line manager approved');
    $claim->refresh();
    $approval = $claim->approvalRequest;

    // Level 1 decision recorded -- reading this as "the decision at this
    // step" since there is no separate Level 1 step; the Line Manager's
    // decision is the second recorded decision overall (after HR's).
    expect($approval->decisions()->where('decision', 'approved')->count())->toBe(2)
        ->and($approval->decisions->last()->actor_id)->toBe($f['managerUser']->id)
        ->and($approval->decisions->last()->reason)->toBe('Line manager approved');

    // Workflow advances -- to the real next step, Finance Final Processing.
    expect($approval->fresh()->current_step_sequence)->toBe(3)
        ->and($approval->fresh()->currentStep()->name)->toBe('Finance Final Processing')
        ->and(app(ApprovalEngine::class)->canAct($approval->fresh(), $f['controllerUser']))->toBeTrue();
});

// ---------------------------------------------------------------------
// TEST 6 -- Rejection.
// ---------------------------------------------------------------------
it('TEST 6 PASS: a claim rejected at the Line Manager step becomes Rejected, cannot proceed, and never reaches Accounting', function () {
    $f = claimsAcceptanceFixture();
    Auth::login($f['employeeUser']);

    $claim = EmployeeRequest::query()->create([
        'company_id'    => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['requestType']->id,
        'title'         => 'Large claim for rejection test', 'nature_of_expense' => 'Team Event',
        'billed_amount' => '100000.0000', 'income_tax_deduction' => '5000.0000', 'sales_tax_deduction' => '2000.0000',
        'amount'        => '93000.0000', // 100,000 - 5,000 - 2,000
        'account_title' => 'Acceptance Claimant', 'iban' => 'PK36SCBL0000001123456702', 'bank_name' => 'Standard Chartered',
        'attachments'   => ['employees/requests/receipt.pdf'],
    ]);
    app(EmployeeRequestService::class)->submit($claim, $f['employeeUser']);
    app(EmployeeRequestService::class)->approve($claim->fresh(), $f['hrUser'], 'HR reviewed');

    $moveCountBefore = Move::query()->count();

    // Reject it at Level 2 (Line Manager -- see TEST 3's note on step naming).
    $rejected = app(EmployeeRequestService::class)->reject($claim->fresh(), $f['managerUser'], 'Not a legitimate business expense');

    // Claim becomes Rejected.
    expect($rejected->status)->toBe('rejected')
        // Rejection reason is retained.
        ->and($rejected->rejection_reason)->toBe('Not a legitimate business expense')
        // No Accounting handoff occurs.
        ->and($rejected->accounting_move_id)->toBeNull()
        ->and($rejected->posted_to_accounting_at)->toBeNull();
    expect(Move::query()->count())->toBe($moveCountBefore);

    // Subsequent approval levels cannot approve it -- the request is
    // terminal (rejected), so canAct() is false for every later step's
    // holder, and Finance specifically cannot process it.
    $approval = $rejected->approvalRequest->fresh();
    expect($approval->status)->toBe('rejected')
        ->and(app(ApprovalEngine::class)->canAct($approval, $f['controllerUser']))->toBeFalse()
        ->and(fn () => app(EmployeeRequestService::class)->approve($rejected->fresh(), $f['controllerUser']))
        ->toThrow(RuntimeException::class);

    // History retained, not overwritten: both the HR approval and the
    // Line Manager rejection are still there.
    expect($approval->decisions)->toHaveCount(2)
        ->and($approval->decisions->pluck('decision')->all())->toBe(['approved', 'rejected']);
});
