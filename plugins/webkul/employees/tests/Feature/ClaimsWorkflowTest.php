<?php

/**
 * Section 8 ("IMPLEMENTATION SECTION 8 -- CLAIMS & REIMBURSEMENTS") --
 * exercises the existing EmployeeRequestType/EmployeeRequest/ApprovalEngine/
 * EmployeeRequestService machinery for the new "Claims and Reimbursements"
 * request types provisioned by ClaimsWorkflowSeeder, rather than a parallel
 * Claims system. Covers: seeder shape (including the two categories that
 * route differently -- Tech via department_manager plus a swapped Level
 * 3/4 pair, Real Estate with no business-hierarchy step and no Level 3/4 at
 * all by design, ending at Khurram directly), the tax/net-payment
 * validation added to EmployeeRequestService::submit(), the full, real
 * four-level People chain (HR Review -> Line Manager Review -> Level 3
 * Approval -> Level 4 Approval, pinned to Mehwish/hierarchy/Raza
 * Afzal/Khurram by name -- with no separate amount-gated step, since
 * ClaimsWorkflowSeeder's own docblock says that threshold was removed, not
 * merely renamed), rejection preserving history without ever reaching the
 * later levels, and that bank details never leak into a plain array/JSON
 * representation of the model.
 */

use Database\Seeders\FinanceRoleSeeder;
use Database\Seeders\HrRoleSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Filament\Resources\BillResource;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Employee\Database\Seeders\ClaimsWorkflowSeeder;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

function claimsFixture(): array
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
    $employee = Employee::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'parent_id' => $manager->id, 'user_id' => $employeeUser->id, 'name' => 'Claimant']);
    $employeeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    // ClaimsWorkflowSeeder pins Level 1/3/4 approval steps to specific named
    // individuals by email, not to a role -- create real Users for those
    // four emails BEFORE the seeder runs, so it wires them into this
    // company's workflow exactly as it would the real people, instead of
    // silently skipping those steps the way it does for an unmatched email.
    $mehwishUser = User::factory()->create(['email' => 'mehwish@truckitin.com', 'default_company_id' => $company->id, 'is_active' => true]);
    $mehwishUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $razaUser = User::factory()->create(['email' => 'raza.afzal@truckitin.com', 'default_company_id' => $company->id, 'is_active' => true]);
    $razaUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $khurramUser = User::factory()->create(['email' => 'khurram@truckitin.com', 'default_company_id' => $company->id, 'is_active' => true]);
    $khurramUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $haiderUser = User::factory()->create(['email' => 'haider.navid@truckitin.com', 'default_company_id' => $company->id, 'is_active' => true]);
    $haiderUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    app(HrRoleSeeder::class)->run();
    app(FinanceRoleSeeder::class)->run();
    app(ClaimsWorkflowSeeder::class)->run();

    $hrUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $hrUser->assignRole(Role::query()->where('name', 'hr_manager')->where('guard_name', 'web')->firstOrFail());
    $hrUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $controllerUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $controllerUser->assignRole(Role::query()->where('name', 'controller')->where('guard_name', 'web')->firstOrFail());
    $controllerUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $vpUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $vpUser->assignRole(Role::query()->where('name', 'vp_finance')->where('guard_name', 'web')->firstOrFail());
    $vpUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    // ClaimsWorkflowSeeder only wires is_financial=true when it finds a real
    // Expenses (600000) / Accounts Payable (211000) account already linked
    // to the company -- a freshly factory-made company has neither, so the
    // seeded claim_people row above is is_financial=false here. Give this
    // one type real, company-linked test accounts, same pattern already
    // proven in HrPlatformTest's accounting-handoff test.
    $expense = Account::factory()->create(['currency_id' => $currency->id, 'account_type' => AccountType::EXPENSE, 'is_group' => false, 'deprecated' => false]);
    // LIABILITY_PAYABLE, not LIABILITY_CURRENT: an approved claim now posts
    // as a real Bill, whose balancing payable line is auto-resolved by
    // MoveLine::computeAccountId() from the company's LIABILITY_PAYABLE
    // account -- the same account_type a real vendor's Accounts Payable
    // account uses. credit_account_id below is still stored for backward
    // compatibility, but is no longer what determines the posted account.
    // reconcile => true matches the real company-1 "Account Payable"
    // account (211000): MoveLine::computeAmountResidual() forces a
    // non-reconcilable account's residual to 0, which would make the fresh
    // Bill's payment_state compute as PAID instead of NOT_PAID.
    $payable = Account::factory()->create(['currency_id' => $currency->id, 'account_type' => AccountType::LIABILITY_PAYABLE, 'is_group' => false, 'deprecated' => false, 'reconcile' => true]);
    $expense->companies()->attach($company->id);
    $payable->companies()->attach($company->id);
    // PURCHASE, not GENERAL: EmployeeRequestService::createAccountingDraft()
    // now posts a real vendor Bill (move_type = IN_INVOICE), which requires
    // a Purchase-type journal.
    $journal = Journal::factory()->create(['company_id' => $company->id, 'currency_id' => $currency->id, 'type' => JournalType::PURCHASE, 'code' => 'CLAIMS-'.$company->id]);

    $requestType = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', 'claim_people')->firstOrFail();
    $requestType->update(['is_financial' => true, 'journal_id' => $journal->id, 'debit_account_id' => $expense->id, 'credit_account_id' => $payable->id]);

    return compact('company', 'department', 'manager', 'managerUser', 'employee', 'employeeUser', 'hrUser', 'controllerUser', 'vpUser', 'mehwishUser', 'razaUser', 'khurramUser', 'haiderUser', 'requestType');
}

// ---------------------------------------------------------------------
// Seeder shape: idempotent, and routes differently per documented category.
// ---------------------------------------------------------------------
it('provisions a claim type + workflow per category, idempotently, with Tech and Real Estate routed differently', function () {
    $f = claimsFixture();
    $stepsBefore = ApprovalWorkflow::query()->where('company_id', $f['company']->id)->where('request_type', 'like', 'claim_%')->withCount('steps')->get()->sum('steps_count');

    app(ClaimsWorkflowSeeder::class)->run();

    expect(EmployeeRequestType::query()->where('company_id', $f['company']->id)->where('code', 'like', 'claim_%')->count())->toBe(9)
        ->and(ApprovalWorkflow::query()->where('company_id', $f['company']->id)->where('request_type', 'like', 'claim_%')->withCount('steps')->get()->sum('steps_count'))->toBe($stepsBefore); // re-running does not duplicate steps

    // People: HR Review (Mehwish, named) -> Line Manager Review (hierarchy)
    // -> Level 3 (Raza Afzal, named) -> Level 4 (Khurram, named).
    $peopleSteps = ApprovalWorkflow::query()->where('company_id', $f['company']->id)->where('request_type', 'claim_people')->firstOrFail()->steps;
    expect($peopleSteps->pluck('name')->all())->toBe(['HR Review', 'Line Manager Review', 'Level 3 Approval', 'Level 4 Approval'])
        ->and($peopleSteps->firstWhere('name', 'Line Manager Review')->hierarchy_route)->toBe('requester_manager')
        ->and($peopleSteps->firstWhere('name', 'HR Review')->approver_user_id)->toBe($f['mehwishUser']->id)
        ->and($peopleSteps->firstWhere('name', 'Level 3 Approval')->approver_user_id)->toBe($f['razaUser']->id)
        ->and($peopleSteps->firstWhere('name', 'Level 4 Approval')->approver_user_id)->toBe($f['khurramUser']->id);

    // Tech: routes level 2 via department_manager (not requester_manager),
    // and its level 3/4 are swapped relative to every other category --
    // Haider Navid then Raza Afzal, per the seeder's documented ROUTES.
    $techSteps = ApprovalWorkflow::query()->where('company_id', $f['company']->id)->where('request_type', 'claim_tech')->firstOrFail()->steps;
    expect($techSteps->pluck('name')->all())->toBe(['HR Review', 'Department Head Review', 'Level 3 Approval', 'Level 4 Approval'])
        ->and($techSteps->firstWhere('name', 'Department Head Review')->hierarchy_route)->toBe('department_manager')
        ->and($techSteps->firstWhere('name', 'Level 3 Approval')->approver_user_id)->toBe($f['haiderUser']->id)
        ->and($techSteps->firstWhere('name', 'Level 4 Approval')->approver_user_id)->toBe($f['razaUser']->id);

    // Real Estate: no business-hierarchy step and no level 3/4 -- just
    // Mehwish (HR Review) then Khurram, pinned by name as "Level 2 Approval".
    $realEstateSteps = ApprovalWorkflow::query()->where('company_id', $f['company']->id)->where('request_type', 'claim_real_estate')->firstOrFail()->steps;
    expect($realEstateSteps->pluck('name')->all())->toBe(['HR Review', 'Level 2 Approval'])
        ->and($realEstateSteps->firstWhere('name', 'Level 2 Approval')->approver_user_id)->toBe($f['khurramUser']->id);
});

// ---------------------------------------------------------------------
// Tax / net payment validation.
// ---------------------------------------------------------------------
it('refuses to submit a claim whose net payment does not equal billed amount minus deductions', function () {
    $f = claimsFixture();
    Auth::login($f['employeeUser']);
    $request = EmployeeRequest::query()->create([
        'company_id'          => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['requestType']->id,
        'title'               => 'Team lunch', 'billed_amount' => '10000.0000', 'income_tax_deduction' => '500.0000',
        'sales_tax_deduction' => '0.0000', 'amount' => '10000.0000', // wrong: should be 9500
        'account_title'       => 'Claimant', 'iban' => 'PK00TEST0000000000000000', 'bank_name' => 'Test Bank',
        'attachments'         => ['employees/requests/receipt.pdf'],
    ]);

    expect(fn () => app(EmployeeRequestService::class)->submit($request, $f['employeeUser']))
        ->toThrow(RuntimeException::class, 'Net payment must equal');
});

it('refuses negative deductions and deductions exceeding the billed amount', function () {
    $f = claimsFixture();
    Auth::login($f['employeeUser']);
    $base = ['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['requestType']->id, 'title' => 'Claim', 'billed_amount' => '1000.0000', 'account_title' => 'Claimant', 'iban' => 'PK00TEST0000000000000000', 'bank_name' => 'Test Bank', 'attachments' => ['employees/requests/receipt.pdf']];

    $negative = EmployeeRequest::query()->create($base + ['income_tax_deduction' => '-1.0000', 'sales_tax_deduction' => '0.0000', 'amount' => '1001.0000']);
    expect(fn () => app(EmployeeRequestService::class)->submit($negative, $f['employeeUser']))
        ->toThrow(RuntimeException::class, 'cannot be negative');

    $overDeducted = EmployeeRequest::query()->create($base + ['income_tax_deduction' => '600.0000', 'sales_tax_deduction' => '600.0000', 'amount' => '1.0000']);
    expect(fn () => app(EmployeeRequestService::class)->submit($overDeducted, $f['employeeUser']))
        ->toThrow(RuntimeException::class, 'cannot exceed the billed amount');
});

// ---------------------------------------------------------------------
// Full pipeline: HR Review -> Line Manager -> Level 3 -> Level 4, the
// real fixed four-level People chain, then posts to Accounting.
// ---------------------------------------------------------------------
it('routes a claim through the real four-level People chain (HR, Line Manager, Level 3, Level 4) and posts a balanced real Bill', function () {
    $f = claimsFixture();
    $service = app(EmployeeRequestService::class);

    Auth::login($f['employeeUser']);
    $request = EmployeeRequest::query()->create([
        'company_id'           => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['requestType']->id,
        'title'                => 'Team lunch', 'billed_amount' => '10000.0000', 'tax_deduction_rate' => '5.0000',
        'income_tax_deduction' => '500.0000', 'sales_tax_deduction' => '0.0000', 'amount' => '9500.0000',
        'account_title'        => 'Claimant Name', 'iban' => 'PK36SCBL0000001123456702', 'bank_name' => 'Standard Chartered',
        'attachments'          => ['employees/requests/receipt.pdf'],
    ]);

    $service->submit($request, $f['employeeUser']);
    $request->refresh();
    expect($request->status)->toBe('pending_approval');

    $service->approve($request, $f['mehwishUser'], 'HR reviewed');
    $service->approve($request->fresh(), $f['managerUser'], 'Line manager approved');
    $service->approve($request->fresh(), $f['razaUser'], 'Level 3 approved');
    $request = $service->approve($request->fresh(), $f['khurramUser'], 'Level 4 approved');

    expect($request->status)->toBe('approved') // all four real, named/hierarchy levels decided
        ->and($request->accounting_move_id)->not->toBeNull();

    $move = $request->accountingMove;
    $lines = $move->lines;
    expect($lines)->toHaveCount(2)
        ->and((float) $lines->sum('debit'))->toBe(9500.0)
        ->and((float) $lines->sum('credit'))->toBe(9500.0) // balanced, and equal to net payment, not billed amount
        ->and($move->accounting_source_type)->toBe('employee_request')
        ->and($move->accounting_source_id)->toBe($request->id)
        // Posted as a real vendor Bill, not a generic Miscellaneous entry:
        // move_type = IN_INVOICE (what makes it "a Bill" in every
        // Bill-scoped view -- Bill/JournalEntry are plain Move subclasses
        // with no scope of their own) and state = POSTED immediately via
        // confirmMove() -- the same behaviour a Drive-ingested vendor bill
        // has today. Posting is not paying: no Payment record exists yet,
        // and registering one remains a fully separate, manual action.
        ->and($move->move_type)->toBe(MoveType::IN_INVOICE)
        ->and($move->state->value)->toBe('posted');

    // The fuller Bill-visibility / Register-Payment-eligibility assertions
    // for this same posting behaviour live in the standalone
    // "posts an approved claim as a real, payable Bill..." test below.

    // Full approval audit trail retained: requester/approver/level/decision/timestamp/reason.
    $decisions = $request->approvalRequest->decisions()->with('step')->get();
    expect($decisions)->toHaveCount(4)
        ->and($decisions->pluck('step.name')->all())->toBe(['HR Review', 'Line Manager Review', 'Level 3 Approval', 'Level 4 Approval'])
        ->and($decisions->pluck('decision')->unique()->all())->toBe(['approved'])
        ->and($decisions->pluck('reason')->all())->toBe(['HR reviewed', 'Line manager approved', 'Level 3 approved', 'Level 4 approved'])
        ->and($decisions->pluck('actor_id')->all())->toBe([$f['mehwishUser']->id, $f['managerUser']->id, $f['razaUser']->id, $f['khurramUser']->id]);
});

// ---------------------------------------------------------------------
// A high-value claim gets no extra/amount-gated step: ClaimsWorkflowSeeder's
// own docblock says VP_FINANCE_THRESHOLD was removed, not merely renamed,
// because it was never part of the client's diagram -- so a 150,000 claim
// walks the identical fixed four levels as a small one, and no legacy
// finance-tier role (like the old 'vp_finance') can stand in for the real
// named Level 4 approver.
// ---------------------------------------------------------------------
it('has no separate amount-gated step -- a high-value claim still walks the same fixed four-level chain, and no legacy finance role can jump it', function () {
    $f = claimsFixture();
    $service = app(EmployeeRequestService::class);

    Auth::login($f['employeeUser']);
    $request = EmployeeRequest::query()->create([
        'company_id'          => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['requestType']->id,
        'title'               => 'Large claim', 'billed_amount' => '150000.0000', 'income_tax_deduction' => '0.0000',
        'sales_tax_deduction' => '0.0000', 'amount' => '150000.0000',
        'account_title'       => 'Claimant Name', 'iban' => 'PK36SCBL0000001123456702', 'bank_name' => 'Standard Chartered',
        'attachments'         => ['employees/requests/receipt.pdf'],
    ]);
    $service->submit($request, $f['employeeUser']);

    $service->approve($request->fresh(), $f['mehwishUser']);
    $service->approve($request->fresh(), $f['managerUser']);
    $afterLevel3 = $service->approve($request->fresh(), $f['razaUser']);

    // Still pending, at the real Level 4 (Khurram) -- amount alone never
    // skips or inserts a step.
    expect($afterLevel3->status)->toBe('pending_approval')
        ->and($afterLevel3->accounting_move_id)->toBeNull() // no accounting draft until fully approved
        ->and($afterLevel3->approvalRequest->currentStep()->name)->toBe('Level 4 Approval')
        ->and(app(ApprovalEngine::class)->canAct($afterLevel3->approvalRequest, $f['khurramUser']))->toBeTrue()
        // A user holding the legacy 'vp_finance' role has no standing here:
        // canAct() only matches the step's exact approver_user_id -- no
        // finance-tier role bypass exists any more -- so a stray role grant
        // can't substitute for Khurram.
        ->and(app(ApprovalEngine::class)->canAct($afterLevel3->approvalRequest, $f['vpUser']))->toBeFalse();

    $final = $service->approve($afterLevel3->fresh(), $f['khurramUser'], 'Level 4 signed off');
    expect($final->status)->toBe('approved')
        ->and($final->accounting_move_id)->not->toBeNull();
});

// ---------------------------------------------------------------------
// Rejection: never reaches Finance, history preserved, resubmittable.
// ---------------------------------------------------------------------
it('a claim rejected by the Line Manager never reaches Finance and is not sent to accounting', function () {
    $f = claimsFixture();
    $service = app(EmployeeRequestService::class);

    Auth::login($f['employeeUser']);
    $request = EmployeeRequest::query()->create([
        'company_id'          => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['requestType']->id,
        'title'               => 'Questionable claim', 'billed_amount' => '5000.0000', 'income_tax_deduction' => '0.0000',
        'sales_tax_deduction' => '0.0000', 'amount' => '5000.0000',
        'account_title'       => 'Claimant Name', 'iban' => 'PK36SCBL0000001123456702', 'bank_name' => 'Standard Chartered',
        'attachments'         => ['employees/requests/receipt.pdf'],
    ]);
    $service->submit($request, $f['employeeUser']);
    $service->approve($request->fresh(), $f['mehwishUser']);

    $rejected = $service->reject($request->fresh(), $f['managerUser'], 'Not a valid business expense');

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->rejection_reason)->toBe('Not a valid business expense')
        ->and($rejected->accounting_move_id)->toBeNull()
        ->and($rejected->approvalRequest->decisions)->toHaveCount(2) // HR approval + the rejection, both retained
        // The real next approver in line (Raza Afzal, Level 3) still cannot
        // act -- the request is terminal.
        ->and(app(ApprovalEngine::class)->canAct($rejected->approvalRequest, $f['razaUser']))->toBeFalse();

    // Resubmittable under existing rules (assertRequestIntegrity allows draft/rejected).
    expect(fn () => $service->submit($rejected->fresh(), $f['employeeUser']))->not->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------
// Bank details never leak through a plain array/JSON representation.
// ---------------------------------------------------------------------
it('never exposes bank details through a plain array or JSON representation of the request', function () {
    $f = claimsFixture();
    $request = EmployeeRequest::query()->create([
        'company_id'    => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['requestType']->id,
        'title'         => 'Claim', 'billed_amount' => '1000.0000', 'amount' => '1000.0000',
        'account_title' => 'Secret Name', 'iban' => 'PK36SECRETIBAN000000001', 'bank_name' => 'Secret Bank',
    ]);

    $array = $request->fresh()->toArray();
    expect($array)->not->toHaveKey('account_title')
        ->not->toHaveKey('iban')
        ->not->toHaveKey('bank_name');
});

// ---------------------------------------------------------------------
// The claims-aware form actually renders (no crash on load, given how
// much reactive/conditional logic the new fields add to a previously
// flat, static form).
// ---------------------------------------------------------------------
it('renders the Employee Requests page for an employee filing their own claim', function () {
    $f = claimsFixture();
    Auth::login($f['employeeUser']);

    Livewire::test(ManageEmployeeRequests::class)->assertOk();
});

it('allows Finance users to view claims, adjust tax deductions, and recalculate Net Payment while pending', function () {
    $f = claimsFixture();
    $service = app(EmployeeRequestService::class);

    Auth::login($f['employeeUser']);
    $request = EmployeeRequest::query()->create([
        'company_id'           => $f['company']->id,
        'employee_id'          => $f['employee']->id,
        'request_type_id'      => $f['requestType']->id,
        'title'                => 'Client Lunch',
        'billed_amount'        => '10000.0000',
        'tax_deduction_rate'   => '0.0000',
        'income_tax_deduction' => '0.0000',
        'sales_tax_deduction'  => '0.0000',
        'amount'               => '10000.0000',
        'account_title'        => 'Claimant Name',
        'iban'                 => 'PK36SCBL0000001123456702',
        'bank_name'            => 'Standard Chartered',
        'attachments'          => ['employees/requests/receipt.pdf'],
    ]);

    $service->submit($request, $f['employeeUser']);
    $request->refresh();
    expect($request->status)->toBe('pending_approval');

    // 1. Finance visibility: controllerUser can see the claim in the resource query
    Auth::login($f['controllerUser']);
    $visibleRequests = EmployeeRequestResource::getEloquentQuery()->pluck('id');
    expect($visibleRequests)->toContain($request->id);

    // 2. Finance can adjust tax deduction: 10% income tax (1,000) and 500 sales tax => Net 8,500
    $request->update([
        'tax_deduction_rate'   => '10.0000',
        'income_tax_deduction' => '1000.0000',
        'sales_tax_deduction'  => '500.0000',
        'amount'               => '8500.0000',
    ]);
    $request->approvalRequest->update(['amount' => '8500.0000']);

    $freshRequest = $request->fresh(['approvalRequest']);
    expect((float) $freshRequest->amount)->toBe(8500.0)
        ->and((float) $freshRequest->approvalRequest->amount)->toBe(8500.0);

    // 3. Approvals proceed with the updated Net Payment, through the real
    // four-level People chain -- Finance's 'controller' role (used above
    // only to view the claim) has no approval standing on this chain; only
    // the named Mehwish/Raza/Khurram and the hierarchy-resolved Line
    // Manager do.
    $service->approve($freshRequest, $f['mehwishUser'], 'HR approved');
    $service->approve($freshRequest->fresh(), $f['managerUser'], 'Manager approved');
    $service->approve($freshRequest->fresh(), $f['razaUser'], 'Level 3 approved');
    $approved = $service->approve($freshRequest->fresh(), $f['khurramUser'], 'Level 4 approved');

    expect($approved->status)->toBe('approved')
        ->and($approved->accounting_move_id)->not->toBeNull();

    $lines = $approved->accountingMove->lines;
    expect((float) $lines->sum('debit'))->toBe(8500.0)
        ->and((float) $lines->sum('credit'))->toBe(8500.0);
});

// ---------------------------------------------------------------------
// The tests above now exercise the real named-approver chain end to end --
// claimsFixture() creates real Users for mehwish@truckitin.com,
// raza.afzal@truckitin.com, khurram@truckitin.com and
// haider.navid@truckitin.com before ClaimsWorkflowSeeder runs, exactly as a
// production company would have them, so $hrUser/$controllerUser/$vpUser's
// ROLES (hr_manager/controller/vp_finance) are no longer what grants
// approval standing on any claims step.
//
// The three tests below still bypass that chain deliberately: their point
// is the Accounting hand-off itself (Bill visibility, idempotency, company
// isolation), not approval ordering, so they take a claim straight to
// status "approved" the same direct way TestHrReportingScenarioTest.php
// already does elsewhere in this plugin -- acceptable because what they
// verify is downstream of approval, not the chain/ordering itself.
// ---------------------------------------------------------------------

it('posts an approved claim as a real, payable Bill visible under Accounting -> Vendors -> Bills', function () {
    $f = claimsFixture();
    $service = app(EmployeeRequestService::class);

    Auth::login($f['controllerUser']);
    $request = EmployeeRequest::query()->create([
        'company_id'       => $f['company']->id,
        'employee_id'      => $f['employee']->id,
        'request_type_id'  => $f['requestType']->id,
        'requested_by'     => $f['employeeUser']->id,
        'title'            => 'Direct-post claim',
        'nature_of_expense'=> 'Team Event',
        'amount'           => '4200.0000',
        'status'           => 'approved',
        'approved_at'      => now(),
    ]);

    $posted = $service->createAccountingDraft($request->fresh(['requestType', 'company']));
    $move = $posted->accountingMove;

    expect($move)->not->toBeNull()
        // Real vendor Bill, not a generic Miscellaneous entry: move_type =
        // IN_INVOICE (what makes it "a Bill" in every Bill-scoped view --
        // Bill/JournalEntry are plain `class X extends Move {}` with no
        // scope of their own) and state = POSTED immediately via
        // confirmMove() -- the same behaviour a Drive-ingested vendor bill
        // has today.
        ->and($move->move_type)->toBe(MoveType::IN_INVOICE)
        ->and($move->state->value)->toBe('posted')
        ->and($move->partner_id)->toBe($f['employee']->fresh()->partner_id)
        // creator_id is the original requester, not whoever's Auth context
        // triggered posting (here, controllerUser) -- required for the
        // request's own row-level permission scope in Accounting.
        ->and($move->creator_id)->toBe($f['employeeUser']->id)
        ->and($move->lines)->toHaveCount(2)
        ->and((float) $move->lines->sum('debit'))->toBe(4200.0)
        ->and((float) $move->lines->sum('credit'))->toBe(4200.0);

    // Now visible under Accounting -> Vendors -> Bills (BillResource scopes
    // strictly by move_type = IN_INVOICE + company_id -- a generic entry
    // never matched this query before).
    expect(BillResource::getEloquentQuery()->whereKey($move->id)->exists())->toBeTrue();

    // The real "Register Payment" action (PayAction) becomes available: its
    // own visibility gate is `state === POSTED && payment_state in
    // [NOT_PAID, PARTIAL, IN_PAYMENT]` -- both now true, whereas neither
    // could ever be true for a MoveType::ENTRY draft (no such action exists
    // on a plain Journal Entry at all). No Payment has been created --
    // that button being available is not the same as it having been used:
    // posting is not paying, and nothing here creates, triggers, or
    // schedules a Payment.
    expect(in_array($move->payment_state, [PaymentState::NOT_PAID, PaymentState::PARTIAL, PaymentState::IN_PAYMENT], true))->toBeTrue()
        ->and($move->matchedPayments)->toBeEmpty();
});

// ---------------------------------------------------------------------
// Idempotency: re-triggering the accounting handoff for an already-posted
// claim must never create a second Bill.
// ---------------------------------------------------------------------
it('does not create a duplicate Bill when the accounting handoff is triggered again for an already-posted claim', function () {
    $f = claimsFixture();
    $service = app(EmployeeRequestService::class);

    Auth::login($f['controllerUser']);
    $request = EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'request_type_id' => $f['requestType']->id,
        'requested_by'    => $f['employeeUser']->id,
        'title'           => 'Repeat-safe claim',
        'amount'          => '2000.0000',
        'status'          => 'approved',
        'approved_at'     => now(),
    ]);

    $posted = $service->createAccountingDraft($request->fresh(['requestType', 'company']));
    $moveId = $posted->accounting_move_id;
    expect($moveId)->not->toBeNull();
    $moveCountAfterFirstPost = Move::query()->count();

    // Re-run the exact handoff method directly (simulating a retried event
    // / re-delivered queue job) -- the guard at the top of
    // createAccountingDraft() (accounting_move_id already set) must
    // short-circuit before any new Move is built.
    $again = $service->createAccountingDraft($posted->fresh(['requestType', 'company']));
    expect($again->accounting_move_id)->toBe($moveId)
        ->and(Move::query()->count())->toBe($moveCountAfterFirstPost);
});

// ---------------------------------------------------------------------
// Company isolation: a claim's Bill belongs to, and is only visible
// within, its own company.
// ---------------------------------------------------------------------
it('keeps a claim-originated Bill scoped to its own company', function () {
    $f = claimsFixture();
    $service = app(EmployeeRequestService::class);

    Auth::login($f['controllerUser']);
    $request = EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'request_type_id' => $f['requestType']->id,
        'requested_by'    => $f['employeeUser']->id,
        'title'           => 'Isolation check claim',
        'amount'          => '3000.0000',
        'status'          => 'approved',
        'approved_at'     => now(),
    ]);
    $approved = $service->createAccountingDraft($request->fresh(['requestType', 'company']));

    $move = $approved->accountingMove;
    expect((int) $move->company_id)->toBe((int) $f['company']->id);

    // A user whose default company is a completely different company must
    // not see this Bill through BillResource's own company-scoped query --
    // not just "the FK says company A", the actual resource query too.
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherUser = User::factory()->create(['default_company_id' => $otherCompany->id, 'is_active' => true]);
    $otherUser->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);

    Auth::login($otherUser);
    expect(BillResource::getEloquentQuery()->whereKey($move->id)->exists())->toBeFalse();

    // Back under the owning company's user, it is visible again -- confirms
    // the prior false was company scoping, not some other broken condition.
    Auth::login($f['controllerUser']);
    expect(BillResource::getEloquentQuery()->whereKey($move->id)->exists())->toBeTrue();
});
