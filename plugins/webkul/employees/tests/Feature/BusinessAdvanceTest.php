<?php

/**
 * Business advances: money an employee asks for before spending it on company
 * work. Covers the request (validation, settle-by date, limits, the overdue
 * block), final approval posting a vendor bill that debits Employee Advances,
 * settlement (approval posts Dr Expense / Cr Employee Advances, once), cash
 * handed back (Dr Bank or Cash / Cr Employee Advances), the closed-period and
 * company-isolation guards, the provisioner and the reminders. Fresh factory
 * companies only: nothing here depends on the data already in the database.
 */

use Carbon\CarbonImmutable;
use Database\Seeders\HrRoleSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Services\PeriodLockService;
use Webkul\Employee\Models\AdvanceEntry;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\Advances\AdvanceProvisioner;
use Webkul\Employee\Services\Advances\AdvanceReminderService;
use Webkul\Employee\Services\Advances\AdvanceService;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\AdvanceKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

beforeEach(function () {
    // Monday 16 Nov 2026, 11:00 in Karachi.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 06:00:00', 'UTC'));
    $this->requests = app(EmployeeRequestService::class);
    $this->advances = app(AdvanceService::class);
});

function advUser(Company $company, ?string $role = null, array $permissions = []): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role !== null || $permissions !== []) {
        $roleModel = Role::query()->firstOrCreate(['name' => $role ?? 'adv-'.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $name) {
            $roleModel->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }
        $user->assignRole($roleModel);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/**
 * One company with a purchase, a general and a bank journal, the expense and
 * payable accounts the provisioner looks for, an employee with a line manager,
 * HR and a finance user, and the advance types provisioned for it.
 *
 * @return array<string, mixed>
 */
function advFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));
    app(HrRoleSeeder::class)->run();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    foreach (['600000', '211000'] as $code) {
        Account::query()->where('code', $code)->firstOrFail()->companies()->syncWithoutDetaching([$company->id]);
    }

    $bankAccount = Account::factory()->create(['currency_id' => $currency->id, 'account_type' => AccountType::ASSET_CASH, 'is_group' => false, 'deprecated' => false]);
    $bankAccount->companies()->attach($company->id);

    $journals = [];
    foreach ([['PURCHASE', JournalType::PURCHASE], ['GENERAL', JournalType::GENERAL], ['BANK', JournalType::BANK]] as [$code, $type]) {
        $journals[$code] = Journal::factory()->create(['company_id' => $company->id, 'currency_id' => $currency->id, 'type' => $type, 'code' => $code.'-'.$company->id, 'default_account_id' => $type === JournalType::BANK ? $bankAccount->id : null]);
    }

    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);
    $managerUser = advUser($company);
    $manager = Employee::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'user_id' => $managerUser->id, 'name' => 'Line Manager']);
    $employeeUser = advUser($company);
    $employee = Employee::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'parent_id' => $manager->id, 'user_id' => $employeeUser->id, 'name' => 'Ali Advance']);
    $hrUser = advUser($company, 'hr_manager');
    $financeUser = advUser($company, null, [HrPermissions::ProcessFinancialRequests]);

    $summary = app(AdvanceProvisioner::class)->provisionForCompany($company);
    $type = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', AdvanceKinds::AdvanceCode)->firstOrFail();
    $settlementType = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', AdvanceKinds::SettlementCode)->firstOrFail();

    return compact('company', 'currency', 'journals', 'bankAccount', 'manager', 'managerUser', 'employee', 'employeeUser', 'hrUser', 'financeUser', 'summary', 'type', 'settlementType');
}

function advAsk(array $f, string $amount = '50000', array $payload = [], ?Employee $employee = null, ?User $by = null): EmployeeRequest
{
    $by ??= $f['employeeUser'];
    Auth::login($by);

    return EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => ($employee ?? $f['employee'])->id,
        'request_type_id' => $f['type']->id,
        'title'           => 'Lahore client visit',
        'amount'          => $amount,
        'payload'         => $payload + ['purpose' => 'Hotel, fuel and meals for the Lahore client visit', 'needed_by' => '2026-11-18'],
        'account_title'   => 'Ali Advance', 'iban' => 'PK36SCBL0000001123456702', 'bank_name' => 'Standard Chartered',
    ]);
}

/** An advance asked for, approved by the line manager and HR, and paid out (the bill posted). */
function advPaidOut(array $f, string $amount = '50000'): EmployeeRequest
{
    $request = advAsk($f, $amount);
    app(EmployeeRequestService::class)->submit($request, $f['employeeUser']);
    app(EmployeeRequestService::class)->approve($request->fresh(), $f['managerUser'], 'Approved');

    return app(EmployeeRequestService::class)->approve($request->fresh(), $f['hrUser'], 'Approved')->fresh();
}

function advLines(int $moveId): array
{
    return DB::table('accounts_account_move_lines')->where('move_id', $moveId)->orderBy('sort')->get()->all();
}

// ---------------------------------------------------------------------
// Provisioning
// ---------------------------------------------------------------------
it('provisions the advance types, workflows and the Employee Advances account, idempotently', function () {
    $f = advFixture();
    $company = $f['company'];

    expect($f['summary']['skipped'])->toBeNull()
        ->and($f['type']->is_financial)->toBeTrue()
        ->and($f['type']->requires_document)->toBeFalse()
        ->and($f['type']->journal_id)->toBe($f['journals']['PURCHASE']->id)
        ->and($f['settlementType']->is_financial)->toBeFalse()
        ->and($f['settlementType']->requires_document)->toBeTrue()
        ->and($f['settlementType']->journal_id)->toBe($f['journals']['GENERAL']->id);

    $advancesAccount = Account::query()->where('code', AdvanceKinds::AdvancesAccountCode)->firstOrFail();
    expect($advancesAccount->account_type)->toBe(AccountType::ASSET_CURRENT)
        ->and($advancesAccount->reconcile)->toBeTrue()
        ->and($advancesAccount->companies->pluck('id')->all())->toContain($company->id)
        ->and($f['type']->debit_account_id)->toBe($advancesAccount->id);

    $steps = ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', AdvanceKinds::AdvanceCode)->firstOrFail()->steps()->orderBy('sequence')->pluck('hierarchy_route', 'name')->all();
    expect($steps)->toMatchArray(['Line Manager Review' => 'requester_manager', 'HR Review' => 'hr']);

    $again = app(AdvanceProvisioner::class)->provisionForCompany($company);
    expect($again['types_created'])->toBe(0)->and($again['workflows_created'])->toBe(0)->and($again['steps_created'])->toBe(0)
        ->and(EmployeeRequestType::query()->where('company_id', $company->id)->whereIn('code', [AdvanceKinds::AdvanceCode, AdvanceKinds::SettlementCode])->count())->toBe(2);
});

it('skips a company that has no purchase or general journal yet', function () {
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['is_active' => true]);

    $summary = app(AdvanceProvisioner::class)->provisionForCompany($company);

    expect($summary['skipped'])->not->toBeNull()
        ->and(EmployeeRequestType::query()->where('company_id', $company->id)->where('category', AdvanceKinds::AdvanceCategory)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------
// Asking for an advance
// ---------------------------------------------------------------------
it('sends an advance for approval and keeps the settle-by date (needed-by plus the type window)', function () {
    $f = advFixture();
    $request = advAsk($f);

    $this->requests->submit($request, $f['employeeUser']);

    $request->refresh();
    expect($request->status)->toBe('pending_approval')
        ->and($request->payload['kind'])->toBe(AdvanceKinds::AdvanceCategory)
        ->and($request->payload['needed_by'])->toBe('2026-11-18')
        ->and($request->payload['settle_by'])->toBe('2026-12-02');
});

it('refuses an advance without a purpose, without a date, or needed in the past', function (array $payload, string $message) {
    $f = advFixture();
    $request = advAsk($f, '50000', $payload);

    expect(fn () => $this->requests->submit($request, $f['employeeUser']))->toThrow(RuntimeException::class, $message);
})->with([
    'no purpose'   => [['purpose' => ''], 'what the advance is for'],
    'no date'      => [['needed_by' => ''], 'date you need the money by'],
    'date in past' => [['needed_by' => '2026-11-10'], 'cannot be in the past'],
]);

it('refuses an advance above the limit set on the request type', function () {
    $f = advFixture();
    $f['type']->update(['configuration' => [...$f['type']->configuration, AdvanceKinds::MaxAmountKey => '100000']]);

    expect(fn () => $this->requests->submit(advAsk($f, '150000'), $f['employeeUser']))->toThrow(RuntimeException::class, 'cannot be more than 100,000.00');
    expect($this->requests->submit(advAsk($f, '100000'), $f['employeeUser']))->not->toBeNull();
});

it('refuses a new advance while an earlier one is overdue, unless the type allows it', function () {
    $f = advFixture();
    $earlier = advPaidOut($f);
    $earlier->forceFill(['payload' => [...$earlier->payload, 'settle_by' => '2026-11-15']])->save();

    expect(fn () => $this->requests->submit(advAsk($f), $f['employeeUser']))->toThrow(RuntimeException::class, 'You still have an advance of 50,000.00');

    $f['type']->update(['configuration' => [...$f['type']->configuration, AdvanceKinds::BlockWhenOverdueKey => '0']]);

    expect($this->requests->submit(advAsk($f), $f['employeeUser']))->not->toBeNull();
});

// ---------------------------------------------------------------------
// Paying out
// ---------------------------------------------------------------------
it('posts an approved advance as a vendor bill to the employee that debits Employee Advances', function () {
    $f = advFixture();
    $advance = advPaidOut($f);

    expect($advance->status)->toBe('approved')->and($advance->accounting_move_id)->not->toBeNull();

    $move = DB::table('accounts_account_moves')->where('id', $advance->accounting_move_id)->first();
    $advancesAccountId = Account::query()->where('code', AdvanceKinds::AdvancesAccountCode)->value('id');
    $lines = advLines($move->id);

    expect($move->move_type)->toBe('in_invoice')->and($move->state)->toBe('posted')
        ->and((int) $move->partner_id)->toBe((int) $f['employee']->partner_id)
        ->and((int) $lines[0]->account_id)->toBe((int) $advancesAccountId)
        ->and((float) $lines[0]->debit)->toBe(50000.0);

    $summary = $this->advances->summary($advance);
    expect($summary['is_posted'])->toBeTrue()->and($summary['outstanding'])->toBe('50000.00')->and($summary['is_closed'])->toBeFalse();
});

// ---------------------------------------------------------------------
// Settling
// ---------------------------------------------------------------------
it('settles part of an advance: approval posts Dr expense / Cr Employee Advances once and leaves the rest outstanding', function () {
    $f = advFixture();
    $advance = advPaidOut($f);

    $settlement = $this->advances->startSettlement($f['employeeUser'], $advance, '30000', ['employees/requests/receipt-1.pdf'], 'Hotel and fuel for the Lahore trip', '2026-11-18');

    expect($settlement->status)->toBe('pending_approval')
        ->and($settlement->advance_request_id)->toBe($advance->id)
        ->and($settlement->requestType->category)->toBe(AdvanceKinds::SettlementCategory)
        ->and($this->advances->summary($advance->fresh())['pending_settlement_id'])->toBe($settlement->id)
        ->and($this->advances->summary($advance->fresh())['outstanding'])->toBe('50000.00');

    $this->requests->approve($settlement->fresh(), $f['managerUser']);
    $final = $this->requests->approve($settlement->fresh(), $f['hrUser']);
    expect($final->status)->toBe('approved')->and($final->accounting_move_id)->toBeNull();

    $entry = AdvanceEntry::query()->where('source_request_id', $settlement->id)->firstOrFail();
    $move = DB::table('accounts_account_moves')->where('id', $entry->accounting_move_id)->first();
    $lines = advLines($move->id);
    $advancesAccountId = Account::query()->where('code', AdvanceKinds::AdvancesAccountCode)->value('id');

    expect($entry->type)->toBe(AdvanceKinds::EntrySettlement)->and((float) $entry->amount)->toBe(30000.0)
        ->and($move->move_type)->toBe('entry')->and($move->state)->toBe('posted')
        ->and($move->accounting_source_type)->toBe(AdvanceKinds::SettlementSourceType)->and((int) $move->accounting_source_id)->toBe($settlement->id)
        ->and($move->cash_flow_category)->toBe(AdvanceKinds::NonCash)
        ->and((int) $move->journal_id)->toBe($f['journals']['GENERAL']->id)
        ->and($lines)->toHaveCount(2)
        ->and((int) $lines[0]->account_id)->toBe((int) $f['settlementType']->debit_account_id)->and((float) $lines[0]->debit)->toBe(30000.0)
        ->and((int) $lines[1]->account_id)->toBe((int) $advancesAccountId)->and((float) $lines[1]->credit)->toBe(30000.0)
        ->and((int) $lines[0]->partner_id)->toBe((int) $f['employee']->partner_id)
        ->and((float) array_sum(array_column($lines, 'debit')))->toBe((float) array_sum(array_column($lines, 'credit')));

    $summary = $this->advances->summary($advance->fresh());
    expect($summary['settled'])->toBe('30000.00')->and($summary['outstanding'])->toBe('20000.00')->and($summary['pending_settlement_id'])->toBeNull();

    // Applying it again never posts a second entry.
    $this->advances->applyApprovedSettlement($settlement->fresh());
    expect(AdvanceEntry::query()->where('source_request_id', $settlement->id)->count())->toBe(1)
        ->and(DB::table('accounts_account_moves')->where('accounting_source_type', AdvanceKinds::SettlementSourceType)->where('accounting_source_id', $settlement->id)->count())->toBe(1);
});

it('refuses settlements that are wrong: not the owner, more than is left, no receipts, nothing said, or one already waiting', function () {
    $f = advFixture();
    $advance = advPaidOut($f);
    $stranger = advUser($f['company']);
    $start = fn (User $user, string $amount, array $receipts = ['employees/requests/r.pdf'], string $note = 'Hotel') => fn () => $this->advances->startSettlement($user, $advance->fresh(), $amount, $receipts, $note);

    expect($start($stranger, '1000'))->toThrow(RuntimeException::class, 'only settle your own')
        ->and($start($f['employeeUser'], '0'))->toThrow(RuntimeException::class, 'more than zero')
        ->and($start($f['employeeUser'], '60000'))->toThrow(RuntimeException::class, 'file a separate claim for the extra 10,000.00')
        ->and($start($f['employeeUser'], '1000', []))->toThrow(RuntimeException::class, 'Attach the receipts')
        ->and($start($f['employeeUser'], '1000', ['employees/requests/r.pdf'], ' '))->toThrow(RuntimeException::class, 'what the money was spent on');

    $this->advances->startSettlement($f['employeeUser'], $advance->fresh(), '1000', ['employees/requests/r.pdf'], 'Hotel');

    expect($start($f['employeeUser'], '500'))->toThrow(RuntimeException::class, 'already waiting for approval')
        ->and($this->advances->canSettle($f['employeeUser'], $advance->fresh()))->toBeFalse();
});

it('does not post an approved settlement that is more than what is left, and posts nothing for a rejected one', function () {
    $f = advFixture();
    $advance = advPaidOut($f, '10000');

    $tooMuch = $this->advances->startSettlement($f['employeeUser'], $advance, '8000', ['employees/requests/r.pdf'], 'Hotel');
    $this->requests->reject($tooMuch->fresh(), $f['managerUser'], 'Receipts do not match');

    expect($tooMuch->fresh()->status)->toBe('rejected')
        ->and(AdvanceEntry::query()->where('advance_request_id', $advance->id)->count())->toBe(0)
        ->and($this->advances->summary($advance->fresh())['outstanding'])->toBe('10000.00')
        ->and($this->advances->canSettle($f['employeeUser'], $advance->fresh()))->toBeTrue();

    // Money handed back meanwhile leaves less than a second settlement asks for.
    $second = $this->advances->startSettlement($f['employeeUser'], $advance->fresh(), '8000', ['employees/requests/r.pdf'], 'Hotel');
    $this->advances->recordReturnedCash($f['financeUser'], $advance->fresh(), '5000', $f['journals']['BANK']->id, '2026-11-16');

    $this->requests->approve($second->fresh(), $f['managerUser']);

    expect(fn () => $this->requests->approve($second->fresh(), $f['hrUser']))->toThrow(RuntimeException::class, 'more than what is left of the advance (5,000.00)')
        ->and(AdvanceEntry::query()->where('type', AdvanceKinds::EntrySettlement)->where('advance_request_id', $advance->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------
// Cash handed back
// ---------------------------------------------------------------------
it('records cash handed back: Dr bank / Cr Employee Advances, closing the advance when nothing is left', function () {
    $f = advFixture();
    $advance = advPaidOut($f);
    $settlement = $this->advances->startSettlement($f['employeeUser'], $advance, '30000', ['employees/requests/r.pdf'], 'Hotel and fuel');
    $this->requests->approve($settlement->fresh(), $f['managerUser']);
    $this->requests->approve($settlement->fresh(), $f['hrUser']);

    expect($this->advances->canRecordReturn($f['financeUser'], $advance->fresh()))->toBeTrue()
        ->and($this->advances->canRecordReturn($f['employeeUser'], $advance->fresh()))->toBeFalse()
        ->and($this->advances->returnJournalOptions($advance))->toHaveKey($f['journals']['BANK']->id);

    $entry = $this->advances->recordReturnedCash($f['financeUser'], $advance->fresh(), '20000', $f['journals']['BANK']->id, '2026-11-16', 'RCPT-77', 'Handed to the cashier');
    $move = DB::table('accounts_account_moves')->where('id', $entry->accounting_move_id)->first();
    $lines = advLines($move->id);
    $advancesAccountId = Account::query()->where('code', AdvanceKinds::AdvancesAccountCode)->value('id');

    expect($entry->type)->toBe(AdvanceKinds::EntryCashReturned)
        ->and($move->accounting_source_type)->toBe(AdvanceKinds::CashReturnSourceType)->and((int) $move->accounting_source_id)->toBe($entry->id)
        ->and($move->cash_flow_category)->toBe(AdvanceKinds::OperatingReceipts)
        ->and((int) $lines[0]->account_id)->toBe((int) $f['bankAccount']->id)->and((float) $lines[0]->debit)->toBe(20000.0)
        ->and((int) $lines[1]->account_id)->toBe((int) $advancesAccountId)->and((float) $lines[1]->credit)->toBe(20000.0);

    $summary = $this->advances->summary($advance->fresh());
    expect($summary['returned'])->toBe('20000.00')->and($summary['outstanding'])->toBe('0.00')->and($summary['is_closed'])->toBeTrue()
        ->and($this->advances->canRecordReturn($f['financeUser'], $advance->fresh()))->toBeFalse();
});

it('refuses cash handed back that is wrong or from someone who may not record it', function () {
    $f = advFixture();
    $advance = advPaidOut($f, '10000');
    $record = fn (User $user, string $amount, ?int $journalId = null, string $date = '2026-11-16') => fn () => $this->advances->recordReturnedCash($user, $advance->fresh(), $amount, $journalId ?? $f['journals']['BANK']->id, $date);

    $otherCompany = Company::factory()->create(['currency_id' => $f['currency']->id, 'is_active' => true]);
    $foreignJournal = Journal::factory()->create(['company_id' => $otherCompany->id, 'currency_id' => $f['currency']->id, 'type' => JournalType::BANK, 'code' => 'BANK-'.$otherCompany->id, 'default_account_id' => $f['bankAccount']->id]);
    $outsider = advUser($otherCompany, null, [HrPermissions::ProcessFinancialRequests]);

    expect($record($f['employeeUser'], '1000'))->toThrow(RuntimeException::class, 'cannot record returned cash')
        ->and($record($outsider, '1000'))->toThrow(RuntimeException::class, 'cannot record returned cash')
        ->and($record($f['financeUser'], '0'))->toThrow(RuntimeException::class, 'more than zero')
        ->and($record($f['financeUser'], '10001'))->toThrow(RuntimeException::class, 'more than what is left of the advance (10,000.00)')
        ->and($record($f['financeUser'], '1000', $foreignJournal->id))->toThrow(RuntimeException::class, 'bank or cash journal of this company')
        ->and($record($f['financeUser'], '1000', null, '2026-11-20'))->toThrow(RuntimeException::class, 'cannot be in the future')
        ->and(AdvanceEntry::query()->where('advance_request_id', $advance->id)->count())->toBe(0);
});

it('does not post into a closed accounting period', function () {
    $f = advFixture();
    $advance = advPaidOut($f, '10000');
    app(PeriodLockService::class)->lockThrough($f['company'], $f['financeUser'], CarbonImmutable::parse('2026-11-16'));

    expect(fn () => $this->advances->recordReturnedCash($f['financeUser'], $advance->fresh(), '1000', $f['journals']['BANK']->id, '2026-11-16'))
        ->toThrow(RuntimeException::class, 'closed accounting period');

    expect(AdvanceEntry::query()->where('advance_request_id', $advance->id)->count())->toBe(0)
        ->and(DB::table('accounts_account_moves')->where('accounting_source_type', AdvanceKinds::CashReturnSourceType)->count())->toBe(0);
});

// ---------------------------------------------------------------------
// Reminders
// ---------------------------------------------------------------------
it('reminds the employee two days before the settle-by date, and the employee and manager weekly once overdue, once a day', function () {
    $f = advFixture();
    $advance = advPaidOut($f);
    $reminders = app(AdvanceReminderService::class);
    $sent = fn (User $user, string $stage) => DB::table('notifications')->where('notifiable_id', $user->id)->where('data', 'like', '%advance_settle_'.$stage.'_'.$advance->id.'_%')->count();
    $setSettleBy = fn (string $date) => $advance->forceFill(['payload' => [...$advance->fresh()->payload, 'settle_by' => $date]])->save();

    // Two days to go: the employee only.
    $setSettleBy('2026-11-18');
    expect($reminders->send($f['company']->id))->toBe(['advances' => 1, 'notified' => 1])
        ->and($sent($f['employeeUser'], 'soon'))->toBe(1)->and($sent($f['managerUser'], 'soon'))->toBe(0);
    expect($reminders->send($f['company']->id)['notified'])->toBe(0);

    // One day late: the employee and the line manager.
    $setSettleBy('2026-11-15');
    expect($reminders->send($f['company']->id)['notified'])->toBe(2)
        ->and($sent($f['employeeUser'], 'overdue'))->toBe(1)->and($sent($f['managerUser'], 'overdue'))->toBe(1);

    // Three days late is between reminders; eight days late is the next one.
    $setSettleBy('2026-11-13');
    expect($reminders->send($f['company']->id)['advances'])->toBe(0);
    $setSettleBy('2026-11-08');
    expect($reminders->send($f['company']->id)['advances'])->toBe(1);

    // Nothing is outstanding once it is settled or handed back.
    $this->advances->recordReturnedCash($f['financeUser'], $advance->fresh(), '50000', $f['journals']['BANK']->id, '2026-11-16');
    expect($reminders->send($f['company']->id, '2026-11-23'))->toBe(['advances' => 0, 'notified' => 0]);
});
