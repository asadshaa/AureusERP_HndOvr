<?php

namespace App\Services\Demo;

use App\Models\DemoRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Removes what the demo seeder created, using demo_records only: the rows
 * it created (newest first, so children go before their parents), the files
 * it stored, and the values it filled into pre-existing demo rows (restored
 * only where they still hold the seeder's value). Nothing that is not in
 * demo_records is ever deleted, except rows hanging off a demo user or
 * application that have no model of their own (role / company pivots, the
 * user's notifications).
 */
class DemoPurger
{
    /** Tables never deleted from, even if a row was recorded (shared configuration). */
    private const KEEP_TABLES = [
        'companies',
        'employees_employee_code_sequences',
        'support_approval_workflows',
        'support_approval_steps',
        'roles',
        'permissions',
    ];

    /** @var array<int, string> */
    private array $failures = [];

    /**
     * @param  array<int, int>|null  $companyIds  null = every company
     * @return array{deleted: int, restored: int, files: int, failed: int, users: array<int, string>, failures: array<int, string>}
     */
    public function purge(?array $companyIds = null, bool $activityOnly = false): array
    {
        $this->failures = [];

        $records = DemoRecord::query()
            ->when($companyIds !== null, fn ($query) => $query->whereIn('company_id', $companyIds))
            ->when($activityOnly, fn ($query) => $query->where('phase', DemoRecord::PHASE_ACTIVITY))
            ->orderByDesc('id')
            ->get();

        $restored = $this->restoreFills($records->whereNotNull('original'));
        $files = $this->deleteFiles($records->where('record_table', DemoRecord::FILE_TABLE));

        $rows = $records
            ->whereNull('original')
            ->where('record_table', '!=', DemoRecord::FILE_TABLE)
            ->reject(fn (DemoRecord $record): bool => in_array($record->record_table, self::KEEP_TABLES, true))
            ->unique(fn (DemoRecord $record): string => $record->record_table.'|'.$record->record_id)
            ->values();

        $userEmails = DB::table('users')->whereIn('id', $rows->where('record_table', 'users')->pluck('record_id'))->pluck('email')->all();

        $this->cleanDependents($rows);
        $deleted = $this->deleteRows($rows);

        DemoRecord::query()->whereIn('id', $records->pluck('id'))->delete();
        $this->forgetAccounts($userEmails);

        return [
            'deleted'  => $deleted,
            'restored' => $restored,
            'files'    => $files,
            'failed'   => count($this->failures),
            'users'    => $userEmails,
            'failures' => array_slice($this->failures, 0, 20),
        ];
    }

    /** @param  Collection<int, DemoRecord>  $fills */
    private function restoreFills(Collection $fills): int
    {
        $restored = 0;

        foreach ($fills as $fill) {
            $row = DB::table($fill->record_table)->where('id', $fill->record_id)->first();

            if (! $row) {
                continue;
            }

            $changes = [];
            foreach ((array) $fill->applied as $column => $value) {
                if (property_exists($row, $column) && $this->same($row->{$column}, $value)) {
                    $changes[$column] = ((array) $fill->original)[$column] ?? null;
                }
            }

            if ($changes !== []) {
                DB::table($fill->record_table)->where('id', $fill->record_id)->update($changes);
                $restored++;
            }
        }

        return $restored;
    }

    /** @param  Collection<int, DemoRecord>  $files */
    private function deleteFiles(Collection $files): int
    {
        $count = 0;

        foreach ($files as $file) {
            [$disk, $path] = explode(':', (string) $file->record_id, 2) + [1 => ''];

            try {
                if ($path !== '' && Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                    $count++;
                }

                // A per-record folder the seeder's files lived in (employees/documents/{employee}...), once empty.
                $directory = dirname($path);
                if (substr_count($directory, '/') >= 2 && Storage::disk($disk)->exists($directory)
                    && Storage::disk($disk)->allFiles($directory) === [] && Storage::disk($disk)->allDirectories($directory) === []) {
                    Storage::disk($disk)->deleteDirectory($directory);
                }
            } catch (Throwable $e) {
                $this->failures[] = "file {$file->record_id}: {$e->getMessage()}";
            }
        }

        return $count;
    }

    /**
     * Rows without a model of their own that hang off demo users and
     * applications (they would block or outlive the delete).
     *
     * @param  Collection<int, DemoRecord>  $rows
     */
    private function cleanDependents(Collection $rows): void
    {
        $userIds = $rows->where('record_table', 'users')->pluck('record_id')->map(fn ($id): int => (int) $id)->all();
        $applicantIds = $rows->where('record_table', 'recruitments_applicants')->pluck('record_id')->map(fn ($id): int => (int) $id)->all();

        if ($userIds !== []) {
            $userTypes = DB::table('model_has_roles')->whereIn('model_id', $userIds)->distinct()->pluck('model_type')
                ->filter(fn (string $type): bool => Str::endsWith($type, 'User'))->all();

            $this->attempt(fn () => DB::table('model_has_roles')->whereIn('model_type', $userTypes)->whereIn('model_id', $userIds)->delete(), 'model_has_roles');
            $this->attempt(fn () => DB::table('model_has_permissions')->whereIn('model_type', $userTypes)->whereIn('model_id', $userIds)->delete(), 'model_has_permissions');
            $this->attempt(fn () => DB::table('user_allowed_companies')->whereIn('user_id', $userIds)->delete(), 'user_allowed_companies');
            $this->attempt(fn () => DB::table('notifications')->where('notifiable_type', 'like', '%User')->whereIn('notifiable_id', $userIds)->delete(), 'notifications');
        }

        if ($applicantIds !== []) {
            $this->attempt(fn () => DB::table('recruitments_applicant_interviewers')->whereIn('applicant_id', $applicantIds)->delete(), 'recruitments_applicant_interviewers');
            $this->attempt(fn () => DB::table('recruitments_applicant_applicant_categories')->whereIn('applicant_id', $applicantIds)->delete(), 'recruitments_applicant_applicant_categories');
        }
    }

    /**
     * Newest first, consecutive rows of one table in one statement. A row a
     * foreign key still holds is retried after the others (up to three passes).
     *
     * @param  Collection<int, DemoRecord>  $rows
     */
    private function deleteRows(Collection $rows): int
    {
        $deleted = 0;
        $pending = $rows->all();

        for ($pass = 1; $pass <= 3 && $pending !== []; $pass++) {
            $retry = [];

            foreach ($this->runs($pending) as [$table, $batch]) {
                try {
                    $deleted += DB::table($table)->whereIn('id', array_map(fn (DemoRecord $record): string => (string) $record->record_id, $batch))->delete();
                } catch (QueryException) {
                    foreach ($batch as $record) {
                        try {
                            $deleted += DB::table($table)->where('id', $record->record_id)->delete();
                        } catch (QueryException $e) {
                            $retry[] = $record;

                            if ($pass === 3) {
                                $this->failures[] = "{$table}#{$record->record_id}: ".Str::limit($e->getMessage(), 160);
                            }
                        }
                    }
                }
            }

            $pending = $retry;
        }

        return $deleted;
    }

    /**
     * @param  array<int, DemoRecord>  $records
     * @return array<int, array{0: string, 1: array<int, DemoRecord>}>
     */
    private function runs(array $records): array
    {
        $runs = [];

        foreach ($records as $record) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][0] === $record->record_table && count($runs[$last][1]) < 500) {
                $runs[$last][1][] = $record;
            } else {
                $runs[] = [$record->record_table, [$record]];
            }
        }

        return $runs;
    }

    private function attempt(callable $callback, string $label): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->failures[] = "{$label}: ".Str::limit($e->getMessage(), 160);
        }
    }

    private function same(mixed $current, mixed $applied): bool
    {
        if ($current === null || $applied === null) {
            return $current === $applied;
        }

        if (is_numeric($current) && is_numeric($applied)) {
            return abs((float) $current - (float) $applied) < 0.00001;
        }

        return Str::startsWith((string) $current, (string) $applied) || (string) $current === (string) $applied;
    }

    /** Drops the purged demo logins from storage/app/private/demo-accounts.txt. */
    private function forgetAccounts(array $emails): void
    {
        $file = storage_path('app/private/demo-accounts.txt');

        if ($emails === [] || ! is_file($file)) {
            return;
        }

        $emails = array_map('strtolower', $emails);
        $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];
        $kept = array_filter($lines, function (string $line) use ($emails): bool {
            $columns = explode("\t", $line);

            return ! isset($columns[2]) || ! in_array(strtolower(trim($columns[2])), $emails, true);
        });

        file_put_contents($file, rtrim(implode(PHP_EOL, $kept)).PHP_EOL);
    }
}
