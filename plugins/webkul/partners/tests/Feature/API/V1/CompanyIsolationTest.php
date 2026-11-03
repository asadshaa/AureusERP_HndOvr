<?php

/**
 * The partners API only reads and changes partners (and their addresses and
 * bank accounts) of the companies the token user belongs to (CurrentCompany
 * membership). Another company's partner is left out of lists and answers 404,
 * and a partner cannot be created in, moved to or parented by another company.
 */

use Illuminate\Support\Str;
use Webkul\Partner\Enums\AccountType;
use Webkul\Partner\Enums\AddressType;
use Webkul\Partner\Models\BankAccount;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Bank;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();

    $this->ownCompany = Company::factory()->create(['name' => 'API isolation ownCompany '.Str::random(8), 'is_active' => true]);
    $this->otherCompany = Company::factory()->create(['name' => 'API isolation otherCompany '.Str::random(8), 'is_active' => true]);

    $user = SecurityHelper::authenticateWithPermissions([
        'view_any_partner_partner', 'view_partner_partner', 'create_partner_partner', 'update_partner_partner',
        'delete_partner_partner', 'restore_partner_partner', 'force_delete_partner_partner',
    ]);

    $user->forceFill(['default_company_id' => $this->ownCompany->id, 'resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    $user->allowedCompanies()->sync([]);
    CurrentCompany::flush();
});

function companyIsolationPartner(Company $company, array $attributes = []): Partner
{
    return Partner::factory()->create([
        'account_type' => AccountType::INDIVIDUAL,
        'company_id'   => $company->id,
        'title_id'     => null,
        'industry_id'  => null,
        ...$attributes,
    ]);
}

it('lists only the partners of the API user\'s companies', function () {
    $own = companyIsolationPartner($this->ownCompany);
    $other = companyIsolationPartner($this->otherCompany);

    $ids = collect($this->getJson(route('admin.api.v1.partners.partners.index', ['filter[id]' => "{$own->id},{$other->id}"]))
        ->assertOk()
        ->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$own->id]);
});

it('answers 404 for another company\'s partner and leaves it untouched', function () {
    $other = companyIsolationPartner($this->otherCompany, ['name' => 'Other Co Partner']);

    $this->getJson(route('admin.api.v1.partners.partners.show', $other))->assertNotFound();
    $this->putJson(route('admin.api.v1.partners.partners.update', $other), ['name' => 'Renamed', 'account_type' => AccountType::INDIVIDUAL->value])->assertNotFound();
    $this->deleteJson(route('admin.api.v1.partners.partners.destroy', $other))->assertNotFound();
    $this->deleteJson(route('admin.api.v1.partners.partners.force-destroy', $other))->assertNotFound();

    expect($other->refresh()->name)->toBe('Other Co Partner')
        ->and($other->trashed())->toBeFalse();

    $this->getJson(route('admin.api.v1.partners.partners.show', companyIsolationPartner($this->ownCompany)))->assertOk();
});

it('hides another company\'s partner addresses and bank accounts', function () {
    $other = companyIsolationPartner($this->otherCompany);
    $address = companyIsolationPartner($this->otherCompany, ['account_type' => AccountType::ADDRESS, 'sub_type' => AddressType::INVOICE, 'parent_id' => $other->id]);
    $bankAccount = BankAccount::factory()->create(['partner_id' => $other->id, 'bank_id' => Bank::factory()->create()->id]);

    $this->getJson(route('admin.api.v1.partners.partners.addresses.index', $other))->assertNotFound();
    $this->getJson(route('admin.api.v1.partners.partners.addresses.show', [$other, $address]))->assertNotFound();
    $this->deleteJson(route('admin.api.v1.partners.partners.addresses.destroy', [$other, $address]))->assertNotFound();
    $this->getJson(route('admin.api.v1.partners.partners.bank-accounts.index', $other))->assertNotFound();
    $this->getJson(route('admin.api.v1.partners.partners.bank-accounts.show', [$other, $bankAccount]))->assertNotFound();
    $this->deleteJson(route('admin.api.v1.partners.partners.bank-accounts.destroy', [$other, $bankAccount]))->assertNotFound();

    expect(Partner::query()->whereKey($address->id)->exists())->toBeTrue()
        ->and(BankAccount::query()->whereKey($bankAccount->id)->exists())->toBeTrue();
});

it('creates and updates partners only in the API user\'s companies', function () {
    $payload = ['name' => 'Isolation Partner', 'account_type' => AccountType::INDIVIDUAL->value];

    $this->postJson(route('admin.api.v1.partners.partners.store'), [...$payload, 'company_id' => $this->otherCompany->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id']);

    $this->postJson(route('admin.api.v1.partners.partners.store'), [...$payload, 'parent_id' => companyIsolationPartner($this->otherCompany)->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_id']);

    // No company sent: the active company; an own parent is fine.
    $id = $this->postJson(route('admin.api.v1.partners.partners.store'), [...$payload, 'parent_id' => companyIsolationPartner($this->ownCompany)->id])
        ->assertCreated()
        ->json('data.id');

    $partner = Partner::query()->findOrFail($id);
    expect($partner->company_id)->toBe($this->ownCompany->id);

    // Neither moved to another company nor turned into a partner of no company.
    $this->patchJson(route('admin.api.v1.partners.partners.update', $partner), ['company_id' => $this->otherCompany->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id']);
    $this->patchJson(route('admin.api.v1.partners.partners.update', $partner), ['company_id' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id']);

    expect($partner->refresh()->company_id)->toBe($this->ownCompany->id);
});
