<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function duplicateRowStatementCsv(): string
{
    $rows = [
        ['HBL', 'Bank Statement', '', '', '', '', ''],
        ['', 'Test Company Ltd', '', '', '', '', '1000.0000'],
        ['', 'DUP-ACCT-001', '', '', '', '', '200.0000'],
        ['', '2026-01-01 to 2026-01-31', '', '', '', '', '0.0000'],
        ['', 'PKR', '', '', '', '', '800.0000'],
        ['Transaction Date', 'Value Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance'],
        // Two transactions with identical date, description, reference, amount
        // and reported running balance — a genuine within-file duplicate, the
        // way the same line can appear twice in an exported statement.
        ['2026-01-05', '2026-01-05', 'Duplicate Vendor Payment', 'REF-100', '100.0000', '0.0000', '900.0000'],
        ['2026-01-05', '2026-01-05', 'Duplicate Vendor Payment', 'REF-100', '100.0000', '0.0000', '900.0000'],
    ];

    $path = tempnam(sys_get_temp_dir(), 'bank_stmt_').'.csv';
    $handle = fopen($path, 'w');
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

it('imports the rest of the statement instead of crashing on a within-file duplicate row', function () {
    $currency = Currency::query()->where('name', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    test()->actingAs($user);

    $bank = Account::factory()->create([
        'currency_id'  => $currency->id,
        'creator_id'   => $user->id,
        'code'         => 'DUP-BANK-'.$company->id,
        'name'         => 'Duplicate test bank',
        'account_type' => AccountType::ASSET_CASH,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $bank->companies()->attach($company->id);

    $journal = Journal::factory()->create([
        'company_id'         => $company->id,
        'currency_id'        => $currency->id,
        'creator_id'         => $user->id,
        'default_account_id' => $bank->id,
        'code'               => 'DB'.$company->id,
        'name'               => 'Duplicate test bank journal',
        'type'               => JournalType::BANK,
    ]);

    $path = duplicateRowStatementCsv();

    $statement = app(BankStatementImportService::class)->import(
        $path,
        $company,
        $journal,
        $bank,
        $currency,
        'hbl',
    );

    // The import must complete (not throw/roll back) and flag the duplicate
    // for review, but only persist one line for it — not crash on the
    // second row's unique-constraint collision.
    expect($statement)->not->toBeNull()
        ->and(collect($statement->validation_errors)->pluck('code'))->toContain('duplicate_row')
        ->and(BankStatementLine::query()->where('statement_id', $statement->id)->count())->toBe(1);
});
