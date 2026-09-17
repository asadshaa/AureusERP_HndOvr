<?php

use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Webkul\Accounting\Filament\Clusters\Accounting\Pages\ImportBankStatement;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Bank\BankStatementPreviewService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function previewFsTagFixtureCsv(string $headerLabel = 'FS Tag'): string
{
    $rows = [
        ['HBL', 'Bank Statement', '', '', '', '', '', ''],
        ['', 'Preview Test Co', '', '', '', '', '1000.0000', ''],
        ['', 'PREVIEW-ACCT-001', '', '', '', '', '80.0000', ''],
        ['', '2026-01-01 to 2026-01-31', '', '', '', '', '0.0000', ''],
        ['', 'PKR', '', '', '', '', '920.0000', ''],
        ['Transaction Date', 'Value Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance', $headerLabel],
        ['2026-01-05', '2026-01-05', 'Bank Fee', 'REF-1', '50.0000', '0.0000', '950.0000', 'FS-PREVIEW-FEE'],
        ['2026-01-06', '2026-01-06', 'Mystery Charge', 'REF-2', '30.0000', '0.0000', '920.0000', 'FS-PREVIEW-UNKNOWN'],
        ['2026-01-07', '2026-01-07', 'Untagged Deposit', 'REF-3', '0.0000', '0.0000', '920.0000', ''],
    ];

    $path = tempnam(sys_get_temp_dir(), 'preview_fs_tag_').'.csv';
    $handle = fopen($path, 'w');
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

function previewFsTagFixture(): array
{
    $currency = Currency::query()->where('name', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    test()->actingAs($user);

    $tag = FsTag::query()->create([
        'company_id' => $company->id,
        'code'       => 'FS-PREVIEW-FEE',
        'name'       => 'Preview Fee',
        'is_active'  => true,
        'creator_id' => $user->id,
    ]);

    grantImportBankStatementAccess($user);

    return compact('currency', 'company', 'user', 'tag');
}

function grantImportBankStatementAccess(User $user): void
{
    Permission::query()->firstOrCreate(['name' => AccountingPermissions::ImportBankStatementPage, 'guard_name' => 'web']);
    $role = \Webkul\Security\Models\Role::query()->firstOrCreate(['name' => 'PreviewFsTagTestRole', 'guard_name' => 'web']);
    $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
    $user->assignRole($role);
    $user->forceFill(['resource_permission' => \Webkul\Security\Enums\PermissionType::GLOBAL->value])->save();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    \Filament\Facades\Filament::bootCurrentPanel();
}

/**
 * Render the Import Bank Statement page's real Livewire component with a
 * preview already computed, bypassing the file-upload UI (which isn't what
 * this test is about) while still exercising the actual page/Blade -- so
 * $this->form and everything else the template relies on resolves for real.
 */
function renderPreviewPage(array $preview): string
{
    return Livewire::test(ImportBankStatement::class)
        ->set('preview', $preview)
        ->html();
}

it('reports recognized, unrecognized and untagged counts on the preview', function () {
    $fixture = previewFsTagFixture();

    $preview = app(BankStatementPreviewService::class)->preview(
        previewFsTagFixtureCsv('FS Tag'),
        $fixture['company'],
        $fixture['currency'],
        'hbl',
    );

    expect($preview['fs_tag_column_found'])->toBeTrue()
        ->and($preview['fs_tag_summary'])->toBe(['recognized' => 1, 'unrecognized' => 1, 'none' => 1]);

    $rows = collect($preview['rows'])->keyBy('description');

    expect($rows['Bank Fee']['fs_tag_status'])->toBe('recognized')
        ->and($rows['Bank Fee']['fs_tag_code'])->toBe('FS-PREVIEW-FEE')
        ->and($rows['Bank Fee']['fs_tag_issue'])->toBeNull()
        ->and($rows['Mystery Charge']['fs_tag_status'])->toBe('unrecognized')
        ->and($rows['Mystery Charge']['fs_tag_code'])->toBe('FS-PREVIEW-UNKNOWN')
        ->and($rows['Mystery Charge']['fs_tag_issue'])->toContain('FS-PREVIEW-UNKNOWN')
        ->and($rows['Untagged Deposit']['fs_tag_status'])->toBe('none')
        ->and($rows['Untagged Deposit']['fs_tag_code'])->toBeNull();
});

it('reports no FS Tag column found when the header does not look like one', function () {
    $fixture = previewFsTagFixture();

    $preview = app(BankStatementPreviewService::class)->preview(
        previewFsTagFixtureCsv('Memo'),
        $fixture['company'],
        $fixture['currency'],
        'hbl',
    );

    expect($preview['fs_tag_column_found'])->toBeFalse()
        ->and($preview['fs_tag_summary'])->toBe(['recognized' => 0, 'unrecognized' => 0, 'none' => 3]);
});

it('renders the FS Tag check section on the preview screen without error', function () {
    $fixture = previewFsTagFixture();

    $preview = app(BankStatementPreviewService::class)->preview(
        previewFsTagFixtureCsv('FS Tag'),
        $fixture['company'],
        $fixture['currency'],
        'hbl',
    );

    $html = renderPreviewPage($preview);

    expect($html)
        ->toContain('FS Tag check')
        ->toContain('1 recognized')
        ->toContain('1 unrecognized')
        ->toContain('1 untagged')
        ->toContain('Mystery Charge')
        ->toContain('FS-PREVIEW-UNKNOWN')
        ->toContain('Not set up yet');
});

it('warns about a missing FS Tag column only when the company actually uses the feature', function () {
    $fixture = previewFsTagFixture();

    $preview = app(BankStatementPreviewService::class)->preview(
        previewFsTagFixtureCsv('Memo'),
        $fixture['company'],
        $fixture['currency'],
        'hbl',
    );

    $html = renderPreviewPage($preview);

    expect($html)->toContain('FS Tag check')
        ->toContain('No "FS Tag" column was recognized');
});

it('does not render the FS Tag check section when the file has no tags and the company does not use them', function () {
    $currency = Currency::query()->where('name', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    test()->actingAs($user);
    grantImportBankStatementAccess($user);

    $preview = app(BankStatementPreviewService::class)->preview(
        previewFsTagFixtureCsv('Memo'),
        $company,
        $currency,
        'hbl',
    );

    $html = renderPreviewPage($preview);

    expect($html)->not->toContain('FS Tag check');
});
