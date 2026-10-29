<?php

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoCatalog;
use Database\Seeders\DemoActivitySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Webkul\Support\Models\Company;

/**
 * Fills the demo companies (ShipLine, OpenPort) with lived-in HR data:
 * staff, attendance, leave, employee requests, documents and recruitment.
 * Idempotent; every row it creates is recorded in demo_records so
 * demo:purge can remove exactly that. Truck It In (a live company) is
 * never touched. Nothing is emailed and real users get no notifications.
 */
class DemoSeed extends Command
{
    protected $signature = 'demo:seed
        {--company=* : ShipLine and/or OpenPort (name or id); default both}
        {--fresh-activity : Remove the demo activity created earlier (attendance, leave, requests, documents, recruitment) and create it again; staff are kept}';

    protected $description = 'Seed realistic, idempotent HR demo data for the ShipLine and OpenPort demo companies';

    public function handle(): int
    {
        $companies = self::resolveCompanies((array) $this->option('company'), $this);

        if ($companies === null) {
            return self::FAILURE;
        }

        $seeder = app(DemoActivitySeeder::class);
        $seeder->setCommand($this);
        $seeder->companyNames = $companies;
        $seeder->freshActivity = (bool) $this->option('fresh-activity');

        $started = microtime(true);
        $seeder->run();

        foreach ($seeder->summary as $company => $summary) {
            $this->newLine();
            $this->info($company);
            $created = $summary['created'];
            $this->line('  Created this run: '.($created === [] ? 'nothing' : collect($created)->map(fn (int $count, string $table): string => "{$table} {$count}")->implode(', ')));
            $this->table(['Total in company', 'Count'], collect($summary['totals'])->map(fn ($value, string $key): array => [Str::headline(Str::after($key, 'total_')), (string) $value])->values()->all());
        }

        if ($seeder->warnings !== []) {
            $this->warn(count($seeder->warnings).' item(s) skipped (see above).');
        }

        $this->info(sprintf('Done in %.1f s. Demo logins: storage/app/private/demo-accounts.txt', microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * The demo companies named in --company (all of them when empty), or
     * null after an error message for anything else.
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>|null
     */
    public static function resolveCompanies(array $requested, Command $command): ?array
    {
        $demo = array_keys(DemoCatalog::COMPANIES);

        if ($requested === []) {
            return $demo;
        }

        $names = [];
        foreach ($requested as $value) {
            $company = ctype_digit((string) $value)
                ? Company::query()->find((int) $value)
                : Company::query()->whereRaw('LOWER(name) = ?', [Str::lower(trim((string) $value))])->first();
            $name = collect($demo)->first(fn (string $demoName): bool => $company && $company->name === $demoName);

            if (! $name) {
                $command->error("\"{$value}\" is not a demo company. Demo data is only for: ".implode(', ', $demo).' (Truck It In is a live company and is never seeded).');

                return null;
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }
}
