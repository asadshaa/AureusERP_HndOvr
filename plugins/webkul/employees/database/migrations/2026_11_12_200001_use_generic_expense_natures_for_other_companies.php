<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ShipLine and OpenPort were given exact copies of Truck It In's "nature of
 * expense" lists, which name Truck It In's own vendors. For every company
 * other than company 1, a claim category whose list still equals the original
 * Truck It In default is switched to a generic starter list. A list that HR
 * changed in any way is left alone, and company 1 is never touched.
 *
 * Self-contained (the lists are copied here on purpose, so later seeder edits
 * cannot change what this migration recognises). Idempotent; counts are
 * written to the log.
 *
 * down() is a no-op: the generic lists are not turned back into another
 * company's vendor names.
 */
return new class extends Migration
{
    private const TRUCK_IT_IN_COMPANY_ID = 1;

    /** @var array<string, array{original: string, generic: string}> */
    private const LISTS = [
        'claim_people' => [
            'original' => 'Payroll, G-Suite, Shell, EOBI, Earned Wage Access, Health Insurance, Munshiana, Income Tax, Zong, BYOD, Medical Bills, Employee Engagement, Team Event',
            'generic'  => 'Payroll, Insurance, Medical bills, Employee engagement, Training, Other',
        ],
        'claim_real_estate' => [
            'original' => 'Office Rent, Apartment Rent, Utilities, Groceries, Other, Maintenance Charges, Leopard Courier',
            'generic'  => 'Office rent, Utilities, Maintenance, Other',
        ],
        'claim_digital_marketing' => [
            'original' => 'SEO Services, Facebook, Tilism Technologies',
            'generic'  => 'Advertising, SEO, Other',
        ],
        'claim_financial_provisions' => [
            'original' => 'Bank charges, Service Charges, Aspire',
            'generic'  => 'Bank charges, Service charges, Other',
        ],
        'claim_tech' => [
            'original' => 'Tech tools, Laptop repairs',
            'generic'  => 'Software and tools, Equipment repairs, Other',
        ],
        'claim_professional_services' => [
            'original' => 'Tax Consultancy, Stamp Paper, Legal Costs, Legal Consultancy, Audit Agency, First Base, VAPT, Corporate Docs, PSEB',
            'generic'  => 'Legal, Audit, Consultancy, Other',
        ],
        'claim_travel_entertainment' => [
            'original' => 'Travel, Accommodation, Daily Allowance, Fuel, Food, Sports Activity, Farewell',
            'generic'  => 'Travel, Accommodation, Daily Allowance, Fuel, Food, Other',
        ],
        'claim_returns_waivers' => [
            'original' => 'Detention',
            'generic'  => 'Detention, Returns, Waivers',
        ],
        'claim_others' => [
            'original' => 'Miscellaneous, Other',
            'generic'  => 'Miscellaneous, Other',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('employees_request_types')) {
            return;
        }

        $changed = 0;
        $kept = 0;

        $types = DB::table('employees_request_types')
            ->where('company_id', '!=', self::TRUCK_IT_IN_COMPANY_ID)
            ->whereIn('code', array_keys(self::LISTS))
            ->get();

        foreach ($types as $type) {
            $configuration = json_decode((string) $type->configuration, true);
            $current = is_array($configuration) ? ($configuration['expense_natures'] ?? null) : null;
            $lists = self::LISTS[$type->code];

            if (! is_string($current) || trim($current) !== $lists['original'] || $lists['original'] === $lists['generic']) {
                $kept++;

                continue;
            }

            $configuration['expense_natures'] = $lists['generic'];

            DB::table('employees_request_types')->where('id', $type->id)->update([
                'configuration' => json_encode($configuration),
                'updated_at'    => now(),
            ]);
            $changed++;
        }

        Log::info("Generic expense natures: {$changed} claim categories switched, {$kept} left as they were.");
    }

    public function down(): void
    {
        // Intentionally empty: see the class comment.
    }
};
