<?php

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoCatalog;
use Database\Seeders\DemoBusinessSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;

/**
 * Business demo data (DemoBusinessSeeder): customers, vendors, products and
 * services, customer invoices, vendor bills and payments, for the demo
 * companies (ShipLine, OpenPort: posted, part paid) and for a live company
 * (Truck It In: drafts only, nothing reaches its ledger). Only installed
 * plugins are seeded. Idempotent; every row is recorded in demo_records, so
 * demo:purge --company=<the company> removes exactly those rows. Nothing is
 * e-mailed, queued or sent anywhere.
 */
class DemoBusiness extends Command
{
    protected $signature = 'demo:business
        {company* : ShipLine, OpenPort and/or a live company such as "Truck It In" (id, name or the start of its name)}';

    protected $description = 'Seed realistic, removable demo customers, vendors, products, invoices, bills and payments';

    public function handle(): int
    {
        // Nothing leaves the machine: mail is logged (then captured by the sandbox), queued work runs in-process.
        config(['mail.default' => 'log', 'queue.default' => 'sync', 'accounting_drive.enabled' => false]);

        $companies = [];
        foreach ((array) $this->argument('company') as $value) {
            $company = $this->resolveCompany((string) $value);

            if (is_string($company)) {
                $this->error($company);

                return self::FAILURE;
            }

            $companies[$company->id] = $company;
        }

        if (($missing = DemoBusinessSeeder::missingPlugins()) !== []) {
            $this->error('Nothing seeded: plugin(s) not installed: '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $seeder = app(DemoBusinessSeeder::class);
        $seeder->setCommand($this);
        $started = microtime(true);

        foreach ($companies as $company) {
            $result = $seeder->seedCompany($company);

            $this->newLine();
            $this->info($company->name.($result['live'] ? ' (live company: invoices and bills are drafts, nothing is posted)' : ''));
            $this->line('  Created this run: '.($result['created'] === [] ? 'nothing' : collect($result['created'])->map(fn (int $count, string $table): string => "{$table} {$count}")->implode(', ')));
            $this->table(['Demo documents (all runs)', 'Count'], collect($result['documents'])->map(fn (int $count, string $key): array => [Str::of($key)->replace(['out_invoice', 'in_invoice'], ['Customer invoice', 'Vendor bill'])->toString(), (string) $count])->values()->all());
        }

        $notInstalled = array_filter(DemoBusinessSeeder::OTHER_PLUGINS, fn (string $name): bool => ! Package::isPluginInstalled($name));
        $installed = array_diff(DemoBusinessSeeder::OTHER_PLUGINS, $notInstalled);

        if ($notInstalled !== []) {
            $this->line('Not seeded, plugin not installed: '.implode(', ', $notInstalled).'.');
        }

        if ($installed !== []) {
            $this->warn('Installed but not covered by demo:business: '.implode(', ', $installed).'.');
        }

        if ($seeder->warnings !== []) {
            $this->warn(count($seeder->warnings).' item(s) skipped (see above).');
        }

        $this->info(sprintf('Done in %.1f s. Remove it with: php artisan demo:purge --company="<company>"', microtime(true) - $started));

        return self::SUCCESS;
    }

    /** A demo company (ShipLine, OpenPort) or a live one, or why there is none. */
    private function resolveCompany(string $value): Company|string
    {
        $live = DemoAttendance::liveCompany($value);

        if ($live instanceof Company) {
            return $live;
        }

        $value = trim($value);
        $company = ctype_digit($value)
            ? Company::query()->find((int) $value)
            : Company::query()->whereRaw('LOWER(name) = ?', [Str::lower($value)])->first();

        return $company && array_key_exists($company->name, DemoCatalog::COMPANIES) ? $company : $live;
    }
}
