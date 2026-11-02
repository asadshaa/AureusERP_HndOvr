<?php

/**
 * accounting:provision-company: ShipLine (and any later company) gets Truck It
 * In's shared chart of accounts linked through accounts_account_companies, its
 * journals' default accounts, an Exchange Difference journal, FX accounts,
 * payment terms, fiscal positions and FS tags, plus expense-claim types with a
 * company-local approval chain. Balances stay per company because every
 * ledger line carries company_id.
 */

use Database\Seeders\FinanceRoleSeeder;
use Database\Seeders\HrRoleSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\FiscalPosition;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\CompanyAccountingProvisioningService;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Employee\Database\Seeders\ClaimsWorkflowSeeder;
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

/** @return array{0: Company, 1: Company} ShipLine (company 25) and Truck It In (company 1). */
function provisioningCompanies(): array
{
    $shipLine = Company::query()->find(25);

    if (! $shipLine) {
        test()->markTestSkipped('ShipLine (company 25) is not in this database.');
    }

    Auth::login(User::factory()->create(['default_company_id' => $shipLine->id, 'is_active' => true]));

    return [$shipLine, Company::query()->findOrFail(1)];
}

function provisionShipLine(): array
{
    [$shipLine, $tii] = provisioningCompanies();

    app(CompanyAccountingProvisioningService::class)->provision($shipLine, $tii);

    return [$shipLine->fresh(), $tii];
}

function provisioningJournal(Company $company, string $code): Journal
{
    return Journal::query()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
}

/** Posts a one-line, untaxed invoice or bill through AccountManager::confirmMove(). */
function provisioningPostedMove(Company $company, MoveType $type, Partner $partner, float $amount): Move
{
    $journal = provisioningJournal($company, $type === MoveType::IN_INVOICE ? 'BILL' : 'INV');

    $move = Move::query()->create([
        'company_id'   => $company->id, 'journal_id' => $journal->id, 'partner_id' => $partner->id,
        'currency_id'  => $company->currency_id, 'move_type' => $type, 'state' => MoveState::DRAFT,
        'date'         => now()->toDateString(), 'invoice_date' => now()->toDateString(), 'invoice_date_due' => now()->addDays(30)->toDateString(),
    ]);
    MoveLine::query()->create([
        'move_id'      => $move->id, 'account_id' => $journal->default_account_id, 'partner_id' => $partner->id,
        'currency_id'  => $company->currency_id, 'display_type' => DisplayType::PRODUCT,
        'name'         => 'Freight service', 'quantity' => 1, 'price_unit' => $amount,
    ]);

    return AccountFacade::confirmMove($move->fresh());
}

function provisioningPartner(?Company $company = null): Partner
{
    return Partner::query()->create([
        'company_id' => $company?->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'name'       => 'Provisioning partner '.str()->random(6),
    ]);
}

function companyAccountIds(Company $company): array
{
    return DB::table('accounts_account_companies')->where('company_id', $company->id)->pluck('account_id')->sort()->values()->all();
}

it('links the shared Truck It In chart to ShipLine and mirrors its journals, terms, positions and FS tags, idempotently', function () {
    [$shipLine, $tii] = provisioningCompanies();
    $service = app(CompanyAccountingProvisioningService::class);
    $tiiAccountsBefore = companyAccountIds($tii);
    $tiiJournalsBefore = Journal::query()->where('company_id', $tii->id)->orderBy('id')->get()->toArray();

    $service->provision($shipLine, $tii);
    $second = $service->provision($shipLine->fresh(), $tii);

    expect(collect($second)->except('warnings')->sum())->toBe(0)
        ->and(companyAccountIds($tii))->toBe($tiiAccountsBefore)
        ->and(Journal::query()->where('company_id', $tii->id)->orderBy('id')->get()->toArray())->toBe($tiiJournalsBefore)
        ->and(array_diff($service->sharedTemplateAccounts($tii)->modelKeys(), companyAccountIds($shipLine)))->toBe([]);

    foreach (['INV', 'BILL', 'BANK', 'CASH'] as $code) {
        $tiiJournal = provisioningJournal($tii, $code);
        $journal = provisioningJournal($shipLine, $code);

        expect($journal->default_account_id)->toBe($tiiJournal->default_account_id)
            ->and($journal->type)->toBe($tiiJournal->type);
    }

    foreach (['BANK', 'CASH'] as $code) {
        expect(PaymentMethodLine::query()->where('journal_id', provisioningJournal($shipLine, $code)->id)->pluck('payment_method_id')->sort()->values()->all())
            ->toBe(PaymentMethodLine::query()->where('journal_id', provisioningJournal($tii, $code)->id)->pluck('payment_method_id')->sort()->values()->all());
    }

    $exchange = provisioningJournal($shipLine, 'EXCH');
    $tiiFx = AccountFacade::exchangeDifferenceConfiguration($tii);
    $shipLine->refresh();
    expect($exchange->type)->toBe(JournalType::GENERAL)
        ->and($shipLine->fx_gain_account_id)->toBe($tiiFx['income_account_id'])
        ->and($shipLine->fx_loss_account_id)->toBe($tiiFx['expense_account_id'])
        ->and(AccountFacade::exchangeDifferenceConfiguration($shipLine))->toBe([
            'journal_id'         => $exchange->id,
            'income_account_id'  => $tiiFx['income_account_id'],
            'expense_account_id' => $tiiFx['expense_account_id'],
        ])
        ->and(Journal::query()->find($tiiFx['journal_id'])->company_id)->toBe($tii->id);

    foreach (PaymentTerm::query()->where('company_id', $tii->id)->with('dueTerms')->get() as $tiiTerm) {
        $term = PaymentTerm::query()->where('company_id', $shipLine->id)->where('name', $tiiTerm->name)->with('dueTerms')->firstOrFail();

        expect($term->dueTerms->map->only(['value', 'value_amount', 'delay_type', 'nb_days'])->all())
            ->toEqual($tiiTerm->dueTerms->map->only(['value', 'value_amount', 'delay_type', 'nb_days'])->all());
    }

    foreach (FiscalPosition::query()->where('company_id', $tii->id)->with('taxes.taxSource')->get() as $tiiPosition) {
        $position = FiscalPosition::query()->where('company_id', $shipLine->id)->where('name', $tiiPosition->name)->with('taxes.taxSource')->firstOrFail();

        expect($position->taxes->pluck('taxSource.name')->all())->toBe($tiiPosition->taxes->pluck('taxSource.name')->all())
            ->and($position->taxes->pluck('taxSource.company_id')->unique()->filter()->values()->all())->toBe($position->taxes->isEmpty() ? [] : [$shipLine->id]);
    }

    $tiiTags = FsTag::query()->where('company_id', $tii->id)->orderBy('code')->get(['code', 'account_id', 'is_active'])->toArray();
    expect(FsTag::query()->where('company_id', $shipLine->id)->orderBy('code')->get(['code', 'account_id', 'is_active'])->toArray())->toBe($tiiTags);
});

it('never shares a company-owned template account such as a bank GL', function () {
    [$shipLine, $tii] = provisioningCompanies();
    $bankGl = Account::query()->create(['code' => 'TIIBANK'.str()->random(4), 'name' => 'TII real bank', 'account_type' => AccountType::ASSET_CASH, 'is_group' => false, 'deprecated' => false]);
    $bankGl->companies()->attach($tii->id);
    DB::table('accounting_account_details')->insert(['account_id' => $bankGl->id, 'company_id' => $tii->id, 'bank_name' => 'TII Bank', 'created_at' => now(), 'updated_at' => now()]);

    app(CompanyAccountingProvisioningService::class)->provision($shipLine, $tii);

    expect(companyAccountIds($shipLine))->not->toContain($bankGl->id);
});

it('posts a balanced ShipLine customer invoice and vendor bill on ShipLine-linked accounts', function () {
    [$shipLine] = provisionShipLine();
    $partner = provisioningPartner($shipLine);
    $linked = companyAccountIds($shipLine);

    $invoice = provisioningPostedMove($shipLine, MoveType::OUT_INVOICE, $partner, 10000);
    $bill = provisioningPostedMove($shipLine, MoveType::IN_INVOICE, $partner, 4000);

    foreach ([[$invoice, AccountType::ASSET_RECEIVABLE], [$bill, AccountType::LIABILITY_PAYABLE]] as [$move, $termType]) {
        $lines = $move->lines()->with('account')->get();

        expect($move->state)->toBe(MoveState::POSTED)
            ->and($move->company_id)->toBe($shipLine->id)
            ->and($move->journal->company_id)->toBe($shipLine->id)
            ->and($lines)->toHaveCount(2)
            ->and(round((float) $lines->sum('debit'), 2))->toBe(round((float) $lines->sum('credit'), 2))
            ->and($lines->pluck('company_id')->unique()->all())->toBe([$shipLine->id])
            ->and(array_diff($lines->pluck('account_id')->all(), $linked))->toBe([])
            ->and($lines->pluck('account.account_type')->contains($termType))->toBeTrue();
    }

    expect((float) $invoice->amount_total)->toBe(10000.0)
        ->and((float) $bill->amount_total)->toBe(4000.0);
});

it('keeps Truck It In balances and trial balance unchanged when ShipLine posts to the shared accounts', function () {
    [$shipLine, $tii] = provisionShipLine();
    $trialBalance = app(TrialBalanceService::class);
    $from = now()->startOfYear()->toDateString();
    $to = now()->endOfYear()->toDateString();
    $byAccount = fn (array $report): array => collect($report['rows'])->keyBy('account_id')->all();

    $tiiBefore = $trialBalance->compute($tii->id, $from, $to);
    $shipLineBefore = $byAccount($trialBalance->compute($shipLine->id, $from, $to));

    $partner = provisioningPartner();
    $invoice = provisioningPostedMove($shipLine, MoveType::OUT_INVOICE, $partner, 10000);
    provisioningPostedMove($shipLine, MoveType::IN_INVOICE, $partner, 4000);

    $shipLineAfter = $trialBalance->compute($shipLine->id, $from, $to);
    $salesAccountId = provisioningJournal($shipLine, 'INV')->default_account_id;
    $receivableAccountId = $invoice->lines()->where('display_type', DisplayType::PAYMENT_TERM)->value('account_id');
    $after = $byAccount($shipLineAfter);

    expect($trialBalance->compute($tii->id, $from, $to))->toBe($tiiBefore)
        ->and(provisioningJournal($tii, 'INV')->default_account_id)->toBe($salesAccountId) // the same shared account row
        ->and($shipLineAfter['totals']['difference'])->toBe(0.0)
        ->and($after[$salesAccountId]['movement_credit'] - ($shipLineBefore[$salesAccountId]['movement_credit'] ?? 0))->toBe(10000.0)
        ->and($after[$receivableAccountId]['movement_debit'] - ($shipLineBefore[$receivableAccountId]['movement_debit'] ?? 0))->toBe(10000.0);
});

it('offers an invoice only its own company\'s outstanding credits on a shared receivable account', function () {
    [$shipLine, $tii] = provisionShipLine();
    $receivable = Account::query()->where('code', '121000')->whereHas('companies', fn ($query) => $query->where('companies.id', $tii->id))->firstOrFail();
    $partner = provisioningPartner();
    $partner->update(['property_account_receivable_id' => $receivable->id]);

    $tiiCredit = provisioningPostedMove($tii, MoveType::OUT_REFUND, $partner, 3000);
    $tiiInvoice = provisioningPostedMove($tii, MoveType::OUT_INVOICE, $partner, 5000);
    $shipLineInvoice = provisioningPostedMove($shipLine, MoveType::OUT_INVOICE, $partner, 5000);

    $creditLineId = $tiiCredit->lines()->where('account_id', $receivable->id)->value('id');

    expect($shipLineInvoice->lines()->where('display_type', DisplayType::PAYMENT_TERM)->value('account_id'))->toBe($receivable->id)
        ->and(collect($tiiInvoice->fresh()->getReconcilablePayments()['lines'])->pluck('id'))->toContain($creditLineId)
        ->and(collect($shipLineInvoice->fresh()->getReconcilablePayments()['lines'])->pluck('id'))->not->toContain($creditLineId);
});

it('routes a ShipLine claim line manager -> ShipLine HR -> ShipLine finance and posts a ShipLine vendor bill', function () {
    [$shipLine] = provisionShipLine();
    app(HrRoleSeeder::class)->run();
    app(FinanceRoleSeeder::class)->run();

    $member = function (string ...$roles) use ($shipLine): User {
        $user = User::factory()->create(['default_company_id' => $shipLine->id, 'is_active' => true]);
        foreach ($roles as $role) {
            $user->assignRole(Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail());
        }

        return $user;
    };
    $hrUser = $member('hr_manager');
    $financeUser = $member('accounting_manager');
    $managerUser = $member();
    $employeeUser = $member();
    $tiiHr = User::factory()->create(['default_company_id' => 1, 'is_active' => true]);
    $tiiHr->assignRole(Role::query()->where('name', 'hr_manager')->where('guard_name', 'web')->firstOrFail());
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ApprovalEngine::flushMemo();

    $department = Department::factory()->create(['company_id' => $shipLine->id, 'manager_id' => null]);
    $manager = Employee::query()->create(['company_id' => $shipLine->id, 'department_id' => $department->id, 'user_id' => $managerUser->id, 'name' => 'ShipLine Line Manager']);
    $employee = Employee::query()->create(['company_id' => $shipLine->id, 'department_id' => $department->id, 'parent_id' => $manager->id, 'user_id' => $employeeUser->id, 'name' => 'ShipLine Claimant']);

    $summary = app(ClaimsWorkflowSeeder::class)->provisionForCompany($shipLine);
    $requestType = EmployeeRequestType::query()->where('company_id', $shipLine->id)->where('code', 'claim_people')->firstOrFail();
    $steps = ApprovalWorkflow::query()->where('company_id', $shipLine->id)->where('request_type', 'claim_people')->firstOrFail()->steps()->orderBy('sequence')->get();

    expect($summary['chain'])->toBe('company')
        ->and(EmployeeRequestType::query()->where('company_id', $shipLine->id)->where('code', 'like', 'claim_%')->count())->toBe(9)
        ->and($requestType->is_financial)->toBeTrue()
        ->and($requestType->journal_id)->toBe(provisioningJournal($shipLine, 'BILL')->id)
        ->and(companyAccountIds($shipLine))->toContain($requestType->debit_account_id)
        ->and($steps->pluck('name')->all())->toBe(['Line Manager Review', 'HR Review', 'Finance Review'])
        ->and($steps->pluck('approver_user_id')->filter()->all())->toBe([]);

    $service = app(EmployeeRequestService::class);
    Auth::login($employeeUser);
    $request = EmployeeRequest::query()->create([
        'company_id'           => $shipLine->id, 'employee_id' => $employee->id, 'request_type_id' => $requestType->id,
        'title'                => 'Client visit fuel', 'billed_amount' => '10000.0000', 'tax_deduction_rate' => '5.0000',
        'income_tax_deduction' => '500.0000', 'sales_tax_deduction' => '0.0000', 'amount' => '9500.0000',
        'account_title'        => 'ShipLine Claimant', 'iban' => 'PK36SCBL0000001123456702', 'bank_name' => 'Standard Chartered',
        'attachments'          => ['employees/requests/receipt.pdf'],
    ]);
    $service->submit($request, $employeeUser);

    $engine = app(ApprovalEngine::class);
    $request = $service->approve($request->fresh(), $managerUser, 'Line manager approved');
    expect($engine->canAct($request->approvalRequest, $tiiHr))->toBeFalse() // another company's HR manager has no say
        ->and($engine->canAct($request->approvalRequest, $hrUser))->toBeTrue();
    $request = $service->approve($request->fresh(), $hrUser, 'HR approved');
    expect($request->accounting_move_id)->toBeNull()
        ->and($engine->resolveAuthorizedApprovers($request->approvalRequest)->pluck('id'))->toContain($financeUser->id);
    $request = $service->approve($request->fresh(), $financeUser, 'Finance approved');

    $bill = $request->accountingMove;
    $lines = $bill->lines;
    expect($request->status)->toBe('approved')
        ->and($request->approvalRequest->decisions()->pluck('actor_id')->all())->toBe([$managerUser->id, $hrUser->id, $financeUser->id])
        ->and($bill->move_type)->toBe(MoveType::IN_INVOICE)
        ->and($bill->state)->toBe(MoveState::POSTED)
        ->and($bill->company_id)->toBe($shipLine->id)
        ->and($bill->journal_id)->toBe($requestType->journal_id)
        ->and($lines->pluck('company_id')->unique()->all())->toBe([$shipLine->id])
        ->and((float) $lines->sum('debit'))->toBe(9500.0)
        ->and((float) $lines->sum('credit'))->toBe(9500.0)
        ->and($lines->pluck('account_id'))->toContain($requestType->debit_account_id);
});

it('keeps the named org-chart approvers on Truck It In claims and never puts them on ShipLine claims', function () {
    [$shipLine, $tii] = provisionShipLine();
    $named = fn (string $email): User => User::query()->where('email', $email)->firstOrFail();
    $claimSteps = fn (Company $company): array => ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', 'like', 'claim_%')
        ->with('steps')->orderBy('id')->get()->flatMap->steps->map->only(['id', 'workflow_id', 'sequence', 'name', 'approver_user_id', 'approver_role_id', 'hierarchy_route'])->all();
    $tiiBefore = $claimSteps($tii);

    app(ClaimsWorkflowSeeder::class)->provisionForCompany($shipLine);
    $shipLineSteps = $claimSteps($shipLine);
    app(ClaimsWorkflowSeeder::class)->provisionForCompany($shipLine->fresh());

    $people = ApprovalWorkflow::query()->where('company_id', $tii->id)->where('request_type', 'claim_people')->firstOrFail()->steps()->orderBy('sequence')->get();
    expect($claimSteps($tii))->toBe($tiiBefore)
        ->and($people->pluck('name')->all())->toBe(['HR Review', 'Line Manager Review', 'Level 3 Approval', 'Level 4 Approval'])
        ->and($people->pluck('approver_user_id')->filter()->values()->all())->toBe([$named('mehwish@truckitin.com')->id, $named('raza.afzal@truckitin.com')->id, $named('khurram@truckitin.com')->id])
        ->and($claimSteps($shipLine))->toBe($shipLineSteps) // idempotent
        ->and(collect($shipLineSteps)->pluck('approver_user_id')->filter()->all())->toBe([]);

    // The named chain still goes to the company the named people work for.
    $home = Company::factory()->create(['name' => 'Named Chain Co '.str()->random(4), 'currency_id' => $tii->currency_id, 'is_active' => true]);
    $named('mehwish@truckitin.com')->update(['default_company_id' => $home->id]);

    expect(app(ClaimsWorkflowSeeder::class)->provisionForCompany($home)['chain'])->toBe('named')
        ->and(ApprovalWorkflow::query()->where('company_id', $home->id)->where('request_type', 'claim_people')->firstOrFail()->steps()->where('sequence', 1)->value('approver_user_id'))
        ->toBe($named('mehwish@truckitin.com')->id);
});

it('replaces the named org-chart chain when it was seeded into another company', function () {
    [$shipLine] = provisionShipLine();
    $workflow = ApprovalWorkflow::query()->firstOrCreate(['company_id' => $shipLine->id, 'request_type' => 'claim_others'], ['name' => 'Others Approval', 'is_active' => true]);
    $workflow->steps()->delete();
    $workflow->steps()->create(['sequence' => 1, 'name' => 'HR Review', 'approver_user_id' => User::query()->where('email', 'mehwish@truckitin.com')->firstOrFail()->id, 'required_approvals' => 1]);

    app(ClaimsWorkflowSeeder::class)->provisionForCompany($shipLine);

    expect($workflow->steps()->orderBy('sequence')->pluck('hierarchy_route')->take(2)->all())->toBe(['requester_manager', 'hr'])
        ->and($workflow->steps()->whereNotNull('approver_user_id')->exists())->toBeFalse();
});

it('provisions a brand-new company through the command, idempotently', function () {
    provisioningCompanies();
    $pkr = Currency::query()->findOrFail(Company::query()->findOrFail(1)->currency_id);
    $company = Company::factory()->create(['name' => 'Provisioned Co '.str()->random(4), 'currency_id' => $pkr->id, 'is_active' => true]);

    $this->artisan('accounting:provision-company', ['company' => [$company->id]])
        ->expectsOutputToContain('Action needed: Create a bank GL account')
        ->assertSuccessful();

    $journals = Journal::query()->where('company_id', $company->id)->get()->keyBy('code');
    expect($journals->keys()->sort()->values()->all())->toBe(['BANK', 'BILL', 'CASH', 'EXCH', 'INV', 'MISC'])
        ->and($journals['INV']->default_account_id)->toBe(provisioningJournal(Company::query()->findOrFail(1), 'INV')->default_account_id)
        ->and(EmployeeRequestType::query()->where('company_id', $company->id)->where('code', 'like', 'claim_%')->where('is_financial', true)->count())->toBe(9);

    $linked = companyAccountIds($company);
    $this->artisan('accounting:provision-company', ['company' => [$company->id]])
        ->expectsTable(['Item', 'Count'], [
            ['Accounts linked', 0], ['Journals created', 0], ['Journals given default accounts', 0], ['Payment methods added to journals', 0],
            ['FX gain / loss accounts set', 0], ['Payment terms created', 0], ['Fiscal positions created', 0], ['FS tags created', 0],
        ])
        ->assertSuccessful();
    expect(companyAccountIds($company))->toBe($linked);
});

it('lists the missing NTN, STRN and bank accounts as user actions', function () {
    [$shipLine] = provisioningCompanies();
    $shipLine->update(['tax_id' => null, 'strn' => null, 'is_sales_tax_registered' => false]);

    $this->artisan('accounting:provision-company', ['company' => [$shipLine->id]])
        ->expectsOutputToContain('Action needed: Enter the NTN')
        ->expectsOutputToContain('Action needed: Enter the STRN')
        ->expectsOutputToContain('Action needed: Create a bank GL account')
        ->assertSuccessful();
});

it('refuses to provision the template itself or a company with another base currency', function () {
    [, $tii] = provisioningCompanies();
    $aed = Currency::query()->where('code', 'AED')->firstOrFail();
    $foreign = Company::factory()->create(['name' => 'Foreign Co '.str()->random(4), 'currency_id' => $aed->id, 'is_active' => true]);

    $this->artisan('accounting:provision-company', ['company' => [$tii->id]])->assertFailed();

    expect(fn () => app(CompanyAccountingProvisioningService::class)->provision($foreign, $tii))
        ->toThrow(RuntimeException::class, 'different base currency')
        ->and(companyAccountIds($foreign))->toBe([]);
});
