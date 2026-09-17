<?php

use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource\Pages\CreateExchangeRate;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

it('creates an exchange rate from the create form without a company_id validation crash', function () {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $foreign = Currency::query()->where('code', 'USD')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    Permission::query()->firstOrCreate(['name' => AccountingPermissions::ManageExchangeRates, 'guard_name' => 'web']);
    $role = Role::query()->firstOrCreate(['name' => 'ExchangeRateFormTestRole', 'guard_name' => 'web']);
    $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
    $user->assignRole($role);
    $user->forceFill(['resource_permission' => PermissionType::GLOBAL->value])->save();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    \Filament\Facades\Filament::bootCurrentPanel();
    test()->actingAs($user);

    // This is exactly the form the real create page renders and the real
    // "create" call it fires -- if the company_id select is missing its
    // options()/getOptionLabelUsing() configuration, Filament throws a
    // LogicException here instead of creating the record.
    Livewire::test(CreateExchangeRate::class)
        ->fillForm([
            'source_currency_id' => $currency->id,
            'target_currency_id' => $foreign->id,
            'effective_date'     => '2026-09-09',
            'rate'               => '280',
            'rate_type'          => ExchangeRateType::Transaction->value,
            'source'             => ExchangeRateSource::Manual->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rate = ExchangeRate::query()
        ->where('company_id', $company->id)
        ->where('source_currency_id', $currency->id)
        ->where('target_currency_id', $foreign->id)
        ->first();

    expect($rate)->not->toBeNull()
        ->and((float) $rate->rate)->toBe(280.0)
        ->and($rate->company_id)->toBe($company->id);
});
