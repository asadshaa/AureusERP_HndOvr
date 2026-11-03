<?php

/**
 * The account form checks codes per company (through accounts_account_companies),
 * like the API and the account services, and only accepts a parent that is a
 * group account of the same company and section, never one that loops.
 */

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Livewire\Livewire;
use Webkul\Account\Enums\AccountType;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\AccountResource\Pages\ManageAccounts;
use Webkul\Accounting\Models\Account;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

beforeEach(function () {
    $this->company = Company::factory()->create(['is_active' => true]);
    $this->otherCompany = Company::factory()->create(['is_active' => true]);

    $user = SecurityHelper::authenticateWithPermissions([
        'view_any_accounting_account', 'view_accounting_account', 'create_accounting_account', 'update_accounting_account',
    ]);
    $user->forceFill(['default_company_id' => $this->company->id, 'resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    CurrentCompany::flush();
    FilamentHelper::bootAdminPanel();
});

function formAccount(Company $company, array $attributes = []): Account
{
    $account = Account::factory()->create($attributes);
    $account->companies()->sync([$company->id]);

    return Account::query()->findOrFail($account->id);
}

it('rejects a code already used in the same company but allows one used only by another company', function () {
    formAccount($this->company, ['code' => 'FORM-100']);
    formAccount($this->otherCompany, ['code' => 'FORM-200']);

    Livewire::test(ManageAccounts::class)
        ->callAction(CreateAction::class, data: ['code' => 'FORM-100', 'name' => 'Duplicate', 'account_type' => AccountType::ASSET_CURRENT->value])
        ->assertHasActionErrors(['code']);

    Livewire::test(ManageAccounts::class)
        ->callAction(CreateAction::class, data: ['code' => 'FORM-200', 'name' => 'Own account', 'account_type' => AccountType::ASSET_CURRENT->value])
        ->assertHasNoActionErrors();

    $created = Account::query()->where('code', 'FORM-200')->linkedToCompanies([$this->company->id])->first();

    expect($created)->not->toBeNull()
        ->and($created->companies()->pluck('companies.id')->all())->toBe([$this->company->id]);
});

it('only accepts a group parent of the same company and section', function () {
    $assetGroup = formAccount($this->company, ['code' => null, 'name' => 'Current assets group', 'is_group' => true, 'account_type' => AccountType::ASSET_CURRENT]);
    $expenseGroup = formAccount($this->company, ['code' => null, 'name' => 'Expenses group', 'is_group' => true, 'account_type' => AccountType::EXPENSE]);
    $otherGroup = formAccount($this->otherCompany, ['code' => null, 'name' => 'Other company group', 'is_group' => true, 'account_type' => AccountType::ASSET_CURRENT]);
    $postable = formAccount($this->company, ['code' => 'FORM-300', 'account_type' => AccountType::ASSET_CURRENT]);

    $create = fn (int $parentId, string $code) => Livewire::test(ManageAccounts::class)
        ->callAction(CreateAction::class, data: ['code' => $code, 'name' => 'Child', 'account_type' => AccountType::ASSET_CASH->value, 'parent_id' => $parentId]);

    $create($expenseGroup->id, 'FORM-301')->assertHasActionErrors(['parent_id']);
    $create($otherGroup->id, 'FORM-302')->assertHasActionErrors(['parent_id']);
    $create($postable->id, 'FORM-303')->assertHasActionErrors(['parent_id']);
    $create($assetGroup->id, 'FORM-304')->assertHasNoActionErrors();

    expect(Account::query()->where('code', 'FORM-304')->value('parent_id'))->toBe($assetGroup->id);
});

it('refuses to place an account under its own sub-account', function () {
    $top = formAccount($this->company, ['code' => 'FORM-400', 'name' => 'Top group', 'is_group' => true, 'account_type' => AccountType::ASSET_CURRENT]);
    $child = formAccount($this->company, ['code' => 'FORM-401', 'name' => 'Child group', 'is_group' => true, 'account_type' => AccountType::ASSET_CURRENT, 'parent_id' => $top->id]);

    Livewire::test(ManageAccounts::class)
        ->callTableAction(EditAction::class, $top, data: ['parent_id' => $child->id])
        ->assertHasTableActionErrors(['parent_id']);

    expect($top->refresh()->parent_id)->toBeNull()
        ->and(fn () => $top->update(['parent_id' => $child->id]))->toThrow(InvalidArgumentException::class);
});
