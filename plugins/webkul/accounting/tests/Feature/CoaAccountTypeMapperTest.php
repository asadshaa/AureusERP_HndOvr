<?php

/**
 * The CoA type mapper never crosses a row's Nature (P&L rows take income or
 * expense types, B/S rows asset/liability/equity types), matches the
 * classification path before the title, maps trade/accounts payable and
 * receivable to the control types, and the validator blocks an override that
 * contradicts the Nature. Overrides chosen in the preview reach the import.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Data\Coa\CoaRow;
use Webkul\Accounting\Data\Coa\CoaWarning;
use Webkul\Accounting\Filament\Clusters\Configuration\Pages\ImportChartOfAccounts;
use Webkul\Accounting\Services\Coa\CoaAccountTypeMapper;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaRowValidator;
use Webkul\Accounting\Services\Coa\CoaUploadPathResolver;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

/** @param  array<int, string>  $classifications */
function mapperRow(string $nature, array $classifications, string $title, string $code = '9001'): CoaRow
{
    return new CoaRow(1, $nature, $classifications, $code, $title, 0, 0, 0, 0, 0, 0, 0, 0);
}

dataset('coa rows that used to be misclassified', [
    'bad debts written off (P&L expense, not a receivable)' => [
        mapperRow('P&L', ['Expenses', 'Administrative expenses'], 'Bad debts - trade debts written off'), AccountType::EXPENSE,
    ],
    'interest on lease liabilities (P&L expense, not a liability)' => [
        mapperRow('P&L', ['Expenses', 'Operating expenses'], 'Interest on lease liabilities'), AccountType::EXPENSE,
    ],
    'interest on lease liabilities under finance cost' => [
        mapperRow('P&L', ['Expenses', 'Finance cost'], 'Interest on lease liabilities'), AccountType::EXPENSE,
    ],
    'revenue share paid (P&L expense, not income)' => [
        mapperRow('P&L', ['Expenses', 'Direct expenses'], 'Revenue share paid to partners'), AccountType::EXPENSE_DIRECT_COST,
    ],
    'revenue share paid without a sub-classification' => [
        mapperRow('P&L', ['Expenses'], 'Revenue share paid to partners'), AccountType::EXPENSE,
    ],
    'reserves (B/S equity, not a current asset)' => [
        mapperRow('B/S', ['Reserves'], 'Revaluation surplus'), AccountType::EQUITY,
    ],
    'accounts payable (control payable, not a current liability)' => [
        mapperRow('B/S', ['Liabilities', 'Current liabilities', 'Trade and other payables'], 'Accounts payable'), AccountType::LIABILITY_PAYABLE,
    ],
    'trade payables' => [
        mapperRow('B/S', ['Liabilities', 'Current liabilities'], 'Trade payables'), AccountType::LIABILITY_PAYABLE,
    ],
    'other payables stay current liabilities' => [
        mapperRow('B/S', ['Liabilities', 'Current liabilities', 'Trade and other payables'], 'Salaries payable'), AccountType::LIABILITY_CURRENT,
    ],
    'accounts receivable' => [
        mapperRow('B/S', ['Assets', 'Current assets'], 'Accounts receivable'), AccountType::ASSET_RECEIVABLE,
    ],
    'trade debts under assets' => [
        mapperRow('B/S', ['Assets', 'Current assets', 'Trade debts'], 'Customer A'), AccountType::ASSET_RECEIVABLE,
    ],
    'P&L row filed under assets gets an expense type' => [
        mapperRow('P&L', ['Assets', 'Current Assets'], 'A Rec written off'), AccountType::EXPENSE,
    ],
]);

it('suggests a type consistent with the row\'s Nature and section', function (CoaRow $row, AccountType $expected) {
    $mapper = new CoaAccountTypeMapper;
    $suggested = $mapper->suggest($row);

    expect($suggested)->toBe($expected)
        ->and($mapper->natureAllows($row, $suggested))->toBeTrue()
        ->and($mapper->allowedTypes($row))->toContain($suggested);
})->with('coa rows that used to be misclassified');

it('blocks a type override that contradicts the Nature and accepts one that does not', function () {
    $bsRow = mapperRow('B/S', ['Liabilities', 'Current liabilities'], 'Accrued expenses', '2001');
    $plRow = mapperRow('P&L', ['Expenses'], 'Rent', '6001');
    $validator = new CoaRowValidator;

    $errors = fn (array $overrides): array => collect($validator->validate([$bsRow, $plRow], $overrides))
        ->filter(fn (CoaWarning $warning) => $warning->isError())
        ->map(fn (CoaWarning $warning) => $warning->code)
        ->values()
        ->all();

    expect($errors(['2001' => AccountType::EXPENSE->value, '6001' => AccountType::ASSET_RECEIVABLE->value]))
        ->toBe(['nature_type_mismatch', 'nature_type_mismatch'])
        ->and($errors(['2001' => 'not_a_type']))->toBe(['invalid_type_override'])
        ->and($errors(['2001' => AccountType::LIABILITY_NON_CURRENT->value, '6001' => AccountType::EXPENSE_DIRECT_COST->value]))->toBe([]);
});

it('imports a row with the type chosen as an override, and refuses a contradicting override', function () {
    $currency = Currency::query()->where('name', 'PKR')->first() ?? Currency::query()->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $rows = [
        mapperRow('B/S', ['Liabilities', 'Current liabilities'], 'Accrued expenses', 'MAP-2001'),
        mapperRow('P&L', ['Expenses'], 'Rent', 'MAP-6001'),
    ];

    expect(fn () => app(CoaImportService::class)->import(rows: $rows, company: $company, typeOverrides: ['MAP-6001' => AccountType::ASSET_CURRENT->value]))
        ->toThrow(RuntimeException::class, 'contradicts Nature');

    $batch = app(CoaImportService::class)->import(rows: $rows, company: $company, typeOverrides: ['MAP-2001' => AccountType::LIABILITY_NON_CURRENT->value]);

    $types = Account::query()->where('import_batch_id', $batch->id)->whereIn('code', ['MAP-2001', 'MAP-6001'])->pluck('account_type', 'code');

    expect($types['MAP-2001'])->toBe(AccountType::LIABILITY_NON_CURRENT)
        ->and($types['MAP-6001'])->toBe(AccountType::EXPENSE)
        ->and($batch->options['type_overrides'])->toBe(['MAP-2001' => AccountType::LIABILITY_NON_CURRENT->value]);
});

it('shows the mapped type of every row in the import preview and passes the chosen override to the import', function () {
    if (Schema::hasTable('plugins')) {
        DB::table('plugins')->updateOrInsert(['name' => 'accounting'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    }

    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    FilamentHelper::actingAs(['page_accounting_import_chart_of_accounts']);

    $tmp = tempnam(sys_get_temp_dir(), 'coa').'.csv';
    copy(base_path('Chart_of_Accounts_Trial_Balance_Test.csv'), $tmp);

    $page = Livewire::test(ImportChartOfAccounts::class)
        ->set('data.file', $tmp)
        ->set('data.file_original_name', 'Chart_of_Accounts_Trial_Balance_Test.csv')
        ->call('preview');

    $types = collect($page->instance()->previewData['types']);
    $index = $types->search(fn (array $choice) => $choice['code'] === '1011');

    expect($types)->toHaveCount(62)
        ->and($index)->not->toBeFalse()
        ->and($page->instance()->typeOverrides[$index])->toBe($types[$index]['suggested'])
        ->and($page->instance()->chosenTypeOverrides())->toBe([]);

    $page->set("typeOverrides.{$index}", AccountType::ASSET_CURRENT->value);

    expect($page->instance()->chosenTypeOverrides())->toBe(['1011' => AccountType::ASSET_CURRENT->value]);

    app(CoaUploadPathResolver::class)->cleanup($page->instance()->workingFilePath);
    @unlink($tmp);
});
