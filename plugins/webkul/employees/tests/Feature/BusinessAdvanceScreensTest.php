<?php

/**
 * Business advance screens on Employee Requests: the form, the list columns,
 * the Settle advance / Record cash returned / Post settlement again actions
 * and the help topic. The accounting rules themselves are covered by
 * BusinessAdvanceTest; here the screens are checked, with minimal request
 * types built from fresh factory companies.
 */

use Database\Seeders\HrRoleSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Employee\Filament\Pages\HrHelp;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\AdvanceEntry;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Support\AdvanceKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function advScreenUser(Company $company, array $permissions = []): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($permissions !== []) {
        $role = Role::query()->create(['name' => 'advscreen-'.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }
        $user->assignRole($role);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function advScreenWorkflow(Company $company, string $code): void
{
    $workflow = ApprovalWorkflow::query()->create(['company_id' => $company->id, 'request_type' => $code, 'name' => $code.' approval', 'is_active' => true]);
    ApprovalStep::query()->create(['workflow_id' => $workflow->id, 'sequence' => 1, 'name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);
}

/**
 * A fresh company with the two advance request types, an employee with a line
 * manager, a finance user, a bank journal and a posted (paid out) advance.
 *
 * @return array<string, mixed>
 */
function advScreenFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));
    app(HrRoleSeeder::class)->run();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $advances = Account::factory()->create(['currency_id' => $currency->id, 'account_type' => AccountType::ASSET_CURRENT, 'is_group' => false, 'deprecated' => false]);
    $expense = Account::factory()->create(['currency_id' => $currency->id, 'account_type' => AccountType::EXPENSE, 'is_group' => false, 'deprecated' => false]);
    $cash = Account::factory()->create(['currency_id' => $currency->id, 'account_type' => AccountType::ASSET_CASH, 'is_group' => false, 'deprecated' => false]);
    foreach ([$advances, $expense, $cash] as $account) {
        $account->companies()->attach($company->id);
    }

    $general = Journal::factory()->create(['company_id' => $company->id, 'currency_id' => $currency->id, 'type' => JournalType::GENERAL, 'code' => 'G'.$company->id]);
    $bank = Journal::factory()->create(['company_id' => $company->id, 'currency_id' => $currency->id, 'type' => JournalType::BANK, 'code' => 'B'.$company->id, 'default_account_id' => $cash->id, 'name' => 'Main bank']);

    $advanceType = EmployeeRequestType::query()->create([
        'company_id'            => $company->id, 'code' => AdvanceKinds::AdvanceCode, 'name' => 'Business Advance', 'category' => AdvanceKinds::AdvanceCategory,
        'approval_request_type' => AdvanceKinds::AdvanceCode, 'is_financial' => true, 'requires_amount' => true, 'requires_document' => false,
        'is_active'             => true, 'journal_id' => $general->id, 'debit_account_id' => $advances->id, 'configuration' => [AdvanceKinds::SettleWithinDaysKey => 10],
    ]);
    $settlementType = EmployeeRequestType::query()->create([
        'company_id'            => $company->id, 'code' => AdvanceKinds::SettlementCode, 'name' => 'Advance Settlement', 'category' => AdvanceKinds::SettlementCategory,
        'approval_request_type' => AdvanceKinds::SettlementCode, 'is_financial' => false, 'requires_amount' => true, 'requires_document' => true,
        'is_active'             => true, 'journal_id' => $general->id, 'debit_account_id' => $expense->id,
    ]);
    advScreenWorkflow($company, AdvanceKinds::AdvanceCode);
    advScreenWorkflow($company, AdvanceKinds::SettlementCode);

    $managerUser = advScreenUser($company);
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Line Manager']);
    $employeeUser = advScreenUser($company);
    $employee = Employee::query()->create([
        'company_id' => $company->id, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id, 'name' => 'Ali Advance',
        'partner_id' => Partner::factory()->create()->id,
    ]);
    $colleagueUser = advScreenUser($company);
    Employee::query()->create(['company_id' => $company->id, 'user_id' => $colleagueUser->id, 'parent_id' => $manager->id, 'name' => 'Colleague']);
    $financeUser = advScreenUser($company, [HrPermissions::ProcessFinancialRequests, HrPermissions::ManageEmployeeRequests]);
    Employee::query()->create(['company_id' => $company->id, 'user_id' => $financeUser->id, 'name' => 'Finance Person']);

    return compact('currency', 'company', 'advanceType', 'settlementType', 'manager', 'managerUser', 'employee', 'employeeUser', 'colleagueUser', 'financeUser', 'bank', 'general');
}

/** An approved advance of $amount whose vendor bill is posted, to be settled in $settleInDays days. */
function advScreenPaidOut(array $f, string $amount = '40000', int $settleInDays = 10): EmployeeRequest
{
    $now = now();
    $moveId = DB::table('accounts_account_moves')->insertGetId([
        'journal_id' => $f['general']->id, 'company_id' => $f['company']->id, 'currency_id' => $f['currency']->id, 'date' => $now->toDateString(),
        'name'       => 'BILL/'.uniqid(), 'move_type' => 'entry', 'state' => 'posted', 'created_at' => $now, 'updated_at' => $now,
    ]);

    return EmployeeRequest::query()->create([
        'company_id'         => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['advanceType']->id,
        'requested_by'       => $f['employeeUser']->id, 'currency_id' => $f['currency']->id, 'title' => 'Lahore client visit', 'reference' => 'HR-ADV-'.uniqid(),
        'amount'             => $amount, 'status' => 'approved', 'accounting_move_id' => $moveId, 'approved_at' => $now,
        'payload'            => ['kind' => AdvanceKinds::AdvanceCategory, 'purpose' => 'Hotel and fuel', 'needed_by' => $now->toDateString(), 'settle_by' => $now->copy()->addDays($settleInDays)->toDateString()],
    ]);
}

function advScreenActAs(User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

it('does not offer Advance Settlement as an approval type', function (): void {
    $f = advScreenFixture();
    advScreenActAs($f['employeeUser']);

    Livewire::test(ManageEmployeeRequests::class)
        ->mountTableAction('submit_new')
        ->assertFormFieldExists('request_type_id', function (Select $field) use ($f): bool {
            $options = $field->getOptions();

            expect($options)->toHaveKey($f['advanceType']->id)
                ->and($options)->not->toHaveKey($f['settlementType']->id);

            return true;
        });
});

it('shows purpose and needed-by for a business advance and hides the tax fields', function (): void {
    $f = advScreenFixture();
    advScreenActAs($f['employeeUser']);

    $component = Livewire::test(ManageEmployeeRequests::class)
        ->mountTableAction('submit_new')
        ->fillForm(['employee_id' => $f['employee']->id, 'request_type_id' => $f['advanceType']->id])
        ->assertFormFieldVisible('payload.purpose')
        ->assertFormFieldVisible('payload.needed_by')
        ->assertFormFieldHidden('billed_amount')
        ->assertFormFieldHidden('tax_deduction_rate')
        ->assertFormFieldExists('payload.purpose', fn ($field): bool => str_starts_with($field->getLabel(), 'What is the money for?') && $field->isRequired())
        ->assertFormFieldHidden('income_tax_deduction')
        ->assertFormFieldHidden('sales_tax_deduction');

    $visible = $component->instance()->mountedActionSchema0->getFlatFields(withHidden: false);

    expect($visible['payload.needed_by']->getLabel())->toBe('Date you need the money by')
        ->and($visible['payload.needed_by']->isRequired())->toBeTrue()
        ->and($visible['amount']->getLabel())->toBe('How much do you need?')
        ->and($visible['attachments']->getLabel())->toBe('Quotation or estimate (optional)')
        ->and($visible['attachments']->isRequired())->toBeFalse();
});

it('sends a business advance for approval from the form without any claim tax fields', function (): void {
    $f = advScreenFixture();
    advScreenActAs($f['employeeUser']);
    $neededBy = now(AdvanceKinds::timezone())->addDays(3)->toDateString();

    Livewire::test(ManageEmployeeRequests::class)
        ->callTableAction('submit_new', data: [
            'employee_id'     => $f['employee']->id,
            'request_type_id' => $f['advanceType']->id,
            'currency_id'     => $f['currency']->id,
            'amount'          => '25000',
            'payload'         => ['purpose' => 'Hotel for the Lahore visit', 'needed_by' => $neededBy],
            'account_title'   => 'Ali Advance', 'iban' => 'PK36SCBL0000001123456702', 'bank_name' => 'Standard Chartered',
        ])
        ->assertHasNoTableActionErrors();

    $request = EmployeeRequest::query()->where('employee_id', $f['employee']->id)->latest('id')->firstOrFail();

    expect($request->status)->toBe('pending_approval')
        ->and((string) $request->amount)->toBe('25000.0000')
        ->and($request->billed_amount)->toBeNull()
        ->and($request->tax_deduction_rate)->toBeNull()
        ->and($request->payload['purpose'])->toBe('Hotel for the Lahore visit')
        ->and($request->payload['settle_by'])->toBe(now(AdvanceKinds::timezone())->addDays(13)->toDateString());
});

it('refuses a business advance without a purpose', function (): void {
    $f = advScreenFixture();
    advScreenActAs($f['employeeUser']);

    Livewire::test(ManageEmployeeRequests::class)
        ->callTableAction('submit_new', data: [
            'employee_id'     => $f['employee']->id,
            'request_type_id' => $f['advanceType']->id,
            'amount'          => '25000',
            'payload'         => ['needed_by' => now()->addDays(3)->toDateString()],
        ])
        ->assertHasTableActionErrors(['payload.purpose' => 'required']);
});

it('lists settle-by and outstanding for an advance and a dash for other requests', function (): void {
    $f = advScreenFixture();
    $advance = advScreenPaidOut($f, '40000');
    AdvanceEntry::query()->create(['company_id' => $f['company']->id, 'advance_request_id' => $advance->id, 'type' => AdvanceKinds::EntryCashReturned, 'amount' => '10000', 'entry_date' => now()->toDateString()]);
    $other = EmployeeRequest::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['settlementType']->id, 'requested_by' => $f['employeeUser']->id, 'amount' => '500', 'status' => 'draft']);

    advScreenActAs($f['employeeUser']);

    Livewire::test(ManageEmployeeRequests::class)
        ->assertCanSeeTableRecords([$advance, $other])
        ->assertTableColumnStateSet('advance_outstanding', '30000.00', $advance)
        ->assertTableColumnStateSet('advance_settle_by', now()->addDays(10)->format('d M Y'), $advance)
        ->assertTableColumnStateSet('advance_status', 'Paid out, to settle', $advance)
        ->assertTableColumnStateSet('advance_outstanding', null, $other)
        ->assertTableColumnStateSet('advance_settle_by', null, $other);
});

it('filters advances into outstanding, overdue and settled and shows overdue in red with a tooltip', function (): void {
    $f = advScreenFixture();
    $open = advScreenPaidOut($f, '40000', 5);
    $late = advScreenPaidOut($f, '20000', -3);
    $done = advScreenPaidOut($f, '10000', 5);
    AdvanceEntry::query()->create(['company_id' => $f['company']->id, 'advance_request_id' => $done->id, 'type' => AdvanceKinds::EntrySettlement, 'amount' => '10000', 'entry_date' => now()->toDateString()]);

    advScreenActAs($f['employeeUser']);

    Livewire::test(ManageEmployeeRequests::class)
        ->filterTable('advances', 'outstanding')->assertCanSeeTableRecords([$open, $late])->assertCanNotSeeTableRecords([$done])
        ->filterTable('advances', 'overdue')->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$open, $done])
        ->filterTable('advances', 'settled')->assertCanSeeTableRecords([$done])->assertCanNotSeeTableRecords([$open, $late])
        ->removeTableFilters()
        ->assertTableColumnStateSet('advance_status', 'Overdue', $late)
        ->assertTableColumnStateSet('advance_status', 'Settled', $done);
});

it('shows Settle advance only to the owner of a paid out advance with money outstanding', function (): void {
    $f = advScreenFixture();
    $advance = advScreenPaidOut($f);
    $unpaid = EmployeeRequest::query()->create(['company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['advanceType']->id, 'requested_by' => $f['employeeUser']->id, 'amount' => '900', 'status' => 'pending_approval', 'payload' => ['purpose' => 'x', 'needed_by' => now()->toDateString()]]);

    advScreenActAs($f['employeeUser']);
    Livewire::test(ManageEmployeeRequests::class)
        ->assertTableActionVisible('settle_advance', $advance)
        ->assertTableActionHidden('settle_advance', $unpaid)
        ->assertTableActionHidden('record_cash_returned', $advance);

    advScreenActAs($f['colleagueUser']);
    expect(EmployeeRequestResource::canSettleAdvance($advance->fresh('requestType')))->toBeFalse();

    advScreenActAs($f['financeUser']);
    expect(EmployeeRequestResource::canSettleAdvance($advance->fresh('requestType')))->toBeFalse();

    AdvanceEntry::query()->create(['company_id' => $f['company']->id, 'advance_request_id' => $advance->id, 'type' => AdvanceKinds::EntryCashReturned, 'amount' => '40000', 'entry_date' => now()->toDateString()]);
    advScreenActAs($f['employeeUser']);
    Livewire::test(ManageEmployeeRequests::class)->assertTableActionHidden('settle_advance', $advance->fresh());
});

it('settles an advance through the service and creates a linked Advance Settlement', function (): void {
    Storage::fake('local');
    $f = advScreenFixture();
    $advance = advScreenPaidOut($f, '40000');
    advScreenActAs($f['employeeUser']);

    Livewire::test(ManageEmployeeRequests::class)
        ->callTableAction('settle_advance', $advance, data: [
            'amount_spent' => '15000',
            'note'         => 'Hotel for two nights',
            'spent_on'     => now()->toDateString(),
            'receipts'     => [UploadedFile::fake()->image('receipt.jpg')],
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Settlement sent for approval');

    $settlement = EmployeeRequest::query()->where('advance_request_id', $advance->id)->firstOrFail();

    expect($settlement->requestType->category)->toBe(AdvanceKinds::SettlementCategory)
        ->and($settlement->status)->toBe('pending_approval')
        ->and((string) $settlement->amount)->toBe('15000.0000')
        ->and($settlement->attachments)->toHaveCount(1)
        ->and($settlement->description)->toBe('Hotel for two nights');

    // One settlement at a time: the action goes away while it waits for approval.
    Livewire::test(ManageEmployeeRequests::class)->assertTableActionHidden('settle_advance', $advance->fresh());
});

it('shows the advance details and its settlement on the view screen', function (): void {
    $f = advScreenFixture();
    $advance = advScreenPaidOut($f, '40000');
    AdvanceEntry::query()->create(['company_id' => $f['company']->id, 'advance_request_id' => $advance->id, 'type' => AdvanceKinds::EntryCashReturned, 'amount' => '5000', 'entry_date' => now()->toDateString(), 'reference' => 'DEP-77']);
    $settlement = EmployeeRequest::query()->create([
        'company_id'   => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['settlementType']->id, 'advance_request_id' => $advance->id,
        'requested_by' => $f['employeeUser']->id, 'currency_id' => $f['currency']->id, 'title' => 'Settlement of the Lahore visit', 'amount' => '12000', 'status' => 'pending_approval',
    ]);

    advScreenActAs($f['employeeUser']);

    Livewire::test(ManageEmployeeRequests::class)->mountTableAction('view', $advance)->assertHasNoErrors()->assertOk();
    Livewire::test(ManageEmployeeRequests::class)->mountTableAction('view', $settlement)->assertHasNoErrors()->assertOk();

    $record = EmployeeRequestResource::getEloquentQuery()->findOrFail($advance->id);

    expect(EmployeeRequestResource::advanceSummaryHtml($record))->toContain('Still to settle', '35,000.00', 'Handed back')
        ->and(EmployeeRequestResource::advanceHistoryHtml($record))->toContain('Cash returned', 'DEP-77', '5,000.00')
        ->and(EmployeeRequestResource::advanceSettlementsHtml($record))->toContain('Settlement of the Lahore visit', 'Pending approval');
});

it('shows Record cash returned only to finance and posts it through the service', function (): void {
    $f = advScreenFixture();
    $advance = advScreenPaidOut($f, '40000');

    advScreenActAs($f['employeeUser']);
    Livewire::test(ManageEmployeeRequests::class)->assertTableActionHidden('record_cash_returned', $advance);

    advScreenActAs($f['financeUser']);
    Livewire::test(ManageEmployeeRequests::class)
        ->assertTableActionVisible('record_cash_returned', $advance)
        ->callTableAction('record_cash_returned', $advance, data: [
            'amount'      => '10000',
            'journal_id'  => $f['bank']->id,
            'returned_on' => now(AdvanceKinds::timezone())->toDateString(),
            'reference'   => 'DEP-1',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Cash returned has been recorded');

    $entry = AdvanceEntry::query()->where('advance_request_id', $advance->id)->firstOrFail();

    expect($entry->type)->toBe(AdvanceKinds::EntryCashReturned)
        ->and((string) $entry->amount)->toBe('10000.0000')
        ->and($entry->accounting_move_id)->not->toBeNull();
});

it('offers Post settlement again only for an approved settlement without an accounting entry, to finance', function (): void {
    $f = advScreenFixture();
    $advance = advScreenPaidOut($f, '40000');
    $settlement = EmployeeRequest::query()->create([
        'company_id'   => $f['company']->id, 'employee_id' => $f['employee']->id, 'request_type_id' => $f['settlementType']->id, 'advance_request_id' => $advance->id,
        'requested_by' => $f['employeeUser']->id, 'currency_id' => $f['currency']->id, 'title' => 'Settlement', 'amount' => '12000', 'status' => 'approved', 'approved_at' => now(),
    ]);

    advScreenActAs($f['employeeUser']);
    expect(EmployeeRequestResource::canRetrySettlementPosting($settlement->fresh('requestType')))->toBeFalse();

    advScreenActAs($f['financeUser']);
    expect(EmployeeRequestResource::canRetrySettlementPosting($settlement->fresh('requestType')))->toBeTrue()
        ->and(EmployeeRequestResource::canRetrySettlementPosting($advance->fresh('requestType')))->toBeFalse();

    AdvanceEntry::query()->create(['company_id' => $f['company']->id, 'advance_request_id' => $advance->id, 'source_request_id' => $settlement->id, 'type' => AdvanceKinds::EntrySettlement, 'amount' => '12000', 'entry_date' => now()->toDateString()]);

    expect(EmployeeRequestResource::canRetrySettlementPosting($settlement->fresh('requestType')))->toBeFalse();
});

it('shows the business advance topic and the claims topic on the help page', function (): void {
    $f = advScreenFixture();
    advScreenActAs($f['employeeUser']);

    Livewire::test(HrHelp::class)
        ->assertOk()
        ->assertSee('Money for company work (business advance)')
        ->assertSee('Settle advance')
        ->assertSee('id="business-advance"', false)
        ->assertSee('Claims: money you already spent')
        ->assertSee('id="claims"', false)
        ->assertSee('id="my-requests"', false);
});
