<?php

use Illuminate\Support\Facades\Schema;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Models\BusinessRule;
use Webkul\Accounting\Models\ConfigurationAudit;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Models\ImportRun;
use Webkul\Accounting\Services\Bank\BankJournalCreationService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Accounting\Services\Import\ConditionalRuleEngine;
use Webkul\Accounting\Services\Import\ImportExecutionService;
use Webkul\Accounting\Services\Import\ImportPreviewService;
use Webkul\Accounting\Services\Import\ImportProfileDefinitionService;
use Webkul\Accounting\Services\Import\ImportTransformationEngine;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function configurableImportFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $currency->update(['active' => true, 'is_iso_fiat' => true]);
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    test()->actingAs($user);

    return compact('currency', 'company', 'user');
}

it('installs the configurable import and FS Tag schema in the isolated test database', function (): void {
    expect(Schema::hasTable('accounting_import_profiles'))->toBeTrue()
        ->and(Schema::hasTable('accounting_import_profile_mappings'))->toBeTrue()
        ->and(Schema::hasTable('accounting_business_rules'))->toBeTrue()
        ->and(Schema::hasTable('accounting_import_runs'))->toBeTrue()
        ->and(Schema::hasTable('accounting_import_source_rows'))->toBeTrue()
        ->and(Schema::hasTable('accounting_fs_tags'))->toBeTrue()
        ->and(Schema::hasTable('accounting_party_classifications'))->toBeTrue()
        ->and(Schema::hasTable('accounting_configuration_audits'))->toBeTrue()
        ->and(Schema::hasColumns('accounting_bank_transaction_mappings', ['fs_tag_id', 'match_type', 'matched_reference']))->toBeTrue();
});

it('applies only whitelisted transformations and deterministic conditional rules', function (): void {
    $fixture = configurableImportFixture();
    $transformed = app(ImportTransformationEngine::class)->transform(' 1,250.5 ', [
        ['type' => 'trim'],
        ['type' => 'decimal', 'thousands_separator' => ',', 'decimal_separator' => '.', 'scale' => 4],
    ]);

    $rule = BusinessRule::query()->create([
        'company_id'    => $fixture['company']->id,
        'creator_id'    => $fixture['user']->id,
        'name'          => 'Normalize preferred customer',
        'entity_type'   => 'customer',
        'priority'      => 10,
        'conditions'    => [['field' => 'category', 'operator' => 'equals', 'value' => 'VIP']],
        'actions'       => [['type' => 'set', 'field' => 'payment_term', 'value' => 'NET30']],
        'is_active'     => true,
    ]);
    $result = app(ConditionalRuleEngine::class)->apply(['category' => 'VIP'], collect([$rule]));

    expect($transformed)->toBe('1250.5000')
        ->and($result['values']['payment_term'])->toBe('NET30')
        ->and($result['applied_rule_ids'])->toBe([$rule->id])
        ->and(ConfigurationAudit::query()->where('auditable_id', $rule->id)->where('event', 'created')->exists())->toBeTrue()
        ->and(fn () => app(ImportTransformationEngine::class)->transform('value', [['type' => 'php']]))->toThrow(InvalidArgumentException::class);
});

it('exports and imports reusable profile definitions as inactive company-owned versions', function (): void {
    $fixture = configurableImportFixture();
    $profile = ImportProfile::query()->create([
        'company_id'  => $fixture['company']->id, 'owner_id' => $fixture['user']->id, 'name' => 'Reusable customers',
        'entity_type' => 'customer', 'file_type' => 'xlsx', 'header_row' => 2, 'data_start_row' => 3,
        'version'     => 1, 'is_active' => true, 'activated_at' => now(),
    ]);
    $profile->mappings()->create([
        'position'        => 1, 'source_header' => 'Customer', 'target_field' => 'name',
        'transformations' => [['type' => 'trim']], 'is_required' => true,
    ]);

    $service = app(ImportProfileDefinitionService::class);
    $definition = $service->export($profile);
    $imported = $service->import($fixture['company'], $definition, $fixture['user']->id, 'Reusable customers copy');

    expect($definition['schema_version'])->toBe(1)
        ->and($imported->company_id)->toBe($fixture['company']->id)
        ->and($imported->owner_id)->toBe($fixture['user']->id)
        ->and($imported->name)->toBe('Reusable customers copy')
        ->and($imported->version)->toBe(1)
        ->and($imported->is_active)->toBeFalse()
        ->and($imported->mappings)->toHaveCount(1)
        ->and($imported->mappings->first()->target_field)->toBe('name');
});

it('previews source lineage and confirms customer rows without overwriting an existing company record', function (): void {
    $fixture = configurableImportFixture();
    $profile = ImportProfile::query()->create([
        'company_id'     => $fixture['company']->id,
        'owner_id'       => $fixture['user']->id,
        'name'           => 'Customer upload',
        'entity_type'    => 'customer',
        'file_type'      => 'csv',
        'header_row'     => 1,
        'data_start_row' => 2,
        'delimiter'      => ',',
        'encoding'       => 'UTF-8',
        'version'        => 1,
        'is_active'      => true,
        'activated_at'   => now(),
    ]);
    $profile->mappings()->createMany([
        ['position' => 1, 'source_header' => 'Customer ID', 'source_aliases' => ['Reference'], 'target_field' => 'reference', 'transformations' => [['type' => 'trim'], ['type' => 'upper']], 'is_required' => true],
        ['position' => 2, 'source_header' => 'Customer Name', 'target_field' => 'name', 'transformations' => [['type' => 'trim']], 'is_required' => true],
        ['position' => 3, 'source_header' => 'Email', 'target_field' => 'email', 'transformations' => [['type' => 'trim'], ['type' => 'lower']]],
    ]);

    $path = tempnam(sys_get_temp_dir(), 'aureus-import-');
    file_put_contents($path, "Customer ID,Customer Name,Email\n c-001 ,Alpha Customer,ALPHA@EXAMPLE.COM\n");

    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'customers.csv', $fixture['user']->id);
        expect($run->status)->toBe('previewed')
            ->and($run->total_rows)->toBe(1)
            ->and($run->passed_rows)->toBe(1)
            ->and($run->failed_rows)->toBe(0)
            ->and($run->sourceRows->first()->source_row_number)->toBe(2)
            ->and($run->sourceRows->first()->raw_values['Customer ID'])->toBe(' c-001 ')
            ->and($run->sourceRows->first()->transformed_values['reference'])->toBe('C-001')
            ->and($run->sourceRows->first()->transformed_values['email'])->toBe('alpha@example.com');

        $completed = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);
        $party = Partner::query()->where('company_id', $fixture['company']->id)->where('reference', 'C-001')->firstOrFail();

        expect($completed->status)->toBe('completed')
            ->and($completed->imported_rows)->toBe(1)
            ->and($party->name)->toBe('Alpha Customer')
            ->and($party->customer_rank)->toBe(1)
            ->and($completed->sourceRows->first()->canonical_id)->toBe($party->id)
            ->and(fn () => app(ImportExecutionService::class)->confirm($run, $fixture['user']->id))->toThrow(RuntimeException::class, 'Only a previewed import run');
    } finally {
        @unlink($path);
    }
});

it('rejects preview rows with cross-company document references', function (): void {
    $fixture = configurableImportFixture();
    $otherCompany = Company::factory()->create(['currency_id' => $fixture['currency']->id, 'is_active' => true]);
    Partner::query()->create([
        'company_id' => $otherCompany->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'reference'  => 'OTHER-001', 'name' => 'Other Company Customer',
    ]);
    Journal::factory()->create(['company_id' => $fixture['company']->id, 'code' => 'SALE-'.$fixture['company']->id]);

    $profile = ImportProfile::query()->create([
        'company_id'  => $fixture['company']->id, 'owner_id' => $fixture['user']->id, 'name' => 'Invoice upload',
        'entity_type' => 'invoice', 'file_type' => 'csv', 'header_row' => 1, 'data_start_row' => 2,
        'delimiter'   => ',', 'encoding' => 'UTF-8', 'version' => 1, 'is_active' => true, 'activated_at' => now(),
    ]);
    foreach (['reference', 'partner_reference', 'date', 'currency', 'amount_total', 'journal_code'] as $position => $field) {
        $profile->mappings()->create(['position' => $position + 1, 'source_header' => $field, 'target_field' => $field, 'is_required' => true]);
    }

    $path = tempnam(sys_get_temp_dir(), 'aureus-import-');
    file_put_contents($path, "reference,partner_reference,date,currency,amount_total,journal_code\nINV-1,OTHER-001,2026-07-28,PKR,100,".'SALE-'.$fixture['company']->id."\n");

    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'invoices.csv', $fixture['user']->id);
        expect($run->failed_rows)->toBe(1)
            ->and(collect($run->sourceRows->first()->messages)->pluck('message'))->toContain('The party reference does not exist in this company.')
            ->and(fn () => app(ImportExecutionService::class)->confirm($run))->toThrow(RuntimeException::class, 'Correct all preview errors');
    } finally {
        @unlink($path);
    }
});

it('creates balanced draft document lines through explicit company GL mappings', function (): void {
    $fixture = configurableImportFixture();
    $partner = Partner::query()->create([
        'company_id' => $fixture['company']->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'reference'  => 'CUST-'.$fixture['company']->id, 'name' => 'Mapped Customer', 'email' => 'customer@example.test',
    ]);
    $journal = Journal::factory()->create([
        'company_id' => $fixture['company']->id, 'currency_id' => $fixture['currency']->id,
        'code'       => 'SI'.$fixture['company']->id, 'type' => JournalType::SALE,
    ]);
    $debit = Account::factory()->create([
        'code'        => 'AR-'.$fixture['company']->id, 'name' => 'Receivable', 'account_type' => AccountType::ASSET_RECEIVABLE,
        'currency_id' => $fixture['currency']->id, 'is_group' => false, 'deprecated' => false, 'reconcile' => true,
    ]);
    $credit = Account::factory()->create([
        'code'        => 'REV-'.$fixture['company']->id, 'name' => 'Revenue', 'account_type' => AccountType::INCOME,
        'currency_id' => $fixture['currency']->id, 'is_group' => false, 'deprecated' => false,
    ]);
    $debit->companies()->attach($fixture['company']->id);
    $credit->companies()->attach($fixture['company']->id);

    $profile = ImportProfile::query()->create([
        'company_id'  => $fixture['company']->id, 'owner_id' => $fixture['user']->id, 'name' => 'Mapped invoices',
        'entity_type' => 'invoice', 'file_type' => 'csv', 'header_row' => 1, 'data_start_row' => 2,
        'delimiter'   => ',', 'encoding' => 'UTF-8', 'version' => 1, 'is_active' => true, 'activated_at' => now(),
    ]);
    $fields = [
        'reference', 'partner_reference', 'customer_name', 'customer_email', 'billing_address', 'date', 'payment_term',
        'due_date', 'location', 'booking_id', 'consolidated_number', 'drop_off', 'product_service', 'description',
        'quantity', 'rate', 'tax_percent', 'currency', 'amount_total', 'journal_code', 'debit_gl_code', 'credit_gl_code',
    ];
    foreach ($fields as $position => $field) {
        $profile->mappings()->create([
            'position'        => $position + 1, 'source_header' => $field, 'target_field' => $field,
            'is_required'     => in_array($field, ['reference', 'date', 'currency', 'amount_total', 'journal_code', 'debit_gl_code', 'credit_gl_code'], true),
            'transformations' => match (true) {
                in_array($field, ['date', 'due_date'], true)                                => [['type' => 'date']],
                in_array($field, ['quantity', 'rate', 'tax_percent', 'amount_total'], true) => [['type' => 'decimal', 'scale' => 4]],
                default                                                                     => [['type' => 'trim']],
            },
        ]);
    }
    $path = tempnam(sys_get_temp_dir(), 'aureus-document-profile-');
    file_put_contents($path, implode(',', $fields)."\nINV-MAPPED-1,{$partner->reference},Mapped Customer,customer@example.test,32 Example Road,2026-07-28,,2026-08-27,Karachi,BKG-1001,CON-2001,Port Qasim,Freight service,Freight delivery,1,1234.56,0,PKR,1234.56,{$journal->code},{$debit->code},{$credit->code}\n");

    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'mapped-invoices.csv', $fixture['user']->id);
        expect($run->failed_rows)->toBe(0);
        $completed = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);
        $move = Move::query()->where('accounting_source_type', ImportRun::class)->where('accounting_source_id', $run->id)->firstOrFail();

        expect($completed->imported_rows)->toBe(1)
            ->and($move->state)->toBe(MoveState::DRAFT)
            ->and($move->lines)->toHaveCount(2)
            ->and($move->lines->sum(fn ($line) => (float) $line->debit))->toBe(1234.56)
            ->and($move->lines->sum(fn ($line) => (float) $line->credit))->toBe(1234.56)
            ->and($move->lines->pluck('account_id'))->toContain($debit->id, $credit->id)
            ->and($move->invoice_source_email)->toBe('customer@example.test')
            ->and($move->billing_address)->toBe('32 Example Road')
            ->and($move->incoterm_location)->toBe('Karachi')
            ->and($move->booking_id)->toBe('BKG-1001')
            ->and($move->consolidated_number)->toBe('CON-2001')
            ->and($move->drop_off)->toBe('Port Qasim')
            ->and($move->invoiceLines->first()?->source_product_service)->toBe('Freight service')
            ->and((float) $move->invoiceLines->first()?->quantity)->toBe(1.0)
            ->and((float) $move->invoiceLines->first()?->price_unit)->toBe(1234.56)
            ->and((float) $move->invoiceLines->first()?->source_tax_percent)->toBe(0.0);

        $posted = AccountFacade::confirmMove($move->fresh());
        expect($posted->state)->toBe(MoveState::POSTED)
            ->and((float) $posted->amount_residual)->toBe(1234.56)
            ->and($posted->paymentTermLines->first()?->account_id)->toBe($debit->id);
    } finally {
        @unlink($path);
    }
});

it('creates company-scoped FS Tags with an existing or automatically-created canonical GL', function (): void {
    $fixture = configurableImportFixture();
    $existing = Account::factory()->create([
        'code'        => 'EXP-'.$fixture['company']->id, 'name' => 'Bank fees', 'account_type' => AccountType::EXPENSE,
        'currency_id' => $fixture['currency']->id, 'is_group' => false, 'deprecated' => false,
    ]);
    $existing->companies()->attach($fixture['company']->id);

    $first = app(FsTagService::class)->create($fixture['company'], [
        'name' => 'Bank Fees', 'account_id' => $existing->id, 'cash_flow_category' => 'Operating', 'is_active' => true,
    ]);
    $second = app(FsTagService::class)->create($fixture['company'], [
        'name'         => 'Courier Charges', 'create_account' => true, 'account_name' => 'Courier Charges',
        'account_type' => AccountType::EXPENSE->value, 'currency_id' => $fixture['currency']->id, 'is_active' => true,
    ]);

    expect($first->code)->toStartWith('FS-')
        ->and($first->normalized_name)->toBe('bank fees')
        ->and($first->account_id)->toBe($existing->id)
        ->and($second->account_id)->not->toBeNull()
        ->and($second->account->code)->toStartWith('FSGL-')
        ->and($second->account->companies->modelKeys())->toContain($fixture['company']->id)
        ->and(fn () => app(FsTagService::class)->create($fixture['company'], [
            'name' => 'Other tag', 'account_id' => Account::factory()->create(['account_type' => AccountType::EXPENSE])->id,
        ]))->toThrow(RuntimeException::class, 'company');
});

it('imports an FS Tag Registry row without creating or overwriting GL accounts', function (): void {
    $fixture = configurableImportFixture();
    $account = Account::factory()->create([
        'code'        => 'TAG-GL-'.$fixture['company']->id,
        'name'        => 'Imported tag GL',
        'account_type'=> AccountType::EXPENSE,
        'currency_id' => $fixture['currency']->id,
        'is_group'    => false,
        'deprecated'  => false,
    ]);
    $account->companies()->attach($fixture['company']->id);
    $profile = ImportProfile::query()->create([
        'company_id'  => $fixture['company']->id, 'owner_id' => $fixture['user']->id, 'name' => 'FS Tag Registry',
        'entity_type' => 'fs_tag', 'file_type' => 'csv', 'header_row' => 1, 'data_start_row' => 2,
        'delimiter'   => ',', 'encoding' => 'UTF-8', 'version' => 1, 'is_active' => true, 'activated_at' => now(),
    ]);
    foreach (['code', 'name', 'gl_code', 'cash_flow_category', 'tax_treatment', 'is_active'] as $position => $field) {
        $profile->mappings()->create([
            'position'        => $position + 1, 'source_header' => $field, 'target_field' => $field,
            'transformations' => $field === 'is_active' ? [['type' => 'boolean']] : [['type' => 'trim']],
            'is_required'     => in_array($field, ['code', 'name'], true),
        ]);
    }

    $path = tempnam(sys_get_temp_dir(), 'aureus-fs-tag-');
    file_put_contents($path, "code,name,gl_code,cash_flow_category,tax_treatment,is_active\nfs-import-1,Imported Fees,{$account->code},Operating,Exempt,true\n");

    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'fs-tags.csv', $fixture['user']->id);
        $completed = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);
        $tag = FsTag::query()->where('company_id', $fixture['company']->id)->where('code', 'FS-IMPORT-1')->firstOrFail();

        expect($run->failed_rows)->toBe(0)
            ->and($completed->imported_rows)->toBe(1)
            ->and($tag->account_id)->toBe($account->id)
            ->and($tag->cash_flow_category)->toBe('Operating')
            ->and($tag->tax_treatment)->toBe('Exempt')
            ->and($tag->is_active)->toBeTrue()
            ->and(Account::query()->where('code', $account->code)->count())->toBe(1);
    } finally {
        @unlink($path);
    }
});

it('creates a currency-compatible bank journal inline and rejects duplicate company codes', function (): void {
    $fixture = configurableImportFixture();
    $bank = Account::factory()->create([
        'code'        => 'BANK-'.$fixture['company']->id, 'name' => 'Operating Bank', 'account_type' => AccountType::ASSET_CASH,
        'currency_id' => $fixture['currency']->id, 'is_group' => false, 'deprecated' => false,
    ]);
    $bank->companies()->attach($fixture['company']->id);

    $journal = app(BankJournalCreationService::class)->create($fixture['company'], [
        'company_id'         => $fixture['company']->id, 'currency_id' => $fixture['currency']->id,
        'default_account_id' => $bank->id, 'name' => 'Operating Bank', 'code' => 'OB'.$fixture['company']->id, 'is_active' => true,
    ]);

    expect($journal->company_id)->toBe($fixture['company']->id)
        ->and($journal->currency_id)->toBe($fixture['currency']->id)
        ->and($journal->default_account_id)->toBe($bank->id)
        ->and($journal->type->value)->toBe('bank')
        ->and(fn () => app(BankJournalCreationService::class)->create($fixture['company'], [
            'currency_id' => $fixture['currency']->id, 'default_account_id' => $bank->id,
            'name'        => 'Duplicate', 'code' => $journal->code,
        ]))->toThrow(RuntimeException::class, 'already exists');
});

it('imports configured bank rows through the canonical currency and reconciliation workflow', function (): void {
    $fixture = configurableImportFixture();
    $bank = Account::factory()->create([
        'code'        => 'CFG-BANK-'.$fixture['company']->id, 'name' => 'Configured Bank', 'account_type' => AccountType::ASSET_CASH,
        'currency_id' => $fixture['currency']->id, 'is_group' => false, 'deprecated' => false,
    ]);
    $bank->companies()->attach($fixture['company']->id);
    $offset = Account::factory()->create([
        'code'        => 'CFG-FEE-'.$fixture['company']->id, 'name' => 'Configured Fee', 'account_type' => AccountType::EXPENSE,
        'currency_id' => $fixture['currency']->id, 'is_group' => false, 'deprecated' => false,
    ]);
    $offset->companies()->attach($fixture['company']->id);
    $tagOffset = Account::factory()->create([
        'code'        => 'CFG-TAG-'.$fixture['company']->id, 'name' => 'Tag fallback', 'account_type' => AccountType::EXPENSE,
        'currency_id' => $fixture['currency']->id, 'is_group' => false, 'deprecated' => false,
    ]);
    $tagOffset->companies()->attach($fixture['company']->id);
    $journal = app(BankJournalCreationService::class)->create($fixture['company'], [
        'currency_id' => $fixture['currency']->id, 'default_account_id' => $bank->id,
        'name'        => 'Configured Bank', 'code' => 'CB'.$fixture['company']->id,
    ]);
    $tag = app(FsTagService::class)->create($fixture['company'], [
        'code'               => 'FS-BANK-'.$fixture['company']->id, 'name' => 'Bank Charges', 'account_id' => $tagOffset->id,
        'cash_flow_category' => 'Operating', 'is_active' => true,
    ]);

    $profile = ImportProfile::query()->create([
        'company_id'  => $fixture['company']->id, 'owner_id' => $fixture['user']->id, 'name' => 'Configured bank rows',
        'entity_type' => 'bank_statement', 'file_type' => 'csv', 'header_row' => 1, 'data_start_row' => 2,
        'delimiter'   => ',', 'encoding' => 'UTF-8', 'version' => 1, 'is_active' => true, 'activated_at' => now(),
    ]);
    $headers = [
        'date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code',
        'bank_name', 'account_title', 'reference', 'debit', 'credit', 'opening_balance', 'closing_balance', 'balance', 'fs_tag',
        'offset_gl_code', 'transaction_type', 'counterparty', 'cash_flow_category', 'tax_treatment', 'supporting_document',
    ];
    foreach ($headers as $position => $field) {
        $transformations = in_array($field, ['debit', 'credit', 'opening_balance', 'closing_balance', 'balance'], true)
            ? [['type' => 'decimal', 'scale' => 4]]
            : [['type' => 'trim']];
        $profile->mappings()->create([
            'position'        => $position + 1, 'source_header' => $field, 'target_field' => $field,
            'transformations' => $transformations, 'validation_rules' => $field === 'date' ? ['date'] : [],
            'is_required'     => in_array($field, ['date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code'], true),
        ]);
    }

    $path = tempnam(sys_get_temp_dir(), 'aureus-bank-profile-');
    file_put_contents($path, implode(',', $headers)."\n2026-07-28,PKR,001122,Monthly bank fee,{$journal->code},{$bank->code},Test Bank,Main Account,FEE-1,100,0,1000,900,900,{$tag->code},{$offset->code},Bank charge,Test Bank,Operating,Exempt,FEE-DOC-1\n");

    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'configured-bank.csv', $fixture['user']->id);
        expect($run->failed_rows)->toBe(0);

        $completed = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);
        $statement = BankStatement::query()->where('company_id', $fixture['company']->id)->where('file_hash', hash_file('sha256', $path))->firstOrFail();
        $line = $statement->lines()->with('mapping.fsTag')->firstOrFail();

        expect($completed->status)->toBe('completed')
            ->and($statement->currency_id)->toBe($fixture['currency']->id)
            ->and($statement->opening_balance)->toBe('1000.0000')
            ->and($statement->closing_balance)->toBe('900.0000')
            ->and($line->original_debit)->toBe('100.0000')
            ->and($line->mapping->fs_tag_id)->toBe($tag->id)
            ->and($line->mapping->offset_account_id)->toBe($offset->id)
            ->and($line->mapping->transaction_type)->toBe('Bank charge')
            ->and($line->mapping->counterparty)->toBe('Test Bank')
            ->and($line->mapping->cash_flow_category)->toBe('Operating')
            ->and($line->mapping->tax_treatment)->toBe('Exempt')
            ->and($line->mapping->supporting_document)->toBe('FEE-DOC-1')
            ->and($line->mapping->match_type)->toBe('source_gl')
            // The source offset GL contradicts the FS Tag's GL: kept as supplied, but surfaced for review...
            ->and($line->mapping->review_status->value)->toBe('needs_review')
            ->and($line->mapping->fs_tag_issue)->toContain("FS Tag {$tag->code} is configured for a different GL account than the source offset GL {$offset->code}");

        // ...and approval refuses to stamp the tag on a GL account it is not configured for.
        expect(fn () => app(BankMappingService::class)->approve($line->mapping, $fixture['user'], false))
            ->toThrow(RuntimeException::class, "does not match FS Tag {$tag->code}");

        // Choosing the tag's own GL resolves the conflict; the source columns are still used.
        $line->mapping->update(['offset_account_id' => $tagOffset->id]);
        $approved = app(BankMappingService::class)->approve($line->mapping->fresh(), $fixture['user'], false);
        expect($approved->offset_account_id)->toBe($tagOffset->id)
            ->and($approved->match_type)->toBe('source_gl')
            ->and($approved->cash_flow_category)->toBe('Operating');
    } finally {
        @unlink($path);
    }
});
