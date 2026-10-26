<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\AmountType;
use Webkul\Account\Enums\CommunicationStandard;
use Webkul\Account\Enums\CommunicationType;
use Webkul\Account\Enums\DocumentType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\RepartitionType;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Product as AccountProduct;
use Webkul\Account\Models\Tax;
use Webkul\Account\Models\TaxGroup;
use Webkul\Account\Models\TaxPartition;
use Webkul\Product\Enums\ProductType;
use Webkul\Product\Models\Category;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\UOM;

class LogisticsCompanyAndProductSeeder extends Seeder
{
    public function run(): void
    {
        if (! Auth::check()) {
            $admin = User::first();
            if ($admin) {
                Auth::login($admin);
            }
        }

        DB::transaction(function () {
            $currencies = $this->ensureCurrencies();
            $companies = $this->seedCompanies($currencies);
            $this->assignUsersToCompanies($companies);
            $this->seedJournals($companies);
            $taxesByCompany = $this->seedTaxesAndPartitions($companies);
            $this->seedCategoriesAndProducts($taxesByCompany);
        });
    }

    /**
     * @return array<string, Currency>
     */
    protected function ensureCurrencies(): array
    {
        $currencies = [];
        foreach (['PKR', 'AED', 'USD', 'EUR'] as $code) {
            $currency = Currency::where('code', $code)->first();
            if ($currency) {
                $currency->update(['active' => true]);
                $currencies[$code] = $currency;
            }
        }

        return $currencies;
    }

    /**
     * @param  array<string, Currency>  $currencies
     * @return array<string, Company>
     */
    protected function seedCompanies(array $currencies): array
    {
        $pakistan = Country::where('code', 'PK')->first();

        // 1. Truck It In (Pvt) Ltd - Core Freight Tech & Brokerage Platform
        $company1 = Company::find(1);
        if ($company1) {
            $company1->update([
                'name'                    => 'Truck It In (Pvt) Ltd',
                'company_id'              => 'TII-001',
                'registration_number'     => '0145892',
                'tax_id'                  => '4289104-7',
                'is_sales_tax_registered' => true,
                'strn'                    => '3277876123456',
                'email'                   => 'accounts@truckitin.com',
                'phone'                   => '+92-21-35894100',
                'street1'                 => 'Plot 14-C, Bukhari Commercial, DHA Phase 6',
                'city'                    => 'Karachi',
                'country_id'              => $pakistan?->id ?? 177,
                'currency_id'             => $currencies['PKR']->id ?? 159,
                'website'                 => 'https://truckitin.com',
                'is_active'               => true,
            ]);
        } else {
            $company1 = Company::create([
                'name'                    => 'Truck It In (Pvt) Ltd',
                'company_id'              => 'TII-001',
                'registration_number'     => '0145892',
                'tax_id'                  => '4289104-7',
                'is_sales_tax_registered' => true,
                'strn'                    => '3277876123456',
                'email'                   => 'accounts@truckitin.com',
                'phone'                   => '+92-21-35894100',
                'street1'                 => 'Plot 14-C, Bukhari Commercial, DHA Phase 6',
                'city'                    => 'Karachi',
                'country_id'              => $pakistan?->id ?? 177,
                'currency_id'             => $currencies['PKR']->id ?? 159,
                'website'                 => 'https://truckitin.com',
                'is_active'               => true,
            ]);
        }

        // 2. ShipLine - sister company (Pakistan, PKR). Registration, tax and
        // contact details are left blank until the real values are supplied.
        $company2 = Company::firstOrCreate(
            ['name' => 'ShipLine'],
            [
                'company_id'  => 'SHL-001',
                'country_id'  => $pakistan?->id ?? 177,
                'currency_id' => $currencies['PKR']->id ?? 159,
                'is_active'   => true,
            ]
        );

        // 3. OpenPort - sister company (Pakistan, PKR). Details pending as above.
        $company3 = Company::firstOrCreate(
            ['name' => 'OpenPort'],
            [
                'company_id'  => 'OPP-001',
                'country_id'  => $pakistan?->id ?? 177,
                'currency_id' => $currencies['PKR']->id ?? 159,
                'is_active'   => true,
            ]
        );

        // Sync enabled currencies
        $allCompanies = [$company1, $company2, $company3];
        foreach ($allCompanies as $comp) {
            $syncMap = [];
            foreach ($currencies as $code => $cur) {
                $isBase = $cur->id === $comp->currency_id;
                $syncMap[$cur->id] = [
                    'transaction_enabled' => true,
                    'reporting_enabled'   => $isBase,
                ];
            }
            $comp->enabledCurrencies()->syncWithoutDetaching($syncMap);
        }

        return [
            'truckitin' => $company1,
            'shipline'  => $company2,
            'openport'  => $company3,
        ];
    }

    /**
     * @param  array<string, Company>  $companies
     */
    protected function assignUsersToCompanies(array $companies): void
    {
        $companyIds = collect($companies)->pluck('id')->all();

        foreach (User::all() as $user) {
            $user->allowedCompanies()->syncWithoutDetaching($companyIds);
        }
    }

    /**
     * @param  array<string, Company>  $companies
     */
    protected function seedJournals(array $companies): void
    {
        $definitions = [
            [
                'code' => 'INV',
                'name' => 'Customer Invoices',
                'type' => JournalType::SALE,
                'ref'  => CommunicationStandard::AUREUS,
            ],
            [
                'code' => 'BILL',
                'name' => 'Vendor Bills',
                'type' => JournalType::PURCHASE,
                'ref'  => CommunicationStandard::AUREUS,
            ],
            [
                'code' => 'BANK',
                'name' => 'Bank Transactions',
                'type' => JournalType::BANK,
                'ref'  => CommunicationStandard::AUREUS,
            ],
            [
                'code' => 'CASH',
                'name' => 'Cash Transactions',
                'type' => JournalType::CASH,
                'ref'  => CommunicationStandard::AUREUS,
            ],
            [
                'code' => 'MISC',
                'name' => 'Miscellaneous Operations',
                'type' => JournalType::GENERAL,
                'ref'  => CommunicationStandard::AUREUS,
            ],
        ];

        foreach ($companies as $comp) {
            foreach ($definitions as $def) {
                Journal::firstOrCreate(
                    [
                        'company_id' => $comp->id,
                        'code'       => $def['code'],
                    ],
                    [
                        'name'                    => $def['name'],
                        'type'                    => $def['type'],
                        'currency_id'             => $comp->currency_id,
                        'invoice_reference_type'  => CommunicationType::INVOICE,
                        'invoice_reference_model' => $def['ref'],
                        'show_on_dashboard'       => true,
                    ]
                );
            }
        }
    }

    /**
     * @param  array<string, Company>  $companies
     * @return array<int, array{sale: array<int>, purchase: array<int>}>
     */
    protected function seedTaxesAndPartitions(array $companies): array
    {
        $taxesByCompany = [];

        // Common tax accounts in standard Chart of Accounts
        // 22: 251000 - Tax Received (Liability)
        // 10: 131000 - Tax Paid (Asset)
        $taxReceivedAcc = Account::where('code', '251000')->first() ?? Account::find(22);
        $taxPaidAcc = Account::where('code', '131000')->first() ?? Account::find(10);

        foreach ($companies as $key => $comp) {
            $taxesByCompany[$comp->id] = [
                'sale'     => [],
                'purchase' => [],
            ];

            // Ensure TaxGroup exists for company
            $taxGroup = TaxGroup::firstOrCreate(
                ['company_id' => $comp->id, 'name' => 'General Taxes'],
                ['country_id' => $comp->country_id]
            );

            $taxDefs = [];

            if ($key === 'global') {
                // UAE VAT Structure (5%)
                $taxDefs = [
                    [
                        'name'          => '5% Standard UAE VAT',
                        'type_tax_use'  => TypeTaxUse::SALE,
                        'amount'        => 5.0,
                        'invoice_label' => 'UAE VAT 5%',
                        'tax_scope'     => 'service',
                    ],
                    [
                        'name'          => '5% UAE Input VAT',
                        'type_tax_use'  => TypeTaxUse::PURCHASE,
                        'amount'        => 5.0,
                        'invoice_label' => 'Input VAT 5%',
                        'tax_scope'     => 'service',
                    ],
                    [
                        'name'          => '0% Zero-Rated International Freight VAT',
                        'type_tax_use'  => TypeTaxUse::SALE,
                        'amount'        => 0.0,
                        'invoice_label' => 'VAT 0% (Export/Freight)',
                        'tax_scope'     => 'service',
                    ],
                ];
            } else {
                // Pakistan Tax Structure (SRB 15%, PRA 16%, GST 18%, Input 18%, Exempt 0%)
                $taxDefs = [
                    [
                        'name'          => '15% Sales Tax on Services (SRB)',
                        'type_tax_use'  => TypeTaxUse::SALE,
                        'amount'        => 15.0,
                        'invoice_label' => 'SRB 15%',
                        'tax_scope'     => 'service',
                    ],
                    [
                        'name'          => '16% Sales Tax on Services (PRA)',
                        'type_tax_use'  => TypeTaxUse::SALE,
                        'amount'        => 16.0,
                        'invoice_label' => 'PRA 16%',
                        'tax_scope'     => 'service',
                    ],
                    [
                        'name'          => '18% Sales Tax (GST)',
                        'type_tax_use'  => TypeTaxUse::SALE,
                        'amount'        => 18.0,
                        'invoice_label' => 'GST 18%',
                        'tax_scope'     => 'consu',
                    ],
                    [
                        'name'          => '18% Input Tax (Purchases)',
                        'type_tax_use'  => TypeTaxUse::PURCHASE,
                        'amount'        => 18.0,
                        'invoice_label' => 'Input Tax 18%',
                        'tax_scope'     => 'consu',
                    ],
                    [
                        'name'          => '15% Input Tax on Services (SRB/PRA)',
                        'type_tax_use'  => TypeTaxUse::PURCHASE,
                        'amount'        => 15.0,
                        'invoice_label' => 'Input Tax 15%',
                        'tax_scope'     => 'service',
                    ],
                    [
                        'name'          => '0% Exempt / Zero-Rated Freight',
                        'type_tax_use'  => TypeTaxUse::SALE,
                        'amount'        => 0.0,
                        'invoice_label' => 'Exempt 0%',
                        'tax_scope'     => 'service',
                    ],
                ];
            }

            foreach ($taxDefs as $def) {
                // Check if tax with same name or similar exists for this company
                $tax = Tax::where('company_id', $comp->id)
                    ->where('name', $def['name'])
                    ->first();

                if (! $tax) {
                    // Check if an existing tax 1, 2, 3, 4 can be cleaned up / upgraded
                    if ($comp->id === 1 && $def['name'] === '15% Sales Tax on Services (SRB)') {
                        $tax = Tax::find(1);
                    } elseif ($comp->id === 1 && $def['name'] === '15% Input Tax on Services (SRB/PRA)') {
                        $tax = Tax::find(2);
                    } elseif ($comp->id === 1 && $def['name'] === '18% Sales Tax (GST)') {
                        $tax = Tax::find(3);
                    } elseif ($comp->id === 1 && $def['name'] === '18% Input Tax (Purchases)') {
                        $tax = Tax::find(4);
                    }
                }

                if ($tax) {
                    $tax->update([
                        'name'          => $def['name'],
                        'type_tax_use'  => $def['type_tax_use'],
                        'amount'        => $def['amount'],
                        'amount_type'   => AmountType::PERCENT,
                        'tax_scope'     => $def['tax_scope'],
                        'invoice_label' => $def['invoice_label'],
                        'is_active'     => true,
                        'tax_group_id'  => $taxGroup->id,
                        'country_id'    => $comp->country_id,
                    ]);
                } else {
                    $tax = Tax::create([
                        'company_id'          => $comp->id,
                        'tax_group_id'        => $taxGroup->id,
                        'country_id'          => $comp->country_id,
                        'type_tax_use'        => $def['type_tax_use'],
                        'tax_scope'           => $def['tax_scope'],
                        'amount_type'         => AmountType::PERCENT,
                        'amount'              => $def['amount'],
                        'name'                => $def['name'],
                        'invoice_label'       => $def['invoice_label'],
                        'is_active'           => true,
                        'include_base_amount' => false,
                        'sort'                => 1,
                    ]);
                }

                // Ensure TaxPartitions are present
                $taxAccId = $def['type_tax_use'] === TypeTaxUse::SALE ? $taxReceivedAcc?->id : $taxPaidAcc?->id;
                $this->ensureTaxPartitions($tax, $taxAccId);

                if ($def['type_tax_use'] === TypeTaxUse::SALE) {
                    $taxesByCompany[$comp->id]['sale'][] = $tax->id;
                } else {
                    $taxesByCompany[$comp->id]['purchase'][] = $tax->id;
                }
            }
        }

        return $taxesByCompany;
    }

    protected function ensureTaxPartitions(Tax $tax, ?int $taxAccountId): void
    {
        $existing = TaxPartition::where('tax_id', $tax->id)->count();
        if ($existing >= 4) {
            return;
        }

        // Invoice - Base
        TaxPartition::firstOrCreate(
            ['tax_id' => $tax->id, 'document_type' => DocumentType::INVOICE, 'repartition_type' => RepartitionType::BASE],
            ['factor_percent' => null, 'company_id' => $tax->company_id, 'account_id' => null, 'sort' => 1]
        );

        // Invoice - Tax
        TaxPartition::firstOrCreate(
            ['tax_id' => $tax->id, 'document_type' => DocumentType::INVOICE, 'repartition_type' => RepartitionType::TAX],
            ['factor_percent' => 100.0, 'company_id' => $tax->company_id, 'account_id' => $taxAccountId, 'use_in_tax_closing' => true, 'sort' => 2]
        );

        // Refund - Base
        TaxPartition::firstOrCreate(
            ['tax_id' => $tax->id, 'document_type' => DocumentType::REFUND, 'repartition_type' => RepartitionType::BASE],
            ['factor_percent' => null, 'company_id' => $tax->company_id, 'account_id' => null, 'sort' => 1]
        );

        // Refund - Tax
        TaxPartition::firstOrCreate(
            ['tax_id' => $tax->id, 'document_type' => DocumentType::REFUND, 'repartition_type' => RepartitionType::TAX],
            ['factor_percent' => 100.0, 'company_id' => $tax->company_id, 'account_id' => $taxAccountId, 'use_in_tax_closing' => true, 'sort' => 2]
        );
    }

    /**
     * @param  array<int, array{sale: array<int>, purchase: array<int>}>  $taxesByCompany
     */
    protected function seedCategoriesAndProducts(array $taxesByCompany): void
    {
        // Product Categories
        $catFreight = Category::firstOrCreate(['name' => 'Logistics & Freight Services']);
        $catColdChain = Category::firstOrCreate(['name' => 'Cold Chain & Warehousing']);
        $catFuel = Category::firstOrCreate(['name' => 'Fleet Operations & Fuel']);
        $catMaintenance = Category::firstOrCreate(['name' => 'Fleet Maintenance & Spares']);
        $catTelematics = Category::firstOrCreate(['name' => 'Telematics & IoT Hardware']);
        $catPort = Category::firstOrCreate(['name' => 'Port & Customs Drayage']);

        // Key accounts in Chart of Accounts:
        // 27: 400000 - Product Sales (Income)
        // 32: 500000 - Cost of Goods Sold (Direct Cost)
        // 34: 611000 - Purchase of Equipments (Expense)
        // 442: 613000 - Fuel Expense (Expense)
        // 443: 614000 - Vehicle Maintenance Expense (Expense)
        // 444: 615000 - Toll Charges Expense (Expense)
        $incSales = Account::where('code', '400000')->first() ?? Account::find(27);
        $expCogs = Account::where('code', '500000')->first() ?? Account::find(32);
        $expEquip = Account::where('code', '611000')->first() ?? Account::find(34);
        $expFuel = Account::where('code', '613000')->first() ?? Account::find(442);
        $expMaint = Account::where('code', '614000')->first() ?? Account::find(443);
        $expToll = Account::where('code', '615000')->first() ?? Account::find(444);

        $uomUnits = UOM::find(1);
        $uomLiters = UOM::find(20) ?? $uomUnits;

        // Collect all sale taxes across companies
        $allSaleTaxIds = [];
        $allPurchaseTaxIds = [];
        foreach ($taxesByCompany as $t) {
            $allSaleTaxIds = array_merge($allSaleTaxIds, $t['sale']);
            $allPurchaseTaxIds = array_merge($allPurchaseTaxIds, $t['purchase']);
        }
        $allSaleTaxIds = array_values(array_unique($allSaleTaxIds));
        $allPurchaseTaxIds = array_values(array_unique($allPurchaseTaxIds));

        $products = [
            // Revenue / Service Products (Customer Invoices)
            [
                'name'         => 'Full Truckload (FTL) Freight - Karachi to Lahore (40ft Flatbed)',
                'type'         => ProductType::SERVICE,
                'category_id'  => $catFreight->id,
                'price'        => 195000.0,
                'cost'         => 145000.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expCogs?->id,
                'sales_ok'     => true,
                'purchase_ok'  => false,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => $allSaleTaxIds,
                'purch_taxes'  => [],
            ],
            [
                'name'         => 'Less than Truckload (LTL) Consolidated Freight',
                'type'         => ProductType::SERVICE,
                'category_id'  => $catFreight->id,
                'price'        => 12500.0,
                'cost'         => 8000.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expCogs?->id,
                'sales_ok'     => true,
                'purchase_ok'  => false,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => $allSaleTaxIds,
                'purch_taxes'  => [],
            ],
            [
                'name'         => 'Temperature-Controlled Reefer Transport (Perishables / Pharma)',
                'type'         => ProductType::SERVICE,
                'category_id'  => $catColdChain->id,
                'price'        => 285000.0,
                'cost'         => 210000.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expCogs?->id,
                'sales_ok'     => true,
                'purchase_ok'  => false,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => $allSaleTaxIds,
                'purch_taxes'  => [],
            ],
            [
                'name'         => 'Port Drayage & Customs Clearance (Karachi Port Trust / QICT)',
                'type'         => ProductType::SERVICE,
                'category_id'  => $catPort->id,
                'price'        => 48000.0,
                'cost'         => 28000.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expCogs?->id,
                'sales_ok'     => true,
                'purchase_ok'  => true,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => $allSaleTaxIds,
                'purch_taxes'  => $allPurchaseTaxIds,
            ],
            [
                'name'         => 'Palletized Warehouse Storage & Handling (Monthly)',
                'type'         => ProductType::SERVICE,
                'category_id'  => $catColdChain->id,
                'price'        => 3500.0,
                'cost'         => 1200.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expCogs?->id,
                'sales_ok'     => true,
                'purchase_ok'  => false,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => $allSaleTaxIds,
                'purch_taxes'  => [],
            ],
            [
                'name'         => 'Fleet Telematics & GPS Tracking Subscription (Monthly)',
                'type'         => ProductType::SERVICE,
                'category_id'  => $catTelematics->id,
                'price'        => 2200.0,
                'cost'         => 850.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expCogs?->id,
                'sales_ok'     => true,
                'purchase_ok'  => false,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => $allSaleTaxIds,
                'purch_taxes'  => [],
            ],

            // Procurement & Direct Fleet Goods / Operational Expenses (Vendor Bills)
            [
                'name'         => 'High-Speed Diesel (Bulk Fuel - Liters)',
                'type'         => ProductType::GOODS,
                'category_id'  => $catFuel->id,
                'price'        => 0.0,
                'cost'         => 285.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expFuel?->id,
                'sales_ok'     => false,
                'purchase_ok'  => true,
                'uom_id'       => $uomLiters->id,
                'sale_taxes'   => [],
                'purch_taxes'  => $allPurchaseTaxIds,
            ],
            [
                'name'         => 'Heavy Duty Commercial Truck Tires (315/80R22.5 Tubeless)',
                'type'         => ProductType::GOODS,
                'category_id'  => $catMaintenance->id,
                'price'        => 0.0,
                'cost'         => 95000.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expMaint?->id,
                'sales_ok'     => false,
                'purchase_ok'  => true,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => [],
                'purch_taxes'  => $allPurchaseTaxIds,
            ],
            [
                'name'         => 'Heavy-Duty Engine Oil & Lubricants (Fleet Drum 208L - 15W-40)',
                'type'         => ProductType::GOODS,
                'category_id'  => $catMaintenance->id,
                'price'        => 0.0,
                'cost'         => 148000.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expMaint?->id,
                'sales_ok'     => false,
                'purchase_ok'  => true,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => [],
                'purch_taxes'  => $allPurchaseTaxIds,
            ],
            [
                'name'         => 'Motorway Toll & E-Tag Fleet Top-Up Voucher',
                'type'         => ProductType::SERVICE,
                'category_id'  => $catFreight->id,
                'price'        => 0.0,
                'cost'         => 25000.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expToll?->id,
                'sales_ok'     => false,
                'purchase_ok'  => true,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => [],
                'purch_taxes'  => [],
            ],
            [
                'name'         => 'Standard Euro Wooden Pallets (1200 x 800 mm Grade A)',
                'type'         => ProductType::GOODS,
                'category_id'  => $catColdChain->id,
                'price'        => 2800.0,
                'cost'         => 1950.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expCogs?->id,
                'sales_ok'     => true,
                'purchase_ok'  => true,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => $allSaleTaxIds,
                'purch_taxes'  => $allPurchaseTaxIds,
            ],
            [
                'name'         => 'Vehicle GPS Tracker Hardware (4G OBD-II / Hardwired Unit)',
                'type'         => ProductType::GOODS,
                'category_id'  => $catTelematics->id,
                'price'        => 14500.0,
                'cost'         => 8500.0,
                'income_acc'   => $incSales?->id,
                'expense_acc'  => $expEquip?->id,
                'sales_ok'     => true,
                'purchase_ok'  => true,
                'uom_id'       => $uomUnits->id,
                'sale_taxes'   => $allSaleTaxIds,
                'purch_taxes'  => $allPurchaseTaxIds,
            ],
        ];

        foreach ($products as $pData) {
            $product = AccountProduct::where('name', $pData['name'])->first();

            if (! $product) {
                $product = AccountProduct::create([
                    'name'                        => $pData['name'],
                    'type'                        => $pData['type'],
                    'category_id'                 => $pData['category_id'],
                    'price'                       => $pData['price'],
                    'cost'                        => $pData['cost'],
                    'property_account_income_id'  => $pData['income_acc'],
                    'property_account_expense_id' => $pData['expense_acc'],
                    'sales_ok'                    => $pData['sales_ok'],
                    'purchase_ok'                 => $pData['purchase_ok'],
                    'uom_id'                      => $pData['uom_id'],
                    'uom_po_id'                   => $pData['uom_id'],
                    'enable_sales'                => $pData['sales_ok'],
                    'enable_purchase'             => $pData['purchase_ok'],
                ]);
            } else {
                $product->update([
                    'type'                        => $pData['type'],
                    'category_id'                 => $pData['category_id'],
                    'price'                       => $pData['price'],
                    'cost'                        => $pData['cost'],
                    'property_account_income_id'  => $pData['income_acc'],
                    'property_account_expense_id' => $pData['expense_acc'],
                    'sales_ok'                    => $pData['sales_ok'],
                    'purchase_ok'                 => $pData['purchase_ok'],
                    'uom_id'                      => $pData['uom_id'],
                    'uom_po_id'                   => $pData['uom_id'],
                    'enable_sales'                => $pData['sales_ok'],
                    'enable_purchase'             => $pData['purchase_ok'],
                ]);
            }

            // Sync product customer taxes and supplier taxes
            $product->productTaxes()->sync($pData['sale_taxes']);
            $product->supplierTaxes()->sync($pData['purch_taxes']);
        }
    }
}
