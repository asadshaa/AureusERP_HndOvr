<?php

/**
 * FS Tag create and edit both go through FsTagService: a duplicate or blank
 * code is reported to the user (no raw exception, no QueryException), and
 * edits apply the same per-company rules as creation. New FSGL account codes
 * follow the per-company account code rule.
 */

use Livewire\Livewire;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages\CreateFsTag;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FsTagResource\Pages\EditFsTag;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

beforeEach(function () {
    $currency = Currency::query()->where('name', 'PKR')->first() ?? Currency::query()->firstOrFail();
    $this->company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $user = SecurityHelper::authenticateWithPermissions([AccountingPermissions::ManageFsTags]);
    $user->forceFill(['default_company_id' => $this->company->id, 'resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    CurrentCompany::flush();
    FilamentHelper::bootAdminPanel();

    $this->service = app(FsTagService::class);
    $this->existing = $this->service->create($this->company, ['code' => 'FS-PAGE-1', 'name' => 'Existing tag']);
});

it('reports a duplicate code on create instead of failing', function () {
    Livewire::test(CreateFsTag::class)
        ->fillForm(['code' => 'fs-page-1', 'name' => 'Duplicate tag'])
        ->call('create')
        ->assertNotified('The FS Tag was not created');

    expect(FsTag::query()->where('company_id', $this->company->id)->where('name', 'Duplicate tag')->exists())->toBeFalse();
});

it('validates edits through the service: duplicate and blank codes are refused', function () {
    $tag = $this->service->create($this->company, ['code' => 'FS-PAGE-2', 'name' => 'Second tag']);

    Livewire::test(EditFsTag::class, ['record' => $tag->getRouteKey()])
        ->fillForm(['code' => 'FS-PAGE-1'])
        ->call('save')
        ->assertNotified('The FS Tag was not saved');

    expect($tag->refresh()->code)->toBe('FS-PAGE-2');

    expect(fn () => $this->service->update($tag, ['code' => '   ']))->toThrow(RuntimeException::class, 'code is required');

    Livewire::test(EditFsTag::class, ['record' => $tag->getRouteKey()])
        ->fillForm(['code' => 'FS-PAGE-3', 'name' => 'Renamed tag'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->refresh()->code)->toBe('FS-PAGE-3')
        ->and($tag->name)->toBe('Renamed tag');
});

it('numbers a new FSGL account per company, skipping codes the company already uses', function () {
    $other = Company::factory()->create(['currency_id' => $this->company->currency_id, 'is_active' => true]);
    $taken = Account::factory()->create(['code' => 'FSGL-000001', 'account_type' => AccountType::EXPENSE]);
    $taken->companies()->sync([$other->id]);

    $tag = $this->service->create($this->company, ['name' => 'Auto account tag', 'create_account' => true, 'account_type' => AccountType::EXPENSE->value]);

    expect($tag->account->code)->toStartWith('FSGL-')
        ->and(Account::query()->where('code', $tag->account->code)->linkedToCompanies([$this->company->id])->count())->toBe(1);
});
