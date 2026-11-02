<?php

use Database\Seeders\LogisticsCompanyAndProductSeeder;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Product as AccountProduct;
use Webkul\Account\Models\Tax;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/AccountHelper.php';
require_once dirname(__DIR__, 6).'/database/seeders/LogisticsCompanyAndProductSeeder.php';

beforeEach(function () {
    $this->admin = AccountHelper::actingAsAdmin();

    // Run the logistics company and product seeder idempotently
    $seeder = new LogisticsCompanyAndProductSeeder;
    $seeder->run();
});

it('seeds all legitimate companies with proper tax and registration profiles', function () {
    $companies = [
        'Truck It In (Pvt) Ltd' => [
            'ntn'     => '4289104-7',
            'strn'    => '3277876123456',
            'city'    => 'Karachi',
            'tax_reg' => true,
        ],
    ];

    foreach ($companies as $name => $expected) {
        $company = Company::where('name', $name)->first();
        expect($company)->not->toBeNull()
            ->and($company->tax_id)->toBe($expected['ntn'])
            ->and($company->strn)->toBe($expected['strn'])
            ->and($company->city)->toBe($expected['city'])
            ->and((bool) $company->is_sales_tax_registered)->toBe($expected['tax_reg'])
            ->and($company->is_active)->toBeTrue();
    }

    // Sister companies: Pakistan / PKR; registration and tax details are filled in later.
    foreach (['ShipLine', 'OpenPort'] as $name) {
        $company = Company::where('name', $name)->first();
        expect($company)->not->toBeNull()
            ->and($company->country?->code)->toBe('PK')
            ->and($company->currency?->code)->toBe('PKR')
            ->and($company->is_active)->toBeTrue();
    }
});

it('ensures each company has standard journals and isolated taxes with valid partitions', function () {
    $companies = Company::whereIn('name', [
        'Truck It In (Pvt) Ltd',
        'ShipLine',
        'OpenPort',
    ])->get();

    expect($companies->count())->toBe(3);

    foreach ($companies as $company) {
        // Must have Sale and Purchase journals
        $saleJournal = Journal::where('company_id', $company->id)
            ->where('type', JournalType::SALE)
            ->first();
        $purchaseJournal = Journal::where('company_id', $company->id)
            ->where('type', JournalType::PURCHASE)
            ->first();

        expect($saleJournal)->not->toBeNull()
            ->and($purchaseJournal)->not->toBeNull();

        // Must have active taxes
        $taxes = Tax::where('company_id', $company->id)->where('is_active', true)->get();
        expect($taxes->count())->toBeGreaterThanOrEqual(3);

        foreach ($taxes as $tax) {
            // Verify tax partition integrity (4 partitions: invoice/refund base/tax)
            $partitions = $tax->invoiceRepartitionLines()->get();
            expect($partitions->count())->toBeGreaterThanOrEqual(2);

            $taxPartition = $partitions->firstWhere('repartition_type', 'tax');
            expect($taxPartition)->not->toBeNull()
                ->and($taxPartition->account_id)->not->toBeNull();
        }
    }
});

it('seeds legitimate freight and procurement products with correct accounts and tax linkages', function () {
    // FTL Freight Service
    $ftl = AccountProduct::where('name', 'Full Truckload (FTL) Freight - Karachi to Lahore (40ft Flatbed)')->first();
    expect($ftl)->not->toBeNull()
        ->and((float) $ftl->price)->toBe(195000.0)
        ->and((float) $ftl->cost)->toBe(145000.0)
        ->and((bool) $ftl->sales_ok)->toBeTrue()
        ->and($ftl->property_account_income_id)->not->toBeNull()
        ->and($ftl->productTaxes()->count())->toBeGreaterThanOrEqual(1);

    // Diesel Fuel Consumable
    $diesel = AccountProduct::where('name', 'High-Speed Diesel (Bulk Fuel - Liters)')->first();
    expect($diesel)->not->toBeNull()
        ->and((float) $diesel->cost)->toBe(285.0)
        ->and((bool) $diesel->purchase_ok)->toBeTrue()
        ->and($diesel->property_account_expense_id)->not->toBeNull()
        ->and($diesel->supplierTaxes()->count())->toBeGreaterThanOrEqual(1);

    // Truck Tires
    $tires = AccountProduct::where('name', 'Heavy Duty Commercial Truck Tires (315/80R22.5 Tubeless)')->first();
    expect($tires)->not->toBeNull()
        ->and((float) $tires->cost)->toBe(95000.0)
        ->and((bool) $tires->purchase_ok)->toBeTrue()
        ->and($tires->supplierTaxes()->count())->toBeGreaterThanOrEqual(1);
});

it('scopes taxes by company when selecting products on customer invoices and vendor bills', function () {
    $tii = Company::where('name', 'Truck It In (Pvt) Ltd')->first();
    $fleet = Company::where('name', 'ShipLine')->first();

    $ftl = AccountProduct::where('name', 'Full Truckload (FTL) Freight - Karachi to Lahore (40ft Flatbed)')->first();

    // Query sale taxes for FTL under Company 1 (TII)
    $tiiTaxes = $ftl->productTaxes()->where('accounts_taxes.company_id', $tii->id)->get();
    expect($tiiTaxes->count())->toBeGreaterThanOrEqual(1)
        ->and($tiiTaxes->pluck('company_id')->unique()->all())->toBe([$tii->id]);

    // Query sale taxes for FTL under Company 2 (ShipLine)
    $fleetTaxes = $ftl->productTaxes()->where('accounts_taxes.company_id', $fleet->id)->get();
    expect($fleetTaxes->count())->toBeGreaterThanOrEqual(1)
        ->and($fleetTaxes->pluck('company_id')->unique()->all())->toBe([$fleet->id]);

    // Taxes for TII should not overlap with ShipLine taxes
    $intersect = $tiiTaxes->pluck('id')->intersect($fleetTaxes->pluck('id'));
    expect($intersect->isEmpty())->toBeTrue();
});

it('keeps an existing first company identity and only fills in blank fields on a re-run', function () {
    $company = Company::findOrFail(1);
    $company->forceFill(['name' => 'Renamed Holding', 'tax_id' => 'NTN-KEEP', 'strn' => null])->saveQuietly();

    (new LogisticsCompanyAndProductSeeder)->run();

    $company->refresh();

    expect($company->name)->toBe('Renamed Holding')
        ->and($company->tax_id)->toBe('NTN-KEEP')
        ->and($company->strn)->toBe('3277876123456');
});

it('replaces the installer placeholder identity of the first company on a fresh install', function () {
    Company::findOrFail(1)->forceFill(['name' => 'Default Company', 'tax_id' => null, 'email' => 'admin@example.com'])->saveQuietly();

    (new LogisticsCompanyAndProductSeeder)->run();

    $company = Company::findOrFail(1);

    expect($company->name)->toBe('Truck It In (Pvt) Ltd')
        ->and($company->tax_id)->toBe('4289104-7')
        ->and($company->email)->toBe('accounts@truckitin.com');
});

it('never adds existing users to the seeded companies', function () {
    $user = User::factory()->create(['default_company_id' => 1, 'is_active' => true]);
    $shipLine = Company::where('name', 'ShipLine')->firstOrFail();
    $openPort = Company::where('name', 'OpenPort')->firstOrFail();

    (new LogisticsCompanyAndProductSeeder)->run();

    expect($user->allowedCompanies()->pluck('companies.id')->all())
        ->not->toContain($shipLine->id)
        ->not->toContain($openPort->id);
});

it('adds only the initial admin to the companies the seeder creates', function () {
    Company::where('name', 'OpenPort')->firstOrFail()->forceFill(['name' => 'OpenPort (renamed)', 'company_id' => 'OPP-OLD'])->saveQuietly();
    $user = User::factory()->create(['default_company_id' => 1, 'is_active' => true]);
    $admin = User::query()->where('is_default', true)->orderBy('id')->first() ?? User::query()->orderBy('id')->firstOrFail();

    (new LogisticsCompanyAndProductSeeder)->run();
    (new LogisticsCompanyAndProductSeeder)->run();

    $created = Company::where('name', 'OpenPort')->firstOrFail();

    expect($admin->allowedCompanies()->whereKey($created->id)->count())->toBe(1)
        ->and($user->allowedCompanies()->whereKey($created->id)->exists())->toBeFalse();
});
