<?php

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoCatalog;
use Database\Seeders\DemoActivitySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Support\Models\Company;

/**
 * Demo attendance for a live company (e.g. Truck It In): fills only the
 * days its employees have no attendance record for, from --from to --to
 * (Karachi; at the latest yesterday), with the demo companies' patterns
 * (DemoActivitySeeder::fillLiveAttendance()). Existing rows are never
 * changed. Idempotent; every row is recorded in demo_records, so
 * demo:purge --company=<the company> removes exactly those rows. Nothing is
 * emailed and nobody is notified.
 */
class DemoAttendance extends Command
{
    protected $signature = 'demo:attendance
        {company : The live company (id, name or the start of its name, e.g. "Truck It In")}
        {--from= : First day, Y-m-d (Asia/Karachi)}
        {--to= : Last day, Y-m-d; default and at the latest yesterday (today is live)}';

    protected $description = 'Fill the missing attendance days of a live company with tracked, removable demo attendance';

    public function handle(): int
    {
        $company = self::liveCompany((string) $this->argument('company'));

        if (is_string($company)) {
            $this->error($company);

            return self::FAILURE;
        }

        $yesterday = Carbon::now('Asia/Karachi')->subDay()->toDateString();

        try {
            $from = Carbon::createFromFormat('!Y-m-d', (string) $this->option('from'))->toDateString();
            $to = $this->option('to') ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('to'))->toDateString() : $yesterday;
        } catch (Throwable) {
            $this->error('Give --from (and optionally --to) as Y-m-d, e.g. --from=2026-08-17.');

            return self::FAILURE;
        }

        if ($to > $yesterday) {
            $this->warn("Days from today on are left alone (real check-ins happen today): filling up to {$yesterday}.");
        }

        $seeder = app(DemoActivitySeeder::class);
        $seeder->setCommand($this);
        $stats = $seeder->fillLiveAttendance($company, $from, $to);

        $this->info("{$company->name}: demo attendance {$stats['from']} to {$stats['to']}");
        $this->table(['', 'Count'], [
            ['Employees covered (active, not placeholders)', $stats['employees']],
            ['Employees given demo days this run', $stats['employees_filled']],
            ['Days created', $stats['days']],
            ['Intervals created', $stats['intervals']],
            ['Days skipped: a record already existed', $stats['existing']],
            ['Work days skipped: month finalised ('.(implode(', ', $stats['locked_months']) ?: 'none').')', $stats['locked']],
            ['Work days skipped: public holiday', $stats['holidays']],
            ['Work days skipped: approved full-day leave', $stats['leave']],
            ['Work days left absent (demo pattern)', $stats['absent']],
        ]);

        if ($stats['placeholders'] !== []) {
            $this->line('Placeholders skipped: '.implode(', ', $stats['placeholders']));
        }

        $this->line("Remove it with: php artisan demo:purge --company=\"{$company->name}\"");

        return self::SUCCESS;
    }

    /**
     * The live (non-demo) company named by $value -- its id, name or the
     * unambiguous start of its name -- or why there is none.
     */
    public static function liveCompany(string $value): Company|string
    {
        $value = trim($value);
        $company = ctype_digit($value)
            ? Company::query()->find((int) $value)
            : Company::query()->whereRaw('LOWER(name) = ?', [Str::lower($value)])->first();

        if (! $company && $value !== '' && ! ctype_digit($value)) {
            $matches = Company::query()->whereRaw('LOWER(name) LIKE ?', [str_replace(['%', '_'], ['\%', '\_'], Str::lower($value)).'%'])->get();

            if ($matches->count() > 1) {
                return "\"{$value}\" matches several companies: ".$matches->pluck('name')->implode(', ').'. Use the full name or the id.';
            }

            $company = $matches->first();
        }

        if (! $company) {
            return "Company \"{$value}\" not found.";
        }

        if (array_key_exists($company->name, DemoCatalog::COMPANIES)) {
            return "{$company->name} is a demo company: use demo:seed / demo:purge for it.";
        }

        return $company;
    }
}
