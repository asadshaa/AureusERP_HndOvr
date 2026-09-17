<?php

use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource\Pages\ListBankTransactionMappings;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function fsTagStatementCsv(string $headerLabel): string
{
    $rows = [
        ['HBL', 'Bank Statement', '', '', '', '', '', ''],
        ['', 'Test Company Ltd', '', '', '', '', '1000.0000', ''],
        ['', 'FSTAG-ACCT-001', '', '', '', '', '80.0000', ''],
        ['', '2026-01-01 to 2026-01-31', '', '', '', '', '0.0000', ''],
        ['', 'PKR', '', '', '', '', '920.0000', ''],
        ['Transaction Date', 'Value Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance', $headerLabel],
        // A row whose FS Tag code is set up for this company.
        ['2026-01-05', '2026-01-05', 'Bank Fee', 'REF-1', '50.0000', '0.0000', '950.0000', 'FS-BANK-FEE'],
        // A row whose FS Tag code was never set up anywhere.
        ['2026-01-06', '2026-01-06', 'Mystery Charge', 'REF-2', '30.0000', '0.0000', '920.0000', 'FS-DOES-NOT-EXIST'],
    ];

    $path = tempnam(sys_get_temp_dir(), 'fs_tag_stmt_').'.csv';
    $handle = fopen($path, 'w');
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

function fsTagImportFixture(): array
{
    $currency = Currency::query()->where('name', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    test()->actingAs($user);

    $bank = Account::factory()->create([
        'currency_id'  => $currency->id,
        'creator_id'   => $user->id,
        'code'         => 'FSTAG-BANK-'.$company->id,
        'name'         => 'FS Tag test bank',
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
        'code'               => 'FSJ'.$company->id,
        'name'               => 'FS Tag test bank journal',
        'type'               => JournalType::BANK,
    ]);

    $tag = FsTag::query()->create([
        'company_id' => $company->id,
        'code'       => 'FS-BANK-FEE',
        'name'       => 'Bank Fee',
        'is_active'  => true,
        'creator_id' => $user->id,
    ]);

    return compact('currency', 'company', 'user', 'bank', 'journal', 'tag');
}

it('recognizes the FS Tag column even when its header is spelled differently', function () {
    $fixture = fsTagImportFixture();

    $statement = app(BankStatementImportService::class)->import(
        fsTagStatementCsv('fs_tag'), // deliberately not the literal "FS Tag"
        $fixture['company'],
        $fixture['journal'],
        $fixture['bank'],
        $fixture['currency'],
        'hbl',
    );

    $lines = BankStatementLine::query()->where('statement_id', $statement->id)->orderBy('sort')->with('mapping')->get();

    expect($lines)->toHaveCount(2);

    $recognized = $lines[0]->mapping;
    $unrecognized = $lines[1]->mapping;

    expect($recognized->fs_tag_id)->toBe($fixture['tag']->id)
        ->and($recognized->fs_tag_raw_code)->toBe('FS-BANK-FEE')
        ->and($recognized->fs_tag_issue)->toBeNull();

    // The unrecognized code must be visible verbatim, with a plain-language
    // reason — not silently dropped, and not indistinguishable from a blank
    // cell.
    expect($unrecognized->fs_tag_id)->toBeNull()
        ->and($unrecognized->fs_tag_raw_code)->toBe('FS-DOES-NOT-EXIST')
        ->and($unrecognized->fs_tag_issue)->toContain('FS-DOES-NOT-EXIST')
        ->and($unrecognized->fs_tag_issue)->toContain("isn't set up yet");
});

it('does not tag anything when no column looks like an FS Tag column at all', function () {
    $fixture = fsTagImportFixture();

    $statement = app(BankStatementImportService::class)->import(
        fsTagStatementCsv('Memo'), // unrelated column name, no FS Tag intent
        $fixture['company'],
        $fixture['journal'],
        $fixture['bank'],
        $fixture['currency'],
        'hbl',
    );

    $lines = BankStatementLine::query()->where('statement_id', $statement->id)->with('mapping')->get();

    foreach ($lines as $line) {
        expect($line->mapping->fs_tag_id)->toBeNull()
            ->and($line->mapping->fs_tag_raw_code)->toBeNull();
    }
});

it('finds the FS Tag column across case, spacing and separator variants', function () {
    $header = ['Transaction Date', 'Description', 'Debit', 'Credit'];

    foreach (['FS Tag', 'fs tag', 'FS_TAG', 'Fs-Tag', ' FSTag ', 'fstag'] as $variant) {
        $index = BankStatementImportService::findFsTagColumnIndex([array_merge($header, [$variant])]);

        expect($index)->toBe(4, "Expected \"{$variant}\" to be recognized as the FS Tag column.");
    }

    expect(BankStatementImportService::findFsTagColumnIndex([array_merge($header, ['Memo'])]))->toBeFalse();
});

it('gives a distinct, plain-language reason for each way an FS Tag code can fail to resolve', function () {
    $companyA = Company::factory()->create(['is_active' => true]);
    $companyB = Company::factory()->create(['is_active' => true]);
    $service = app(FsTagService::class);

    FsTag::query()->create(['company_id' => $companyB->id, 'code' => 'FS-OTHERCO', 'name' => 'Other Co Tag', 'is_active' => true]);
    FsTag::query()->create(['company_id' => $companyA->id, 'code' => 'FS-RETIRED', 'name' => 'Retired Tag', 'is_active' => false]);

    expect($service->diagnose($companyA->id, 'FS-NEVER-EXISTED'))->toContain("isn't set up yet")
        ->and($service->diagnose($companyA->id, 'FS-OTHERCO'))->toContain('belongs to a different company')
        ->and($service->diagnose($companyA->id, 'FS-RETIRED'))->toContain('retired')
        ->and($service->diagnose($companyA->id, ''))->toBeNull();
});

it('shows the unrecognized code in the Bank Transaction Mapping grid instead of a blank cell', function () {
    $fixture = fsTagImportFixture();

    Permission::query()->firstOrCreate(['name' => 'view_any_accounting_bank_transaction_mapping', 'guard_name' => 'web']);
    $role = \Webkul\Security\Models\Role::query()->firstOrCreate(['name' => 'FsTagGridTestRole', 'guard_name' => 'web']);
    $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
    $fixture['user']->assignRole($role);
    $fixture['user']->forceFill(['resource_permission' => PermissionType::GLOBAL->value])->save();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    \Filament\Facades\Filament::bootCurrentPanel();

    $statement = app(BankStatementImportService::class)->import(
        fsTagStatementCsv('FS Tag'),
        $fixture['company'],
        $fixture['journal'],
        $fixture['bank'],
        $fixture['currency'],
        'hbl',
    );

    $lines = BankStatementLine::query()->where('statement_id', $statement->id)->orderBy('sort')->with('mapping')->get();
    $recognizedMapping = $lines[0]->mapping;
    $unrecognizedMapping = $lines[1]->mapping;

    // assertTableColumnFormattedStateSet() calls formatState(getState())
    // directly, which is NOT what the real page renders: TextColumn's own
    // toEmbeddedHtml() checks blank($rawState) BEFORE ever calling
    // formatStateUsing()/the column's computed state, and swaps in the
    // placeholder instead of running the formatter at all when the
    // underlying `fsTag.code` relationship value is null. A column built
    // with formatStateUsing() alone can pass this assertion while still
    // rendering blank on the actual page -- exactly what happened here
    // during manual testing. Assert on toEmbeddedHtml() too so this stays
    // caught.
    $testable = Livewire::test(ListBankTransactionMappings::class)
        // A resolved tag still just shows its own code, unchanged.
        ->assertTableColumnFormattedStateSet('fsTag.code', 'FS-BANK-FEE', $recognizedMapping)
        // An unresolved one shows the raw code the user typed, marked
        // unrecognized -- not a blank cell indistinguishable from a
        // transaction that was never tagged at all.
        ->assertTableColumnFormattedStateSet('fsTag.code', 'FS-DOES-NOT-EXIST (unrecognized)', $unrecognizedMapping);

    $column = $testable->instance()->getTable()->getColumn('fsTag.code');

    $column->record($recognizedMapping->fresh('fsTag'));
    expect(strip_tags($column->toEmbeddedHtml()))->toContain('FS-BANK-FEE');

    $column->record($unrecognizedMapping->fresh('fsTag'));
    expect(strip_tags($column->toEmbeddedHtml()))->toContain('FS-DOES-NOT-EXIST (unrecognized)');
});
