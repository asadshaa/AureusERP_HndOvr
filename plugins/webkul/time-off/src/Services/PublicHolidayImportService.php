<?php

namespace Webkul\TimeOff\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SplFileObject;
use Throwable;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\CalendarLeave;

/**
 * Adds a list of public holidays to one company's Public Holidays
 * (calendar_leaves, time_type "leave", every work calendar): the bundled
 * Pakistan federal list of a year (database/data/pk-public-holidays-{year}.php)
 * or a CSV HR prepares. Used by hr:import-public-holidays and the "Import
 * Pakistan holidays" action on the Public Holidays list.
 *
 * Idempotent and additive: a date the company already has as a holiday
 * (its own or a shared one with no company) is skipped, the rest of a
 * holiday's days are still added, and nothing is ever changed or deleted.
 * A whole list is read and checked before anything is written.
 *
 * The attendance month lock does not apply: a finalised month reads its
 * snapshot, so a holiday added later never changes it (re-open and finalise
 * again to take the holiday in).
 */
class PublicHolidayImportService
{
    public const TENTATIVE_SUFFIX = ' (tentative)';

    private const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y'];

    /** The bundled Pakistan list for the year, or null when there is none. */
    public function bundledFile(int $year): ?string
    {
        $path = database_path("data/pk-public-holidays-{$year}.php");

        return is_file($path) ? $path : null;
    }

    /** @return array<int, int> the years with a bundled Pakistan list, oldest first */
    public function bundledYears(): array
    {
        $years = [];

        foreach (glob(database_path('data/pk-public-holidays-*.php')) ?: [] as $path) {
            if (preg_match('/pk-public-holidays-(\d{4})\.php$/', $path, $match)) {
                $years[] = (int) $match[1];
            }
        }

        sort($years);

        return $years;
    }

    /**
     * Reads and checks a holiday list: a PHP data file like the bundled ones
     * (returns ['holidays' => [...]]) or a CSV with a header row: name,
     * date_from (or date), date_to (optional, default date_from), tentative
     * (optional: yes / no). Dates are Y-m-d or d/m/Y and must lie in $year.
     *
     * @return array<int, array{name: string, date_from: string, date_to: string, tentative: bool}> sorted by date
     *
     * @throws InvalidArgumentException naming every bad line; nothing is returned then
     */
    public function load(string $path, int $year): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("The holiday file {$path} cannot be read.");
        }

        $rows = Str::lower(pathinfo($path, PATHINFO_EXTENSION)) === 'php'
            ? $this->phpRows($path)
            : $this->csvRows($path);

        $holidays = [];
        $errors = [];

        foreach ($rows as $line => $row) {
            try {
                $holidays[] = $this->normalise($row, $year);
            } catch (InvalidArgumentException $e) {
                $errors[] = "{$line}: {$e->getMessage()}";
            }
        }

        if ($errors !== []) {
            throw new InvalidArgumentException('The holiday list was not imported: '.implode('; ', array_slice($errors, 0, 20)));
        }

        if ($holidays === []) {
            throw new InvalidArgumentException('The holiday list is empty.');
        }

        usort($holidays, fn (array $a, array $b): int => [$a['date_from'], $a['date_to']] <=> [$b['date_from'], $b['date_to']]);

        return $holidays;
    }

    /**
     * Adds the holidays to the company. Each holiday's days that the company
     * does not have as a holiday yet are written (one row per run of
     * consecutive free days, every calendar); days already covered are
     * reported as skipped, including those an earlier holiday of the same
     * list just covered.
     *
     * @param  array<int, array{name: string, date_from: string, date_to: string, tentative: bool}>  $holidays  from load()
     * @return array{company: string, created: array<int, array{name: string, date_from: string, date_to: string}>, skipped: array<int, array{name: string, date_from: string, date_to: string, reason: string}>}
     */
    public function import(int $companyId, array $holidays, ?User $actor = null, bool $dryRun = false): array
    {
        $company = Company::query()->find($companyId);

        if (! $company) {
            throw new InvalidArgumentException("Company {$companyId} was not found. Check the company number and try again.");
        }

        $work = function () use ($company, $holidays, $actor, $dryRun): array {
            // Serialises imports into the same company, so two runs never add a date twice.
            Company::query()->whereKey($company->id)->lockForUpdate()->value('id');

            $covered = $this->coveredDates($company->id, $holidays);
            $created = [];
            $skipped = [];

            foreach ($holidays as $holiday) {
                $name = $holiday['tentative'] ? Str::limit($holiday['name'], 255 - strlen(self::TENTATIVE_SUFFIX), '').self::TENTATIVE_SUFFIX : $holiday['name'];
                $run = [];

                foreach ($this->dates($holiday['date_from'], $holiday['date_to']) as $date) {
                    if (isset($covered[$date])) {
                        $skipped[] = ['name' => $name, 'date_from' => $date, 'date_to' => $date, 'reason' => 'already a holiday: '.$covered[$date]];
                        $created = $this->closeRun($run, $name, $company->id, $actor, $dryRun, $created);
                        $run = [];

                        continue;
                    }

                    $covered[$date] = $name;
                    $run[] = $date;
                }

                $created = $this->closeRun($run, $name, $company->id, $actor, $dryRun, $created);
            }

            return ['company' => (string) $company->name, 'created' => $created, 'skipped' => $this->mergeSkipped($skipped)];
        };

        return $dryRun ? $work() : DB::transaction($work);
    }

    /**
     * @param  array<int, string>  $run  consecutive free dates of one holiday
     * @param  array<int, array{name: string, date_from: string, date_to: string}>  $created
     * @return array<int, array{name: string, date_from: string, date_to: string}>
     */
    private function closeRun(array $run, string $name, int $companyId, ?User $actor, bool $dryRun, array $created): array
    {
        if ($run === []) {
            return $created;
        }

        $row = ['name' => $name, 'date_from' => $run[0], 'date_to' => end($run)];

        if (! $dryRun) {
            CalendarLeave::query()->create($row + [
                'time_type'   => 'leave',
                'company_id'  => $companyId,
                'calendar_id' => null,
                'creator_id'  => $actor?->id,
            ]);
        }

        $created[] = $row;

        return $created;
    }

    /**
     * Dates the company already has as a public holiday (as the attendance
     * reports read them: its own or shared rows, any calendar, not a
     * resource's own time off), within the list's span.
     *
     * @param  array<int, array{date_from: string, date_to: string}>  $holidays
     * @return array<string, string> date => holiday name
     */
    private function coveredDates(int $companyId, array $holidays): array
    {
        if ($holidays === []) {
            return [];
        }

        $from = min(array_column($holidays, 'date_from'));
        $to = max(array_column($holidays, 'date_to'));
        $covered = [];

        CalendarLeave::query()
            ->where(fn (Builder $query) => $query->where('time_type', 'leave')->orWhereNull('time_type'))
            ->where(fn (Builder $query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->whereNull('resource_id')
            ->whereDate('date_from', '<=', $to)
            ->whereDate('date_to', '>=', $from)
            ->orderBy('date_from')
            ->get(['name', 'date_from', 'date_to'])
            ->each(function (CalendarLeave $existing) use (&$covered): void {
                foreach ($this->dates(Carbon::parse($existing->date_from)->toDateString(), Carbon::parse($existing->date_to)->toDateString()) as $date) {
                    $covered[$date] ??= (string) $existing->name;
                }
            });

        return $covered;
    }

    /**
     * Consecutive skipped days of one holiday with the same reason become one line.
     *
     * @param  array<int, array{name: string, date_from: string, date_to: string, reason: string}>  $skipped
     * @return array<int, array{name: string, date_from: string, date_to: string, reason: string}>
     */
    private function mergeSkipped(array $skipped): array
    {
        $merged = [];

        foreach ($skipped as $item) {
            $last = array_key_last($merged);

            if ($last !== null
                && $merged[$last]['name'] === $item['name']
                && $merged[$last]['reason'] === $item['reason']
                && Carbon::parse($merged[$last]['date_to'])->addDay()->toDateString() === $item['date_from']) {
                $merged[$last]['date_to'] = $item['date_to'];

                continue;
            }

            $merged[] = $item;
        }

        return $merged;
    }

    /** @return array<int, string> every date from $from to $to (Y-m-d) */
    private function dates(string $from, string $to): array
    {
        $dates = [];

        for ($day = Carbon::parse($from)->startOfDay(), $last = Carbon::parse($to)->startOfDay(); $day->lessThanOrEqualTo($last); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{name: string, date_from: string, date_to: string, tentative: bool}
     */
    private function normalise(array $row, int $year): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('the name is missing');
        }
        if (mb_strlen($name) > 200) {
            throw new InvalidArgumentException('the name is longer than 200 characters');
        }

        $from = $this->date($row['date_from'] ?? $row['date'] ?? null, 'date_from');
        $to = filled($row['date_to'] ?? null) ? $this->date($row['date_to'], 'date_to') : $from;

        if ($to < $from) {
            throw new InvalidArgumentException("{$name}: the end date (date_to) is before the start date (date_from)");
        }
        if ((int) substr($from, 0, 4) !== $year || (int) substr($to, 0, 4) !== $year) {
            throw new InvalidArgumentException("{$name}: the dates are not in {$year}");
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 14) {
            throw new InvalidArgumentException("{$name}: a holiday longer than 15 days looks like a typing mistake");
        }

        $tentative = $row['tentative'] ?? false;

        return [
            'name'      => $name,
            'date_from' => $from,
            'date_to'   => $to,
            'tentative' => is_bool($tentative) ? $tentative : in_array(Str::lower(trim((string) $tentative)), ['1', 'yes', 'y', 'true', 'tentative'], true),
        ];
    }

    private function date(mixed $value, string $field): string
    {
        $value = trim((string) $value);

        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date !== false && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        throw new InvalidArgumentException("{$field} \"{$value}\" is not a date like 2026-08-14 or 14/08/2026");
    }

    /** @return array<string, array<string, mixed>> "entry N" => row */
    private function phpRows(string $path): array
    {
        $data = require $path;
        $rows = is_array($data) ? ($data['holidays'] ?? $data) : null;

        if (! is_array($rows)) {
            throw new InvalidArgumentException("{$path} does not return a holiday list.");
        }

        $result = [];
        foreach (array_values($rows) as $index => $row) {
            $result['entry '.($index + 1)] = is_array($row) ? $row : [];
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> "line N" => row keyed by the header */
    private function csvRows(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $header = null;
        $rows = [];

        foreach ($file as $index => $values) {
            if (! is_array($values) || $values === [null] || implode('', array_map('trim', array_map('strval', $values))) === '') {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn ($column): string => Str::snake(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $column))), $values);

                if (! in_array('name', $header, true) || (! in_array('date_from', $header, true) && ! in_array('date', $header, true))) {
                    throw new InvalidArgumentException('The CSV needs a header row with at least: name, date_from (or date). Optional: date_to, tentative.');
                }

                continue;
            }

            $rows['line '.($index + 1)] = array_combine($header, array_slice(array_pad($values, count($header), null), 0, count($header)));
        }

        return $rows;
    }
}
