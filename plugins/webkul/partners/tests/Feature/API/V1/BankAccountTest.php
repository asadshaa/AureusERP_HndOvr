<?php

use Webkul\Partner\Enums\AccountType;
use Webkul\Partner\Models\BankAccount;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Bank;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function actingAsBankAccountApiUser(array $permissions = []): void
{
    // A group owner belongs to every company, so the company-scoped API finds the factory records.
    SecurityHelper::authenticateWithPermissions([...$permissions, CurrentCompany::MANAGE_MEMBERSHIPS]);
}

function createBankPartner(): Partner
{
    return Partner::factory()->create([
        'account_type' => AccountType::INDIVIDUAL,
        'company_id'   => Company::query()->orderBy('id')->value('id'),
        'title_id'     => null,
        'industry_id'  => null,
    ]);
}

function createBankAccountRecord(Partner $partner, ?Bank $bank = null): BankAccount
{
    return BankAccount::factory()->create([
        'partner_id' => $partner->id,
        'bank_id'    => $bank?->id ?? Bank::factory()->create()->id,
    ]);
}

function bankAccountRoute(string $action, Partner $partner, mixed $bankAccount = null): string
{
    $name = "admin.api.v1.partners.partners.bank-accounts.{$action}";

    return $bankAccount ? route($name, [$partner, $bankAccount]) : route($name, $partner);
}

it('requires authentication to list bank accounts', function () {
    $partner = createBankPartner();

    $this->getJson(bankAccountRoute('index', $partner))->assertUnauthorized();
});

it('forbids listing bank accounts without parent partner view permission', function () {
    $partner = createBankPartner();
    actingAsBankAccountApiUser();

    $this->getJson(bankAccountRoute('index', $partner))->assertForbidden();
});

it('lists bank accounts for authorized users', function () {
    actingAsBankAccountApiUser(['view_partner_partner']);
    $partner = createBankPartner();
    createBankAccountRecord($partner);
    createBankAccountRecord($partner);

    $this->getJson(bankAccountRoute('index', $partner))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('creates a bank account for authorized users', function () {
    actingAsBankAccountApiUser(['update_partner_partner']);
    $partner = createBankPartner();
    $bank = Bank::factory()->create();

    $payload = BankAccount::factory()->make([
        'bank_id'    => $bank->id,
        'partner_id' => $partner->id,
    ])->toArray();

    $this->postJson(bankAccountRoute('store', $partner), $payload)
        ->assertCreated()
        ->assertJsonPath('message', 'Bank account created successfully.');
});

it('validates required fields when creating a bank account', function (string $field) {
    actingAsBankAccountApiUser(['update_partner_partner']);
    $partner = createBankPartner();
    $bank = Bank::factory()->create();

    $payload = BankAccount::factory()->make([
        'bank_id'    => $bank->id,
        'partner_id' => $partner->id,
    ])->toArray();
    unset($payload[$field]);

    $this->postJson(bankAccountRoute('store', $partner), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with(['account_number', 'can_send_money', 'bank_id']);

it('shows a bank account for authorized users', function () {
    actingAsBankAccountApiUser(['view_partner_partner']);
    $partner = createBankPartner();
    $bankAccount = createBankAccountRecord($partner);

    $this->getJson(bankAccountRoute('show', $partner, $bankAccount))
        ->assertOk()
        ->assertJsonPath('data.id', $bankAccount->id);
});

it('returns 404 for a non-existent bank account', function () {
    actingAsBankAccountApiUser(['view_partner_partner']);
    $partner = createBankPartner();

    $this->getJson(bankAccountRoute('show', $partner, 999999))
        ->assertNotFound();
});

it('updates a bank account for authorized users', function () {
    actingAsBankAccountApiUser(['update_partner_partner']);
    $partner = createBankPartner();
    $bankAccount = createBankAccountRecord($partner);

    $this->patchJson(bankAccountRoute('update', $partner, $bankAccount), ['account_number' => 'ACC-UPDATED-001'])
        ->assertOk()
        ->assertJsonPath('message', 'Bank account updated successfully.')
        ->assertJsonPath('data.account_number', 'ACC-UPDATED-001');
});

it('deletes, restores and force deletes a bank account for authorized users', function () {
    actingAsBankAccountApiUser(['update_partner_partner']);
    $partner = createBankPartner();
    $bankAccount = createBankAccountRecord($partner);

    $this->deleteJson(bankAccountRoute('destroy', $partner, $bankAccount))
        ->assertOk()
        ->assertJsonPath('message', 'Bank account deleted successfully.');

    $this->postJson(bankAccountRoute('restore', $partner, $bankAccount->id))
        ->assertOk()
        ->assertJsonPath('message', 'Bank account restored successfully.');

    $bankAccount->delete();

    $this->deleteJson(bankAccountRoute('force-destroy', $partner, $bankAccount->id))
        ->assertOk()
        ->assertJsonPath('message', 'Bank account permanently deleted.');
});
