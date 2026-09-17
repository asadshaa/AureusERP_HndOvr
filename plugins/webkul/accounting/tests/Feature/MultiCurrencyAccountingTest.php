<?php

use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Accounting\Filament\Clusters\Accounting\Pages\ImportBankStatement;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\BalanceSheet;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\DirectCashFlow;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\ProfitLoss;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Accounting\Models\FxRevaluation;
use Webkul\Accounting\Services\Account\CanonicalAccountCreationService;
use Webkul\Accounting\Services\Currency\CompanyCurrencyService;
use Webkul\Accounting\Services\Currency\ExchangeRateApprovalService;
use Webkul\Accounting\Services\Currency\ExchangeRateService;
use Webkul\Accounting\Services\Currency\FxRevaluationService;
use Webkul\Accounting\Services\Currency\IsoCurrencySynchronizer;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

function multiCurrencyTestFixture(): array
{
    $base = Currency::query()->where('code', 'PKR')->firstOrFail();
    $foreign = Currency::query()->where('code', 'USD')->firstOrFail();
    $reporting = Currency::query()->where('code', 'EUR')->firstOrFail();
    Currency::query()->whereKey([$base->id, $foreign->id, $reporting->id])->update([
        'active'      => true,
        'is_iso_fiat' => true,
    ]);
    $base->refresh();
    $foreign->refresh();
    $reporting->refresh();
    $company = Company::factory()->create(['currency_id' => $base->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return compact('base', 'foreign', 'reporting', 'company', 'user');
}

function multiCurrencyAccount(array $fixture, string $code, AccountType $type): Account
{
    $account = Account::factory()->create([
        'code'         => $code,
        'name'         => $code,
        'account_type' => $type,
        'currency_id'  => $fixture['base']->id,
        'is_group'     => false,
        'deprecated'   => false,
        'creator_id'   => $fixture['user']->id,
    ]);
    $account->companies()->attach($fixture['company']->id);

    return $account;
}

it('synchronizes the current ISO fiat master idempotently without replacing customized records', function (): void {
    $usd = Currency::query()->where('code', 'USD')->firstOrFail();
    $usdId = $usd->id;
    $usd->update(['full_name' => 'Customized US Dollar']);

    $synchronizer = app(IsoCurrencySynchronizer::class);
    $first = $synchronizer->synchronize();
    $second = $synchronizer->synchronize();

    expect($first['total'])->toBe(155)
        ->and($second['created'])->toBe(0)
        ->and(Currency::query()->where('is_iso_fiat', true)->where('active', true)->count())->toBe(155)
        ->and(Currency::query()->whereNotNull('code')->select('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->count())->toBe(0)
        ->and(Currency::query()->where('code', 'USD')->firstOrFail()->id)->toBe($usdId)
        ->and(Currency::query()->where('code', 'USD')->firstOrFail()->full_name)->toBe('Customized US Dollar');
});

it('keeps the base currency enabled and validates company-owned FX settings', function (): void {
    $fixture = multiCurrencyTestFixture();
    $gain = multiCurrencyAccount($fixture, 'FX-GAIN-'.$fixture['company']->id, AccountType::INCOME_OTHER);
    $loss = multiCurrencyAccount($fixture, 'FX-LOSS-'.$fixture['company']->id, AccountType::EXPENSE);

    $company = app(CompanyCurrencyService::class)->update(
        company: $fixture['company'],
        baseCurrencyId: $fixture['base']->id,
        transactionCurrencyIds: [$fixture['foreign']->id],
        reportingCurrencyIds: [$fixture['reporting']->id],
        fxGainAccountId: $gain->id,
        fxLossAccountId: $loss->id,
        rateSourcePriority: [ExchangeRateSource::BankStatement->value, ExchangeRateSource::Manual->value, ExchangeRateSource::Api->value],
        allowPreviousRateFallback: false,
        pnlTranslationPolicy: 'transaction_date',
        balanceSheetTranslationPolicy: 'period_closing',
    );

    expect($company->enabledCurrencies()->whereKey($fixture['base']->id)->wherePivot('transaction_enabled', true)->exists())->toBeTrue()
        ->and($company->enabledCurrencies()->whereKey($fixture['foreign']->id)->wherePivot('transaction_enabled', true)->exists())->toBeTrue()
        ->and($company->enabledCurrencies()->whereKey($fixture['reporting']->id)->wherePivot('reporting_enabled', true)->exists())->toBeTrue()
        ->and($company->fx_gain_account_id)->toBe($gain->id)
        ->and($company->fx_loss_account_id)->toBe($loss->id);
});

it('resolves only approved company rates with bank manual API and cache priority', function (): void {
    $fixture = multiCurrencyTestFixture();
    $service = app(ExchangeRateService::class);
    $date = '2026-07-15';

    $makeRate = function (string $rate, ExchangeRateType $type, ExchangeRateSource $source) use ($fixture, $date): ExchangeRate {
        return ExchangeRate::query()->create([
            'company_id'         => $fixture['company']->id,
            'source_currency_id' => $fixture['foreign']->id,
            'target_currency_id' => $fixture['base']->id,
            'effective_date'     => $date,
            'rate'               => $rate,
            'rate_type'          => $type,
            'source'             => $source,
            'approval_status'    => ExchangeRateApprovalStatus::Approved,
            'created_by'         => $fixture['user']->id,
            'approved_by'        => $fixture['user']->id,
            'approved_at'        => now(),
        ]);
    };

    $makeRate('277.123456789012345', ExchangeRateType::Daily, ExchangeRateSource::Api);
    $manual = $makeRate('278.123456789012345', ExchangeRateType::Transaction, ExchangeRateSource::Manual);
    $resolved = $service->resolveForBankTransaction($fixture['company']->fresh(), $fixture['foreign'], $fixture['base'], $date);
    expect($resolved->recordId)->toBe($manual->id)
        ->and($resolved->rate)->toBe('278.123456789012345')
        ->and($service->convert('100.00', $resolved))->toBe('27812.3457');

    $bank = $makeRate('279.123456789012345', ExchangeRateType::BankProvided, ExchangeRateSource::BankStatement);
    $afterCacheInvalidation = $service->resolveForBankTransaction($fixture['company']->fresh(), $fixture['foreign'], $fixture['base'], $date);
    expect($afterCacheInvalidation->recordId)->toBe($bank->id)
        ->and($service->resolve($fixture['company'], $fixture['base'], $fixture['base'], $date)->recordId)->toBeNull();

    expect(fn () => $service->resolve(
        $fixture['company'],
        $fixture['reporting'],
        $fixture['base'],
        $date,
        [ExchangeRateType::Transaction],
        false,
    ))->toThrow(MissingExchangeRateException::class);
});

it('approval invalidates missing-rate state and rejects duplicate approved rate dimensions', function (): void {
    $fixture = multiCurrencyTestFixture();
    Cache::flush();
    $draft = ExchangeRate::query()->create([
        'company_id'         => $fixture['company']->id,
        'source_currency_id' => $fixture['foreign']->id,
        'target_currency_id' => $fixture['base']->id,
        'effective_date'     => '2026-07-20',
        'rate'               => '280.000000000000001',
        'rate_type'          => ExchangeRateType::Transaction,
        'source'             => ExchangeRateSource::Manual,
        'approval_status'    => ExchangeRateApprovalStatus::Draft,
        'created_by'         => $fixture['user']->id,
    ]);

    expect(fn () => app(ExchangeRateService::class)->resolveForBankTransaction(
        $fixture['company'],
        $fixture['foreign'],
        $fixture['base'],
        '2026-07-20',
    ))->toThrow(MissingExchangeRateException::class);

    $approved = app(ExchangeRateApprovalService::class)->approve($draft, $fixture['user']);
    expect($approved->approval_status)->toBe(ExchangeRateApprovalStatus::Approved)
        ->and(app(ExchangeRateService::class)->resolveForBankTransaction(
            $fixture['company'],
            $fixture['foreign'],
            $fixture['base'],
            '2026-07-20',
        )->recordId)->toBe($approved->id);
});

it('enforces the shared configurable approval chain when one is configured for exchange rates', function (): void {
    $fixture = multiCurrencyTestFixture();
    $rate = ExchangeRate::query()->create([
        'company_id'         => $fixture['company']->id,
        'source_currency_id' => $fixture['foreign']->id,
        'target_currency_id' => $fixture['base']->id,
        'effective_date'     => '2026-08-25',
        'rate'               => '281.500000000000000',
        'rate_type'          => ExchangeRateType::Transaction,
        'source'             => ExchangeRateSource::Manual,
        'approval_status'    => ExchangeRateApprovalStatus::Draft,
        'created_by'         => $fixture['user']->id,
    ]);
    $workflow = ApprovalWorkflow::query()->create([
        'company_id' => $fixture['company']->id, 'creator_id' => $fixture['user']->id,
        'name'       => 'Exchange rate approval', 'request_type' => 'exchange_rate_change',
        'priority'   => 100, 'is_active' => true,
    ]);
    $workflow->steps()->create([
        'sequence'           => 1, 'name' => 'Finance controller', 'approver_user_id' => $fixture['user']->id,
        'required_approvals' => 1,
    ]);
    $service = app(ExchangeRateApprovalService::class);

    expect($service->requiresConfiguredApproval($rate))->toBeTrue()
        ->and(fn () => $service->approve($rate, $fixture['user']))
        ->toThrow(RuntimeException::class, 'completed configured approval');

    $request = $service->submit($rate, $fixture['user']);
    $request = app(ApprovalEngine::class)->approve($request, $fixture['user'], 'Rate source checked.');
    $approved = $service->approve($rate, $fixture['user']);

    expect($request->status)->toBe('approved')
        ->and($approved->approval_status)->toBe(ExchangeRateApprovalStatus::Approved)
        ->and($approved->approved_by)->toBe($fixture['user']->id);
});

it('refuses to finalize an exchange rate edited after its approval was granted', function (): void {
    $fixture = multiCurrencyTestFixture();
    $rate = ExchangeRate::query()->create([
        'company_id'         => $fixture['company']->id,
        'source_currency_id' => $fixture['foreign']->id,
        'target_currency_id' => $fixture['base']->id,
        'effective_date'     => '2026-08-26',
        'rate'               => '281.500000000000000',
        'rate_type'          => ExchangeRateType::Transaction,
        'source'             => ExchangeRateSource::Manual,
        'approval_status'    => ExchangeRateApprovalStatus::Draft,
        'created_by'         => $fixture['user']->id,
    ]);
    $workflow = ApprovalWorkflow::query()->create([
        'company_id' => $fixture['company']->id, 'creator_id' => $fixture['user']->id,
        'name'       => 'Exchange rate approval (edit-after-approve)', 'request_type' => 'exchange_rate_change',
        'priority'   => 100, 'is_active' => true,
    ]);
    $workflow->steps()->create([
        'sequence'           => 1, 'name' => 'Finance controller', 'approver_user_id' => $fixture['user']->id,
        'required_approvals' => 1,
    ]);
    $service = app(ExchangeRateApprovalService::class);

    // Submit and get the workflow-level approval on the original value ...
    $request = $service->submit($rate, $fixture['user']);
    app(ApprovalEngine::class)->approve($request, $fixture['user'], 'Rate source checked.');

    // ... but before anyone clicks the resource's own "approve" action, the
    // rate itself gets edited. The stale approval must not be usable to
    // finalize this new, never-actually-approved value.
    $rate->update(['rate' => '999.000000000000000']);

    expect(fn () => $service->approve($rate, $fixture['user']))
        ->toThrow(RuntimeException::class, 'exchange rate value was changed from "281.5" to "999"');

    expect($rate->fresh()->approval_status)->toBe(ExchangeRateApprovalStatus::Draft);

    // Editing it back to exactly what was approved must let it through again.
    $rate->update(['rate' => '281.500000000000000']);
    $approved = $service->approve($rate, $fixture['user']);

    expect($approved->approval_status)->toBe(ExchangeRateApprovalStatus::Approved);
});

it('shows a clean notification instead of a crash when the approve action fails', function (): void {
    $fixture = multiCurrencyTestFixture();
    $rate = ExchangeRate::query()->create([
        'company_id'         => $fixture['company']->id,
        'source_currency_id' => $fixture['foreign']->id,
        'target_currency_id' => $fixture['base']->id,
        'effective_date'     => '2026-08-27',
        'rate'               => '281.500000000000000',
        'rate_type'          => ExchangeRateType::Transaction,
        'source'             => ExchangeRateSource::Manual,
        'approval_status'    => ExchangeRateApprovalStatus::Draft,
        'created_by'         => $fixture['user']->id,
    ]);
    ApprovalWorkflow::query()->create([
        'company_id' => $fixture['company']->id, 'creator_id' => $fixture['user']->id,
        'name'       => 'Exchange rate approval (notification test)', 'request_type' => 'exchange_rate_change',
        'priority'   => 100, 'is_active' => true,
    ])->steps()->create([
        'sequence' => 1, 'name' => 'Finance controller', 'approver_user_id' => $fixture['user']->id,
        'required_approvals' => 1,
    ]);

    Permission::findOrCreate(AccountingPermissions::ApproveExchangeRates, 'web');
    Permission::findOrCreate(AccountingPermissions::ManageExchangeRates, 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $fixture['user']->givePermissionTo([
        AccountingPermissions::ApproveExchangeRates,
        AccountingPermissions::ManageExchangeRates,
    ]);
    test()->actingAs($fixture['user']);

    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    \Filament\Facades\Filament::bootCurrentPanel();

    // A configured workflow exists but nothing has actually approved this
    // rate yet -- clicking "approve" directly must surface a clean,
    // catchable error, not an unhandled RuntimeException crash page.
    Livewire::test(ListExchangeRates::class)
        ->callTableAction('approve', $rate)
        ->assertNotified('Could not approve this exchange rate');

    expect($rate->fresh()->approval_status)->toBe(ExchangeRateApprovalStatus::Draft);
});

it('creates canonical company-scoped bank and offset accounts without overwriting duplicates', function (): void {
    $fixture = multiCurrencyTestFixture();
    $fixture['company']->enabledCurrencies()->syncWithoutDetaching([
        $fixture['foreign']->id => ['transaction_enabled' => true, 'reporting_enabled' => false],
    ]);
    test()->actingAs($fixture['user']);

    $service = app(CanonicalAccountCreationService::class);
    $parent = $service->createBankParentAccount($fixture['company'], [
        'code'         => 'BANK-PARENT-'.$fixture['company']->id,
        'name'         => 'Bank and cash accounts',
        'company_id'   => $fixture['company']->id,
        'currency_id'  => $fixture['foreign']->id,
        'account_type' => AccountType::ASSET_CURRENT->value,
        'is_group'     => true,
    ]);
    $bank = $service->createBankAccount($fixture['company'], [
        'code'                => 'USD-BANK-'.$fixture['company']->id,
        'name'                => 'USD operating bank',
        'currency_id'         => $fixture['foreign']->id,
        'parent_id'           => $parent->id,
        'bank_name'           => 'Test Bank',
        'bank_account_number' => 'ACCOUNT-'.$fixture['company']->id,
        'iban'                => 'PK00TEST'.$fixture['company']->id,
        'active'              => true,
    ]);
    $offset = $service->createOffsetAccount($fixture['company'], [
        'code'         => 'OFFSET-'.$fixture['company']->id,
        'name'         => 'Foreign bank charges',
        'account_type' => AccountType::EXPENSE->value,
        'currency_id'  => $fixture['foreign']->id,
        'active'       => true,
    ]);

    expect($parent->account_type)->toBe(AccountType::ASSET_CURRENT)
        ->and($parent->is_group)->toBeTrue()
        ->and($parent->deprecated)->toBeFalse()
        ->and($bank->account_type)->toBe(AccountType::ASSET_CASH)
        ->and($bank->is_group)->toBeFalse()
        ->and($bank->parent_id)->toBe($parent->id)
        ->and($bank->companies->modelKeys())->toContain($fixture['company']->id)
        ->and($bank->accountingDetail->bank_name)->toBe('Test Bank')
        ->and($offset->account_type)->toBe(AccountType::EXPENSE)
        ->and(fn () => $service->createBankAccount($fixture['company'], [
            'code' => $bank->code, 'name' => 'Duplicate', 'currency_id' => $fixture['foreign']->id,
        ]))->toThrow(RuntimeException::class, 'already exists for this company')
        ->and(fn () => $service->createBankAccount($fixture['company'], [
            'code'                => 'OTHER-BANK-'.$fixture['company']->id,
            'name'                => 'Duplicate bank number',
            'currency_id'         => $fixture['foreign']->id,
            'bank_account_number' => $bank->accountingDetail->bank_account_number,
        ]))->toThrow(RuntimeException::class, 'bank account number already exists')
        ->and(fn () => $service->createBankAccount($fixture['company'], [
            'code'        => 'IBAN-BANK-'.$fixture['company']->id,
            'name'        => 'Duplicate IBAN',
            'currency_id' => $fixture['foreign']->id,
            'iban'        => $bank->accountingDetail->iban,
        ]))->toThrow(RuntimeException::class, 'IBAN already exists')
        ->and(fn () => $service->createBankAccount($fixture['company'], [
            'code'        => 'INACTIVE-BANK-'.$fixture['company']->id,
            'name'        => 'Inactive bank',
            'currency_id' => $fixture['foreign']->id,
            'active'      => false,
        ]))->toThrow(RuntimeException::class, 'must be active');
});

it('validates searchable Bank GL parents, resolves labels, creates parents inline, and rejects cross-company parents', function (): void {
    $fixture = multiCurrencyTestFixture();
    $fixture['company']->enabledCurrencies()->syncWithoutDetaching([
        $fixture['base']->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    $validParent = Account::factory()->create([
        'code'         => 'BANK-GROUP-'.$fixture['company']->id,
        'name'         => 'Operating Bank Accounts',
        'account_type' => AccountType::ASSET_CURRENT,
        'currency_id'  => $fixture['base']->id,
        'is_group'     => true,
        'deprecated'   => false,
    ]);
    $validParent->companies()->attach($fixture['company']->id);

    $expenseGroup = Account::factory()->create([
        'code'         => 'EXPENSE-GROUP-'.$fixture['company']->id,
        'name'         => 'Operating Expenses',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $fixture['base']->id,
        'is_group'     => true,
        'deprecated'   => false,
    ]);
    $expenseGroup->companies()->attach($fixture['company']->id);

    $otherCompany = Company::factory()->create([
        'currency_id' => $fixture['base']->id,
        'is_active'   => true,
    ]);
    $otherCompanyParent = Account::factory()->create([
        'code'         => 'OTHER-BANK-GROUP-'.$otherCompany->id,
        'name'         => 'Other Company Banks',
        'account_type' => AccountType::ASSET_CURRENT,
        'currency_id'  => $fixture['base']->id,
        'is_group'     => true,
        'deprecated'   => false,
    ]);
    $otherCompanyParent->companies()->attach($otherCompany->id);

    Permission::findOrCreate(AccountingPermissions::ImportBankStatementPage, 'web');
    Permission::findOrCreate(AccountingPermissions::CreateBankAccount, 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $fixture['user']->givePermissionTo([
        AccountingPermissions::ImportBankStatementPage,
        AccountingPermissions::CreateBankAccount,
    ]);
    test()->actingAs($fixture['user']);

    $bankModal = Livewire::test(ImportBankStatement::class)
        ->set('data.currency_id', $fixture['base']->id)
        ->mountFormComponentAction('bank_gl_account_id', 'createOption');

    $parentSelect = $bankModal->instance()
        ->getSchema('mountedActionSchema0')
        ?->getComponentByStatePath('parent_id');

    expect($parentSelect)->toBeInstanceOf(Select::class);

    $searchResults = $parentSelect->getSearchResults('Bank');
    expect($searchResults)->toHaveKey($validParent->id)
        ->not->toHaveKey($expenseGroup->id)
        ->not->toHaveKey($otherCompanyParent->id);

    $parentSelect->state($validParent->id);
    expect($parentSelect->getOptionLabel(withDefault: false))->toBe("{$validParent->code} {$validParent->name}")
        ->and($parentSelect->getInValidationRuleValues())->toBeNull();

    $parentSelect->state($otherCompanyParent->id);
    expect($parentSelect->getOptionLabel(withDefault: false))->toBeNull()
        ->and($parentSelect->getInValidationRuleValues())->toBe([]);

    $bankModal
        ->setFormComponentActionData([
            'code'                => 'MANUAL-BANK-'.$fixture['company']->id,
            'name'                => 'Manually Named Bank GL',
            'currency_id'         => $fixture['base']->id,
            'parent_id'           => $validParent->id,
            'bank_name'           => 'Manual Test Bank',
            'bank_account_number' => 'MANUAL-ACCOUNT-'.$fixture['company']->id,
            'iban'                => 'PK00MANUAL'.$fixture['company']->id,
            'branch_reference'    => 'Main Branch',
            'active'              => true,
        ])
        ->callMountedFormComponentAction()
        ->assertHasNoFormComponentActionErrors();

    $createdBank = Account::query()->where('code', 'MANUAL-BANK-'.$fixture['company']->id)->firstOrFail();
    expect($createdBank->parent_id)->toBe($validParent->id)
        ->and($createdBank->account_type)->toBe(AccountType::ASSET_CASH)
        ->and($createdBank->is_group)->toBeFalse()
        ->and($createdBank->accountingDetail->bank_name)->toBe('Manual Test Bank')
        ->and($createdBank->accountingDetail->branch_reference)->toBe('Main Branch');

    $inlineParentModal = Livewire::test(ImportBankStatement::class)
        ->set('data.currency_id', $fixture['base']->id)
        ->callFormComponentAction(
            ['bank_gl_account_id', 'mountedActionSchema0.parent_id'],
            ['createOption', 'createOption'],
            [
                'name'         => 'New Inline Bank Parent',
                'code'         => 'INLINE-BANK-PARENT-'.$fixture['company']->id,
                'company_id'   => $fixture['company']->id,
                'currency_id'  => $fixture['base']->id,
                'account_type' => AccountType::ASSET_CURRENT->value,
                'is_group'     => true,
            ],
        );

    $inlineParent = Account::query()->where('code', 'INLINE-BANK-PARENT-'.$fixture['company']->id)->firstOrFail();
    $inlineParentModal->assertSet('mountedActions.0.data.parent_id', (string) $inlineParent->id);
    expect($inlineParent->is_group)->toBeTrue()
        ->and($inlineParent->companies()->whereKey($fixture['company']->id)->exists())->toBeTrue();

    expect(fn () => app(CanonicalAccountCreationService::class)->createBankAccount($fixture['company'], [
        'code'                => 'INVALID-PARENT-BANK-'.$fixture['company']->id,
        'name'                => 'Invalid Parent Bank',
        'currency_id'         => $fixture['base']->id,
        'parent_id'           => $otherCompanyParent->id,
        'bank_account_number' => 'INVALID-PARENT-'.$fixture['company']->id,
    ]))->toThrow(RuntimeException::class, 'belongs to another company or does not exist');
});

it('registers the import permission for admin while preserving unauthorized 403 access', function (): void {
    $fixture = multiCurrencyTestFixture();
    $role = Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $result = app(AccountingPermissionRegistrar::class)->synchronize();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($result['permissions'])->toBe(count(array_unique(AccountingPermissions::all())))
        ->and($role->fresh()->hasPermissionTo(AccountingPermissions::ImportBankStatementPage))->toBeTrue();

    test()->actingAs($fixture['user']);
    test()->get(ImportBankStatement::getUrl())->assertForbidden();

    $fixture['user']->givePermissionTo(Permission::findByName(AccountingPermissions::ImportBankStatementPage, 'web'));
    test()->get(ImportBankStatement::getUrl())->assertOk();
});

it('creates balanced gain loss and reversal revaluation drafts idempotently', function (): void {
    $fixture = multiCurrencyTestFixture();
    $gain = multiCurrencyAccount($fixture, 'FX-REVAL-GAIN-'.$fixture['company']->id, AccountType::INCOME_OTHER);
    $loss = multiCurrencyAccount($fixture, 'FX-REVAL-LOSS-'.$fixture['company']->id, AccountType::EXPENSE);
    $cash = multiCurrencyAccount($fixture, 'FX-REVAL-CASH-'.$fixture['company']->id, AccountType::ASSET_CASH);
    $equity = multiCurrencyAccount($fixture, 'FX-REVAL-EQUITY-'.$fixture['company']->id, AccountType::EQUITY);
    $fixture['company']->update([
        'fx_gain_account_id' => $gain->id,
        'fx_loss_account_id' => $loss->id,
    ]);

    $journal = Journal::factory()->create([
        'company_id' => $fixture['company']->id,
        'currency_id'=> $fixture['base']->id,
        'type'       => JournalType::GENERAL,
    ]);
    $sourceMove = Move::factory()->create([
        'company_id' => $fixture['company']->id,
        'journal_id' => $journal->id,
        'currency_id'=> $fixture['foreign']->id,
        'state'      => MoveState::POSTED,
        'date'       => '2026-07-01',
    ]);

    MoveLine::withoutEvents(function () use ($fixture, $sourceMove, $journal, $cash, $equity): void {
        MoveLine::factory()->create([
            'move_id'                => $sourceMove->id,
            'journal_id'             => $journal->id,
            'company_id'             => $fixture['company']->id,
            'account_id'             => $cash->id,
            'currency_id'            => $fixture['foreign']->id,
            'original_currency_id'   => $fixture['foreign']->id,
            'company_currency_id'    => $fixture['base']->id,
            'date'                   => '2026-07-01',
            'parent_state'           => MoveState::POSTED,
            'debit'                  => '250.0000',
            'credit'                 => '0.0000',
            'balance'                => '250.0000',
            'original_debit'         => '100.0000',
            'original_credit'        => '0.0000',
            'original_signed_amount' => '100.0000',
            'company_debit'          => '250.0000',
            'company_credit'         => '0.0000',
            'company_signed_amount'  => '250.0000',
            'amount_currency'        => '100.0000',
        ]);
        MoveLine::factory()->create([
            'move_id'                => $sourceMove->id,
            'journal_id'             => $journal->id,
            'company_id'             => $fixture['company']->id,
            'account_id'             => $equity->id,
            'currency_id'            => $fixture['base']->id,
            'original_currency_id'   => $fixture['base']->id,
            'company_currency_id'    => $fixture['base']->id,
            'date'                   => '2026-07-01',
            'parent_state'           => MoveState::POSTED,
            'debit'                  => '0.0000',
            'credit'                 => '250.0000',
            'balance'                => '-250.0000',
            'original_debit'         => '0.0000',
            'original_credit'        => '250.0000',
            'original_signed_amount' => '-250.0000',
            'company_debit'          => '0.0000',
            'company_credit'         => '250.0000',
            'company_signed_amount'  => '-250.0000',
            'amount_currency'        => '-250.0000',
        ]);
    });

    $makeClosingRate = function (string $date, string $rate) use ($fixture): ExchangeRate {
        return ExchangeRate::query()->create([
            'company_id'         => $fixture['company']->id,
            'source_currency_id' => $fixture['foreign']->id,
            'target_currency_id' => $fixture['base']->id,
            'effective_date'     => $date,
            'rate'               => $rate,
            'rate_type'          => ExchangeRateType::PeriodClosing,
            'source'             => ExchangeRateSource::Manual,
            'approval_status'    => ExchangeRateApprovalStatus::Approved,
            'created_by'         => $fixture['user']->id,
            'approved_by'        => $fixture['user']->id,
            'approved_at'        => now(),
        ]);
    };
    $makeClosingRate('2026-07-31', '3.000000000000000');
    $makeClosingRate('2026-08-31', '2.000000000000000');

    test()->actingAs($fixture['user']);
    $service = app(FxRevaluationService::class);
    $gainRevaluation = $service->createDraft(
        $fixture['company']->fresh(),
        $fixture['foreign'],
        '2026-07-31',
        $journal,
        '2026-08-01',
    );
    $sameGainRevaluation = $service->createDraft(
        $fixture['company']->fresh(),
        $fixture['foreign'],
        '2026-07-31',
        $journal,
        '2026-08-01',
    );

    expect($gainRevaluation->difference)->toBe('50.0000')
        ->and($gainRevaluation->move->state)->toBe(MoveState::DRAFT)
        ->and($gainRevaluation->move->lines->sum('debit'))->toEqual(50.0)
        ->and($gainRevaluation->move->lines->sum('credit'))->toEqual(50.0)
        ->and($gainRevaluation->move->lines->where('account_id', $gain->id)->sum('credit'))->toEqual(50.0)
        ->and($gainRevaluation->reversalMove->lines->sum('debit'))->toEqual(50.0)
        ->and($gainRevaluation->reversalMove->lines->sum('credit'))->toEqual(50.0)
        ->and($sameGainRevaluation->id)->toBe($gainRevaluation->id);

    $lossRevaluation = $service->createDraft(
        $fixture['company']->fresh(),
        $fixture['foreign'],
        '2026-08-31',
        $journal,
    );

    expect($lossRevaluation->difference)->toBe('-50.0000')
        ->and($lossRevaluation->move->lines->sum('debit'))->toEqual(50.0)
        ->and($lossRevaluation->move->lines->sum('credit'))->toEqual(50.0)
        ->and($lossRevaluation->move->lines->where('account_id', $loss->id)->sum('debit'))->toEqual(50.0)
        ->and(FxRevaluation::query()->where('company_id', $fixture['company']->id)->count())->toBe(2);
});

it('renders company original and incomplete reporting modes consistently on financial statements', function (): void {
    $fixture = multiCurrencyTestFixture();
    $fixture['company']->enabledCurrencies()->syncWithoutDetaching([
        $fixture['reporting']->id => ['transaction_enabled' => false, 'reporting_enabled' => true],
    ]);
    foreach ([
        'page_accounting_balance_sheet',
        'page_accounting_profit_loss',
        'page_accounting_direct_cash_flow',
        AccountingPermissions::ViewMultiCurrencyReports,
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $fixture['user']->givePermissionTo([
        'page_accounting_balance_sheet',
        'page_accounting_profit_loss',
        'page_accounting_direct_cash_flow',
        AccountingPermissions::ViewMultiCurrencyReports,
    ]);
    test()->actingAs($fixture['user']);

    foreach ([BalanceSheet::class, ProfitLoss::class, DirectCashFlow::class] as $page) {
        Livewire::test($page)->assertOk()
            ->set('data.currency_mode', 'original')
            ->assertOk()
            ->set('data.reporting_currency_id', $fixture['reporting']->id)
            ->set('data.currency_mode', 'reporting')
            ->assertOk();
    }
});
