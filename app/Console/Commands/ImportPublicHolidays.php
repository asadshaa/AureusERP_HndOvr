<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Services\PublicHolidayImportService;

/**
 * Adds a year's public holidays to the named companies: the bundled
 * Pakistan federal list (database/data/pk-public-holidays-{year}.php) or
 * HR's own CSV (--file). Idempotent: dates a company already has as a
 * holiday are skipped; nothing is changed or deleted.
 */
class ImportPublicHolidays extends Command
{
    protected $signature = 'hr:import-public-holidays
                            {year : The year of the holidays, e.g. 2026}
                            {--company=* : Company id or name (repeat for several); required}
                            {--file= : A CSV (name,date_from,date_to,tentative) or PHP list instead of the bundled Pakistan list}
                            {--dry-run : Show what would be added without saving}';

    protected $description = 'Import public holidays (the bundled Pakistan federal list, or a CSV) into the companies\' Public Holidays; skips dates that already are holidays, never deletes';

    public function handle(PublicHolidayImportService $service): int
    {
        $year = (int) $this->argument('year');
        if ((string) $year !== trim((string) $this->argument('year')) || $year < 2000 || $year > 2100) {
            $this->error('The year must look like 2026.');

            return self::INVALID;
        }

        $companies = $this->companies((array) $this->option('company'));
        if ($companies === null) {
            return self::INVALID;
        }

        $file = $this->option('file') ?: $service->bundledFile($year);
        if ($file === null) {
            $this->error("There is no bundled Pakistan holiday list for {$year} (available: ".(implode(', ', $service->bundledYears()) ?: 'none').'). Pass --file=holidays.csv.');

            return self::INVALID;
        }

        try {
            $holidays = $service->load((string) $file, $year);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[dry run] ' : '').count($holidays)." holiday(s) for {$year} from {$file}");

        foreach ($companies as $company) {
            $result = $service->import((int) $company->id, $holidays, null, $dryRun);

            $this->newLine();
            $this->info("{$result['company']} (#{$company->id}): ".count($result['created']).' added'.($dryRun ? ' (not saved)' : '').', '.count($result['skipped']).' skipped');
            $this->table(
                ['Holiday', 'From', 'To', 'Result'],
                [
                    ...array_map(fn (array $row): array => [$row['name'], $row['date_from'], $row['date_to'], $dryRun ? 'would be added' : 'added'], $result['created']),
                    ...array_map(fn (array $row): array => [$row['name'], $row['date_from'], $row['date_to'], 'skipped: '.$row['reason']], $result['skipped']),
                ],
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $requested
     * @return array<int, Company>|null
     */
    private function companies(array $requested): ?array
    {
        if ($requested === []) {
            $this->error('Name the companies with --company=<id or name> (repeat for several).');

            return null;
        }

        $companies = [];
        foreach ($requested as $value) {
            $value = trim((string) $value);
            $company = ctype_digit($value)
                ? Company::query()->find((int) $value)
                : Company::query()->whereRaw('LOWER(name) = ?', [Str::lower($value)])->first();

            if (! $company) {
                $this->error("Company \"{$value}\" was not found.");

                return null;
            }

            $companies[$company->id] = $company;
        }

        return array_values($companies);
    }
}
