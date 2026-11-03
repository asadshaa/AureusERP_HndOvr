<?php

/**
 * One account row can be shared by several companies (accounts_account_companies).
 * Only a user who belongs to every linked company (or a group owner) may change
 * or delete it, and the account form never adds or detaches a company the
 * user is not in, whatever the (disabled) companies field submits.
 */

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\AccountResource\Pages\ManageAccounts;
use Webkul\Accounting\Models\Account;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';

beforeEach(function () {
    $this->companyA = Company::factory()->create(['is_active' => true]);
    $this->companyB = Company::factory()->create(['is_active' => true]);
});

function sharedAccountUser(Company $company): User
{
    $user = SecurityHelper::authenticateWithPermissions([
        'view_any_accounting_account', 'view_accounting_account', 'create_accounting_account',
        'update_accounting_account', 'delete_accounting_account', 'delete_any_accounting_account',
    ]);
    $user->forceFill(['default_company_id' => $company->id, 'resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    $user->allowedCompanies()->sync([]);
    CurrentCompany::flush();
    FilamentHelper::bootAdminPanel();

    return $user;
}

/** @param  array<int, Company>  $companies */
function sharedAccount(array $companies, string $name = 'Shared account'): Account
{
    $account = Account::factory()->create(['name' => $name]);
    $account->companies()->sync(collect($companies)->pluck('id')->all());

    return Account::query()->findOrFail($account->id);
}

it('denies updating or deleting an account also linked to a company the user is not in', function () {
    $user = sharedAccountUser($this->companyA);
    $account = sharedAccount([$this->companyA, $this->companyB]);

    $update = $user->can('update', $account);
    $response = Gate::forUser($user)->inspect('delete', $account);

    expect($update)->toBeFalse()
        ->and($response->allowed())->toBeFalse()
        ->and($response->message())->toContain('shared with companies you do not belong to');

    Livewire::test(ManageAccounts::class)
        ->assertTableActionHidden(EditAction::class, $account)
        ->assertTableActionHidden(DeleteAction::class, $account);

    expect(Account::query()->whereKey($account->id)->exists())->toBeTrue();
});

it('allows a user of every linked company to update a shared account', function () {
    $user = sharedAccountUser($this->companyA);
    $user->allowedCompanies()->sync([$this->companyB->id]);
    CurrentCompany::flush();

    expect($user->can('update', sharedAccount([$this->companyA, $this->companyB])))->toBeTrue();
});

it('never detaches or adds a company the user is not in when the companies field is tampered with', function () {
    $user = sharedAccountUser($this->companyA);
    $own = sharedAccount([$this->companyA], 'Own account');

    Livewire::test(ManageAccounts::class)
        ->mountTableAction(EditAction::class, $own)
        ->set('mountedActions.0.data.name', 'Own account renamed')
        ->set('mountedActions.0.data.companies', [(string) $this->companyB->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($own->refresh()->name)->toBe('Own account renamed')
        ->and($own->companies()->pluck('companies.id')->all())->toBe([$this->companyA->id]);

    $shared = sharedAccount([$this->companyA, $this->companyB]);
    $shared->syncCompaniesFor($user, [$this->companyA->id]);

    expect($shared->companies()->pluck('companies.id')->sort()->values()->all())
        ->toBe(collect([$this->companyA->id, $this->companyB->id])->sort()->values()->all());
});

it('builds the code tree only from the active company\'s accounts', function () {
    sharedAccountUser($this->companyA);

    sharedAccount([$this->companyA], 'Own tree account')->forceFill(['code' => '8Q001'])->save();
    sharedAccount([$this->companyB], 'Other tree account')->forceFill(['code' => '8W001'])->save();

    $tree = Livewire::test(ManageAccounts::class)->instance()->getCodeTreeProperty();

    expect($tree['8'] ?? [])->toContain('8Q')->not->toContain('8W');
});
