<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webkul\Employee\Enums\AttendanceSource;
use Webkul\Employee\Enums\AttendanceVerificationAction as Action;
use Webkul\Employee\Enums\AttendanceVerificationMethod as Method;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Enums\AttendanceVerificationStatus as Status;
use Webkul\Employee\Enums\DeviceImportRowStatus as RowStatus;
use Webkul\Employee\Enums\PunchPairingMode;
use Webkul\Employee\Models\AttendanceImportBatch;
use Webkul\Employee\Models\AttendanceImportDay;
use Webkul\Employee\Models\AttendanceImportRow;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendancePolicy;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\Data\ParsedPunchRow;
use Webkul\Employee\Support\Cnic;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Biometric / device attendance file import (Attendance -> Device import),
 * company-scoped and HR only.
 *
 *  1. preview(): the file is read (DevicePunchFileParser), every row is
 *     matched to an employee of the company (device ID, else employee code,
 *     else an exact CNIC for users who may see it) and given a status
 *     (OK / unknown employee / duplicate / outside the employment dates /
 *     bad date / day already has manual entries). Nothing is written to
 *     attendance.
 *  2. import(): the rows are checked again and each employee-day is written
 *     in its own transaction through AttendanceIntervalService (intervals
 *     with source "biometric", the day summary from them). A punch is
 *     imported once (unique punch_key); a day with manual / GPS entries, or
 *     an imported day corrected since, is never overwritten -- its rows are
 *     flagged instead. An odd punch leaves the last pair open and the day in
 *     the review queue; no time is ever made up. A day the attendance model
 *     refuses (e.g. a locked month) fails alone, reported per row.
 *  3. undo(): removes the batch's punches; days left without punches are
 *     deleted, days that still hold another import's punches are rebuilt from
 *     them, and days changed since the import are kept. All or nothing: a
 *     locked month refuses the whole undo.
 */
class DeviceAttendanceImportService
{
    public const DISK = 'local';

    public const DIRECTORY = 'employees/attendance-imports';

    public const MAX_SIZE_KB = 10240;

    /** Two punches of the same employee this close together are one (a double tap on the device). */
    public const DOUBLE_TAP_SECONDS = 60;

    public const FLAG_SOURCE = 'device_import';

    private const FUTURE_TOLERANCE_MINUTES = 10;

    public function __construct(
        protected DevicePunchFileParser $parser,
        protected PunchPairer $pairer,
        protected AttendanceIntervalService $intervals,
        protected AttendanceScheduleResolver $schedule,
    ) {}

    /** HR of the company (manage attendance + view all records); line managers cannot import. */
    public static function canManage(?User $user, ?int $companyId): bool
    {
        return $user !== null
            && $companyId !== null
            && $user->can(HrPermissions::ManageAttendance)
            && $user->can(HrPermissions::ViewAllRecords)
            && CurrentCompany::canAccess($companyId, $user);
    }

    /** Time zone of the device clocks (all companies are in Pakistan). */
    public function timezone(): string
    {
        $timezone = (string) config('attendance.device_import.timezone', config('attendance.monthly_report.timezone', 'Asia/Karachi'));

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'Asia/Karachi';
    }

    public function pairingMode(int $companyId): PunchPairingMode
    {
        return PunchPairingMode::tryFrom((string) AttendancePolicy::forCompany($companyId)->getAttribute('punch_pairing_mode'))
            ?? PunchPairingMode::FirstInLastOut;
    }

    /** Saves the company's pairing mode on its attendance policy row (created with the defaults if missing). */
    public function setPairingMode(User $user, int $companyId, PunchPairingMode $mode): void
    {
        $this->authorize($user, $companyId);

        $policy = AttendancePolicy::query()->firstOrNew(['company_id' => $companyId]);
        if (! $policy->exists) {
            $policy->fill(AttendancePolicy::defaults());
        }

        $policy->forceFill(['punch_pairing_mode' => $mode->value, 'updated_by' => $user->id])->save();
    }

    // ------------------------------------------------------------------
    // Preview
    // ------------------------------------------------------------------

    /**
     * Reads a file already stored on the private disk and records it as a
     * preview batch with one row per file row. Writes no attendance.
     *
     * @param  string  $dateOrder  "auto", "dmy" or "mdy"
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the file cannot be read
     */
    public function preview(User $user, int $companyId, string $storedPath, ?string $originalName = null, string $dateOrder = 'auto'): AttendanceImportBatch
    {
        $this->authorize($user, $companyId);

        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($storedPath)) {
            throw new InvalidArgumentException('The uploaded file was not found. Upload it again.');
        }

        $absolutePath = $disk->path($storedPath);
        $fileName = mb_substr($originalName ?: basename($storedPath), 0, 255);
        $parsed = $this->parser->parse($absolutePath, $fileName, $dateOrder);

        return DB::transaction(function () use ($user, $companyId, $storedPath, $absolutePath, $fileName, $parsed): AttendanceImportBatch {
            $batch = AttendanceImportBatch::query()->create([
                'company_id'   => $companyId,
                'file_name'    => $fileName,
                'file_path'    => $storedPath,
                'file_hash'    => hash_file('sha256', $absolutePath) ?: null,
                'file_format'  => $parsed->format,
                'date_order'   => $parsed->dateOrder,
                'pairing_mode' => $this->pairingMode($companyId)->value,
                'timezone'     => $this->timezone(),
                'status'       => AttendanceImportBatch::STATUS_PREVIEW,
                'total_rows'   => count($parsed->rows),
                'created_by'   => $user->id,
            ]);

            $rows = $this->evaluate($batch, $user, array_map(fn (ParsedPunchRow $row): array => $this->workRow($row), $parsed->rows));
            $this->insertRows($batch, $rows);

            $summary = $this->summarize($batch, $rows);
            $summary['columns'] = $parsed->columns;
            $summary['warnings'] = $parsed->warnings;

            $batch->update($this->countColumns($rows, $summary) + ['summary' => $summary]);

            return $batch;
        });
    }

    /** Throws away a batch that was only previewed (and its stored file). */
    public function discard(User $user, AttendanceImportBatch $batch): void
    {
        $this->authorize($user, (int) $batch->company_id);

        if ($batch->status !== AttendanceImportBatch::STATUS_PREVIEW) {
            throw new RuntimeException('Only a file that was not imported can be discarded.');
        }

        Storage::disk(self::DISK)->delete($batch->file_path);
        $batch->delete();
    }

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    /**
     * Writes the batch's OK rows to attendance, one employee-day per
     * transaction. Rows are checked again first, so what changed since the
     * preview (new device IDs, days corrected meanwhile, another import of
     * the same punches) is respected.
     *
     * @throws AuthorizationException
     * @throws RuntimeException when the batch was imported already
     */
    public function import(User $user, AttendanceImportBatch $batch): AttendanceImportBatch
    {
        $this->authorize($user, (int) $batch->company_id);

        $claimed = AttendanceImportBatch::query()
            ->whereKey($batch->id)
            ->where('status', AttendanceImportBatch::STATUS_PREVIEW)
            ->update(['status' => AttendanceImportBatch::STATUS_IMPORTING, 'imported_by' => $user->id, 'updated_at' => now()]);

        if ($claimed === 0) {
            throw new RuntimeException('This file has already been imported.');
        }

        $batch->refresh();
        $rows = $this->evaluate($batch, $user, $this->loadRows($batch));

        $days = [];
        foreach ($rows as $index => $row) {
            if ($row['status'] === RowStatus::Ok) {
                $days[$row['employee_id'].'|'.$row['attendance_date']][] = $index;
            }
        }
        ksort($days);

        $employees = Employee::query()
            ->where('company_id', $batch->company_id)
            ->whereIn('id', array_unique(array_map(fn (string $key): int => (int) explode('|', $key)[0], array_keys($days))))
            ->get()
            ->keyBy('id');

        $written = ['days' => 0, 'review' => 0, 'created' => 0, 'merged' => 0];

        foreach ($days as $key => $indexes) {
            [$employeeId, $date] = explode('|', $key);
            $employee = $employees->get((int) $employeeId);
            $dayRows = array_map(fn (int $index): array => $rows[$index], $indexes);

            try {
                $outcome = $employee
                    ? DB::transaction(fn (): array => $this->importDay($batch, $user, $employee, $date, $dayRows))
                    : ['status' => 'error', 'message' => 'The employee is no longer in this company.'];
            } catch (UniqueConstraintViolationException) {
                $outcome = ['status' => 'duplicate', 'message' => 'Imported by another upload at the same time.'];
            } catch (Throwable $exception) {
                $locked = $this->isMonthLock($exception);
                if (! $locked) {
                    report($exception);
                }

                $outcome = $locked
                    ? ['status' => 'locked', 'message' => mb_substr('Locked month: '.$exception->getMessage(), 0, 255)]
                    : ['status' => 'error', 'message' => mb_substr('Not imported: '.$exception->getMessage(), 0, 255)];
            }

            foreach ($indexes as $index) {
                $rows[$index]['status'] = match ($outcome['status']) {
                    'written'    => RowStatus::Imported,
                    'manual_day' => RowStatus::ManualDay,
                    'duplicate'  => RowStatus::Duplicate,
                    'locked'     => RowStatus::LockedMonth,
                    default      => RowStatus::Error,
                };
                $rows[$index]['message'] = $outcome['message'] ?? null;
            }

            if ($outcome['status'] === 'written') {
                $written['days']++;
                $written['review'] += $outcome['needs_review'] ? 1 : 0;
                $written[$outcome['created'] ? 'created' : 'merged']++;
            }
        }

        // Imported rows were claimed inside their day's transaction; store everything else now.
        $this->persistRows($batch, array_filter($rows, fn (array $row): bool => $row['status'] !== RowStatus::Imported));

        $summary = $this->summarize($batch, $rows) + ($batch->summary ?? []);
        $summary['review_days'] = $written['review'];
        $summary['result'] = [
            'days_written'    => $written['days'],
            'records_created' => $written['created'],
            'days_merged'     => $written['merged'],
            'review_days'     => $written['review'],
        ];

        $batch->update(array_merge($this->countColumns($rows, $summary), [
            'status'       => AttendanceImportBatch::STATUS_IMPORTED,
            'imported_at'  => now(),
            'days_written' => $written['days'],
            'summary'      => $summary,
        ]));

        return $batch->refresh();
    }

    /**
     * One employee-day inside its transaction.
     *
     * @param  array<int, array<string, mixed>>  $dayRows
     * @return array{status: string, message?: ?string, record_id?: int, created?: bool, needs_review?: bool}
     */
    private function importDay(AttendanceImportBatch $batch, User $user, Employee $employee, string $date, array $dayRows): array
    {
        // Same lock as GPS check-in / check-out, so the two never interleave.
        Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

        $record = AttendanceRecord::query()
            ->where('company_id', $batch->company_id)
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $date)
            ->lockForUpdate()
            ->first();

        if ($record !== null && ($ownership = $this->dayOwnership($record)) !== 'owned') {
            return ['status' => 'manual_day', 'message' => $this->manualDayMessage($record, $ownership)];
        }

        foreach ($dayRows as $row) {
            AttendanceImportRow::query()->whereKey($row['id'])->update([
                'employee_id'     => $employee->id,
                'punched_at'      => $this->storedTime($row['punched_at']),
                'attendance_date' => $date,
                'status'          => RowStatus::Imported->value,
                'message'         => null,
                'punch_key'       => AttendanceImportRow::punchKey($employee->id, $row['punched_at']->utc()->format('Y-m-d H:i:s')),
                'updated_at'      => now(),
            ]);
        }

        $write = $this->writeDay($batch, $user, $employee, $date, $record, $this->livePunches($employee->id, $date), 'Rebuilt from device punches (import #'.$batch->id.')');

        AttendanceImportRow::query()
            ->whereIn('id', array_column($dayRows, 'id'))
            ->update(['attendance_record_id' => $write['record']->id]);

        return ['status' => 'written', 'record_id' => $write['record']->id, 'created' => $write['created'], 'needs_review' => $write['needs_review']];
    }

    // ------------------------------------------------------------------
    // Undo
    // ------------------------------------------------------------------

    /**
     * @return array{days_removed: int, days_rebuilt: int, days_kept: int, kept: array<int, array{employee: ?string, date: string}>}
     *
     * @throws AuthorizationException
     * @throws RuntimeException when the batch cannot be undone or a day is in a locked month
     */
    public function undo(User $user, AttendanceImportBatch $batch): array
    {
        $this->authorize($user, (int) $batch->company_id);

        if (! $batch->canBeUndone()) {
            throw new RuntimeException('Only an imported file can be undone.');
        }

        try {
            return DB::transaction(fn (): array => $this->undoLocked($user, $batch));
        } catch (Throwable $exception) {
            if ($this->isMonthLock($exception)) {
                throw new RuntimeException('Undo refused, nothing was changed: part of this import is in a locked month. '.$exception->getMessage(), 0, $exception);
            }

            throw $exception;
        }
    }

    /** @return array{days_removed: int, days_rebuilt: int, days_kept: int, kept: array<int, array{employee: ?string, date: string}>} */
    private function undoLocked(User $user, AttendanceImportBatch $batch): array
    {
        $batch = AttendanceImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
        if (! $batch->canBeUndone()) {
            throw new RuntimeException('Only an imported file can be undone.');
        }

        $rows = AttendanceImportRow::query()
            ->where('batch_id', $batch->id)
            ->where('status', RowStatus::Imported->value)
            ->orderBy('employee_id')
            ->orderBy('attendance_date')
            ->get(['id', 'employee_id', 'attendance_date']);

        $employees = Employee::withTrashed()->whereIn('id', $rows->pluck('employee_id')->unique())->get()->keyBy('id');
        $removed = 0;
        $rebuilt = 0;
        $kept = [];

        foreach ($rows->groupBy(fn (AttendanceImportRow $row): string => $row->employee_id.'|'.$row->attendance_date->toDateString()) as $key => $dayRows) {
            [$employeeId, $date] = explode('|', $key);
            $employee = $employees->get((int) $employeeId);
            $ids = $dayRows->pluck('id')->all();

            Employee::query()->whereKey((int) $employeeId)->lockForUpdate()->first();
            $record = AttendanceRecord::query()
                ->where('company_id', $batch->company_id)
                ->where('employee_id', (int) $employeeId)
                ->where('attendance_date', $date)
                ->lockForUpdate()
                ->first();

            if ($record !== null && $this->dayOwnership($record) !== 'owned') {
                $kept[] = ['employee' => $employee?->name, 'date' => $date];
                AttendanceImportRow::query()->whereIn('id', $ids)->update(['message' => 'Kept by the undo: the day was changed after the import.', 'updated_at' => now()]);

                continue;
            }

            AttendanceImportRow::query()->whereIn('id', $ids)->update([
                'status'     => RowStatus::Undone->value,
                'punch_key'  => null,
                'message'    => 'Removed by the undo of this import.',
                'updated_at' => now(),
            ]);

            if ($record === null) {
                continue;
            }

            $remaining = $this->livePunches((int) $employeeId, $date);
            if ($remaining === [] || $employee === null) {
                $this->removeDay($record, $user, $batch);
                $removed++;

                continue;
            }

            // The day still holds punches of another import: rebuild it from those.
            $owner = AttendanceImportBatch::query()->find($remaining[0]['batch_id']) ?? $batch;
            $this->writeDay($owner, $user, $employee, $date, $record, $remaining, 'Rebuilt after the undo of device import #'.$batch->id);
            $rebuilt++;
        }

        $summary = ['days_removed' => $removed, 'days_rebuilt' => $rebuilt, 'days_kept' => count($kept), 'kept' => array_slice($kept, 0, 200)];

        $batch->update([
            'status'       => $kept === [] ? AttendanceImportBatch::STATUS_UNDONE : AttendanceImportBatch::STATUS_PARTIALLY_UNDONE,
            'undone_by'    => $user->id,
            'undone_at'    => now(),
            'undo_summary' => $summary,
        ]);

        return $summary;
    }

    /**
     * Deletes a day only imports wrote. The intervals are voided through the
     * model first, so attendance model guards (e.g. a month lock) apply to the
     * undo as they do to any other change.
     */
    private function removeDay(AttendanceRecord $record, User $user, AttendanceImportBatch $batch): void
    {
        AttendanceInterval::query()
            ->where('attendance_record_id', $record->id)
            ->active()
            ->get()
            ->each(fn (AttendanceInterval $interval) => $interval->update([
                'voided_at'   => now(),
                'voided_by'   => $user->id,
                'void_reason' => 'Removed by the undo of device import #'.$batch->id,
            ]));

        $this->withdrawFlags($record, $user, 'Device import #'.$batch->id.' was undone.');

        AttendanceImportDay::query()
            ->where('attendance_record_id', $record->id)
            ->where('status', AttendanceImportDay::STATUS_CURRENT)
            ->update(['status' => AttendanceImportDay::STATUS_UNDONE, 'updated_at' => now()]);

        $record->delete();
    }

    // ------------------------------------------------------------------
    // Writing a day
    // ------------------------------------------------------------------

    /**
     * (Re)writes one employee-day from its live device punches through
     * AttendanceIntervalService, then snapshots it.
     *
     * @param  array<int, array{at: CarbonImmutable, direction: ?string, batch_id: int}>  $punches
     * @return array{record: AttendanceRecord, created: bool, needs_review: bool}
     */
    private function writeDay(AttendanceImportBatch $batch, User $user, Employee $employee, string $date, ?AttendanceRecord $record, array $punches, string $rebuildReason): array
    {
        $pairing = $this->pairer->pair($punches, $batch->pairingMode());
        $pairs = $pairing['pairs'];
        $needsReview = $pairing['flags'] !== [];
        $created = $record === null;
        $appTimezone = (string) config('app.timezone');

        $record = AttendanceIntervalService::quietly(function () use ($batch, $user, $employee, $date, $record, $pairs, $needsReview, $appTimezone, $rebuildReason): AttendanceRecord {
            $firstIn = $pairs[0][0]->setTimezone($appTimezone);

            if ($record === null) {
                [$scheduledStart, $scheduledEnd] = $this->schedule->scheduledWindowFor($employee, $date);

                $record = new AttendanceRecord;
                $record->fill([
                    'company_id'       => $batch->company_id,
                    'employee_id'      => $employee->id,
                    'attendance_date'  => $date,
                    'check_in'         => $firstIn,
                    'check_out'        => null,
                    'status'           => 'present',
                    'source'           => AttendanceSource::Biometric->value,
                    'source_reference' => 'device_import:'.$batch->id,
                    'scheduled_start'  => $scheduledStart?->setTimezone($appTimezone),
                    'scheduled_end'    => $scheduledEnd?->setTimezone($appTimezone),
                ]);
                $record->creator_id = $user->id;
                $record->save();
            } else {
                // An imported day nobody touched since: its intervals are replaced (voided, kept for audit).
                AttendanceInterval::query()
                    ->where('attendance_record_id', $record->id)
                    ->active()
                    ->get()
                    ->each(fn (AttendanceInterval $interval) => $interval->update([
                        'voided_at'   => now(),
                        'voided_by'   => $user->id,
                        'void_reason' => mb_substr($rebuildReason, 0, 255),
                    ]));

                $record->fill(['check_in' => $firstIn, 'check_out' => null, 'source_reference' => 'device_import:'.$batch->id]);
                $record->save();
            }

            foreach ($pairs as [$in, $out]) {
                $this->intervals->open($record, $in->setTimezone($appTimezone), AttendanceSource::Biometric->value);
                if ($out !== null) {
                    $this->intervals->close($record, $out->setTimezone($appTimezone));
                }
            }

            return $this->intervals->syncSummary($record, [
                'verification_status' => $needsReview ? Status::NeedsReview->value : null,
            ]);
        });

        if ($needsReview) {
            $this->flagForReview($record, $user, $batch, $pairing['flags']);
        } else {
            $this->withdrawFlags($record, $user, 'Completed by device import #'.$batch->id.'.');
        }

        AttendanceImportDay::query()
            ->where('attendance_record_id', $record->id)
            ->where('status', AttendanceImportDay::STATUS_CURRENT)
            ->update(['status' => AttendanceImportDay::STATUS_SUPERSEDED, 'updated_at' => now()]);

        AttendanceImportDay::query()->create([
            'batch_id'             => $batch->id,
            'company_id'           => $batch->company_id,
            'employee_id'          => $employee->id,
            'attendance_date'      => $date,
            'attendance_record_id' => $record->id,
            'record_created'       => $created,
            'punch_count'          => count($punches),
            'needs_review'         => $needsReview,
            'fingerprint'          => $this->fingerprint($record->fresh()),
            'status'               => AttendanceImportDay::STATUS_CURRENT,
        ]);

        return ['record' => $record, 'created' => $created, 'needs_review' => $needsReview];
    }

    /**
     * A pending review item (the same kind hr:flag-forgotten-shifts raises),
     * so the day appears in Needs Review and HR can approve it or Edit the
     * missing time. One per day.
     *
     * @param  array<int, string>  $flags
     */
    private function flagForReview(AttendanceRecord $record, User $user, AttendanceImportBatch $batch, array $flags): void
    {
        if ($this->pendingFlags($record)->isNotEmpty()) {
            return;
        }

        AttendanceVerification::query()->create([
            'company_id'           => $record->company_id,
            'employee_id'          => $record->employee_id,
            'user_id'              => $user->id,
            'attendance_record_id' => $record->id,
            'action'               => Action::SystemFlag,
            'method'               => Method::Manual,
            'result'               => Result::NeedsReview,
            'accepted'             => false,
            'flags'                => array_values(array_unique([...$flags, self::FLAG_SOURCE])),
            'metadata'             => [
                'source'   => self::FLAG_SOURCE,
                'batch_id' => $batch->id,
                'reason'   => 'Device import: '.implode('; ', array_map(fn (string $flag): string => PunchPairer::describeFlag($flag), $flags)).'. Set the missing time with Edit, or approve the day as it is.',
            ],
            'review_status'        => 'pending',
            'server_recorded_at'   => now(),
        ]);
    }

    private function withdrawFlags(AttendanceRecord $record, User $user, string $note): void
    {
        foreach ($this->pendingFlags($record) as $verification) {
            $verification->update([
                'review_status' => 'withdrawn',
                'reviewed_by'   => $user->id,
                'reviewed_at'   => now(),
                'review_note'   => $note,
            ]);
        }
    }

    /** @return Collection<int, AttendanceVerification> */
    private function pendingFlags(AttendanceRecord $record): Collection
    {
        return AttendanceVerification::query()
            ->where('attendance_record_id', $record->id)
            ->where('action', Action::SystemFlag->value)
            ->where('review_status', 'pending')
            ->get()
            ->filter(fn (AttendanceVerification $verification): bool => ($verification->metadata['source'] ?? null) === self::FLAG_SOURCE)
            ->values();
    }

    /** @return array<int, array{at: CarbonImmutable, direction: ?string, batch_id: int}> live imported punches of one employee-day */
    private function livePunches(int $employeeId, string $date): array
    {
        return AttendanceImportRow::query()
            ->livePunches()
            ->where('employee_id', $employeeId)
            ->where('attendance_date', $date)
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get(['batch_id', 'punched_at', 'direction'])
            ->map(fn (AttendanceImportRow $row): array => [
                'at'        => CarbonImmutable::instance($row->punched_at)->utc(),
                'direction' => $row->direction,
                'batch_id'  => (int) $row->batch_id,
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // Day ownership (untouched since the import?)
    // ------------------------------------------------------------------

    /**
     * What a day is to the importer: 'owned' (written by an import and
     * unchanged since), 'edited' (written by an import, changed since) or
     * 'foreign' (manual, GPS, ... never written by an import).
     */
    public function dayOwnership(AttendanceRecord $record): string
    {
        return $this->dayOwnerships(new EloquentCollection([$record]))[$record->id];
    }

    /**
     * @param  EloquentCollection<int, AttendanceRecord>  $records
     * @return array<int, string> record id => owned | edited | foreign
     */
    private function dayOwnerships(EloquentCollection $records): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        $ids = $records->modelKeys();
        $snapshots = AttendanceImportDay::query()
            ->whereIn('attendance_record_id', $ids)
            ->orderBy('id')
            ->get(['id', 'attendance_record_id', 'fingerprint', 'status'])
            ->groupBy('attendance_record_id');

        $intervals = AttendanceInterval::query()
            ->whereIn('attendance_record_id', $ids)
            ->active()
            ->orderBy('check_in')
            ->orderBy('id')
            ->get(['id', 'attendance_record_id', 'check_in', 'check_out', 'source'])
            ->groupBy('attendance_record_id');

        $result = [];
        foreach ($records as $record) {
            $history = $snapshots->get($record->id, collect());
            $current = $history->last(fn (AttendanceImportDay $day): bool => $day->status === AttendanceImportDay::STATUS_CURRENT);

            $result[$record->id] = match (true) {
                $history->isEmpty()                                                                                                           => 'foreign',
                $current !== null && hash_equals($current->fingerprint, $this->fingerprint($record, $intervals->get($record->id, collect()))) => 'owned',
                default                                                                                                                       => 'edited',
            };
        }

        return $result;
    }

    /**
     * The day's times, status, source, notes and live intervals. Any edit
     * (Filament, HR correction, approved request, GPS check-in on the day)
     * changes it.
     *
     * @param  Collection<int, AttendanceInterval>|null  $intervals
     */
    public function fingerprint(AttendanceRecord $record, ?Collection $intervals = null): string
    {
        $intervals ??= AttendanceInterval::query()
            ->where('attendance_record_id', $record->id)
            ->active()
            ->orderBy('check_in')
            ->orderBy('id')
            ->get(['id', 'attendance_record_id', 'check_in', 'check_out', 'source']);

        $moment = fn (?CarbonInterface $value): ?string => $value ? CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s') : null;

        return sha1((string) json_encode([
            'id'        => (int) $record->id,
            'check_in'  => $moment($record->check_in),
            'check_out' => $moment($record->check_out),
            'status'    => (string) $record->status,
            'source'    => (string) $record->source,
            'notes'     => (string) $record->notes,
            'intervals' => $intervals->map(fn (AttendanceInterval $interval): array => [
                (int) $interval->id,
                $moment($interval->check_in),
                $moment($interval->check_out),
                (string) $interval->source,
            ])->values()->all(),
        ]));
    }

    private function manualDayMessage(AttendanceRecord $record, string $ownership): string
    {
        if ($ownership === 'edited') {
            return 'This day was corrected after an earlier device import, so it was not changed.';
        }

        $source = AttendanceSource::tryFrom((string) $record->source)?->label() ?? ucfirst((string) $record->source);

        return mb_substr('Day already has manual entries ('.$source.'), so it was not changed.', 0, 255);
    }

    // ------------------------------------------------------------------
    // Row evaluation
    // ------------------------------------------------------------------

    /**
     * Gives every row its status. Pure read: nothing is written.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function evaluate(AttendanceImportBatch $batch, User $user, array $rows): array
    {
        $companyId = (int) $batch->company_id;
        $timezone = (string) $batch->timezone;
        $now = CarbonImmutable::now('UTC');

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->get(['id', 'company_id', 'name', 'employee_number', 'biometric_id', 'cnic', 'joining_date', 'leaving_date', 'departure_date'])
            ->keyBy('id');

        $byDevice = [];
        $byCode = [];
        $byCnic = [];
        foreach ($employees as $employee) {
            if (filled($employee->biometric_id)) {
                $byDevice[self::deviceKey((string) $employee->biometric_id)] = (int) $employee->id;
            }
            if (filled($employee->employee_number)) {
                $byCode[self::codeKey((string) $employee->employee_number)] = (int) $employee->id;
            }
            if (filled($employee->cnic)) {
                $byCnic[(string) $employee->cnic] = (int) $employee->id;
            }
        }
        if (! $user->can(HrPermissions::ViewSensitiveEmployeeData)) {
            $byCnic = [];
        }

        foreach ($rows as &$row) {
            $row['employee_id'] = $this->matchEmployee($row, $byDevice, $byCode, $byCnic);
            $row['punched_at'] = null;
            $row['attendance_date'] = null;
            $row['message'] = null;

            if ($row['local_datetime'] === null) {
                $row['status'] = RowStatus::BadDate;
                $row['message'] = $row['parse_error'] ?: 'Could not read the date / time.';

                continue;
            }

            if ($row['employee_id'] === null) {
                $row['status'] = RowStatus::UnknownEmployee;
                $label = $row['identifier'] ?? $row['employee_code'];
                $row['message'] = $label === null
                    ? 'No device ID or employee code in this row.'
                    : mb_substr('No employee of this company has the device ID or employee code "'.$label.'".', 0, 255);

                continue;
            }

            $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $row['local_datetime'], $timezone);
            $row['punched_at'] = $local->utc();
            $row['attendance_date'] = $local->toDateString();

            if ($row['punched_at']->greaterThan($now->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
                $row['status'] = RowStatus::BadDate;
                $row['message'] = 'The date is in the future.';

                continue;
            }

            $outside = $this->outsideEmployment($employees->get($row['employee_id']), $row['attendance_date']);
            $row['status'] = $outside === null ? RowStatus::Ok : RowStatus::OutsideEmployment;
            $row['message'] = $outside;
        }
        unset($row);

        $this->markDuplicates($batch, $rows);
        $this->markManualDays($companyId, $rows);

        return $rows;
    }

    /**
     * Same punch twice in the file, a punch already imported by another
     * batch, or a double tap within DOUBLE_TAP_SECONDS of a kept punch.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function markDuplicates(AttendanceImportBatch $batch, array &$rows): void
    {
        $candidates = array_keys(array_filter($rows, fn (array $row): bool => $row['status'] === RowStatus::Ok));
        if ($candidates === []) {
            return;
        }

        $dates = array_map(fn (int $index): string => $rows[$index]['attendance_date'], $candidates);
        $existing = AttendanceImportRow::query()
            ->where('company_id', $batch->company_id)
            ->where('batch_id', '!=', $batch->id)
            ->livePunches()
            ->whereIn('employee_id', array_unique(array_map(fn (int $index): int => $rows[$index]['employee_id'], $candidates)))
            ->whereBetween('attendance_date', [
                CarbonImmutable::parse(min($dates))->subDay()->toDateString(),
                CarbonImmutable::parse(max($dates))->addDay()->toDateString(),
            ])
            ->get(['batch_id', 'row_number', 'employee_id', 'punched_at', 'punch_key']);

        $imported = $existing->keyBy('punch_key');
        $timeline = [];
        foreach ($existing as $punch) {
            $timeline[$punch->employee_id][] = [CarbonImmutable::instance($punch->punched_at)->getTimestamp(), 'import #'.$punch->batch_id.' row '.$punch->row_number];
        }

        usort($candidates, fn (int $a, int $b): int => [$rows[$a]['employee_id'], $rows[$a]['punched_at']->getTimestamp(), $rows[$a]['row_number']]
            <=> [$rows[$b]['employee_id'], $rows[$b]['punched_at']->getTimestamp(), $rows[$b]['row_number']]);

        $seen = [];
        foreach ($candidates as $index) {
            $row = &$rows[$index];
            $key = AttendanceImportRow::punchKey($row['employee_id'], $row['punched_at']->format('Y-m-d H:i:s'));
            $timestamp = $row['punched_at']->getTimestamp();

            if ($imported->has($key)) {
                $previous = $imported->get($key);
                $row['status'] = RowStatus::Duplicate;
                $row['message'] = 'Already imported (import #'.$previous->batch_id.', row '.$previous->row_number.').';
            } elseif (isset($seen[$key])) {
                $row['status'] = RowStatus::Duplicate;
                $row['message'] = 'Same punch as row '.$seen[$key].'.';
            } elseif (($near = $this->nearbyPunch($timeline[$row['employee_id']] ?? [], $timestamp)) !== null) {
                $row['status'] = RowStatus::Duplicate;
                $row['message'] = 'Repeated punch within a minute of '.$near.'.';
            } else {
                $seen[$key] = $row['row_number'];
                $timeline[$row['employee_id']][] = [$timestamp, 'row '.$row['row_number']];
            }
            unset($row);
        }
    }

    /** @param  array<int, array{0: int, 1: string}>  $punches */
    private function nearbyPunch(array $punches, int $timestamp): ?string
    {
        foreach ($punches as [$other, $label]) {
            if ($other !== $timestamp && abs($other - $timestamp) <= self::DOUBLE_TAP_SECONDS) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Rows whose day already holds attendance that no untouched import wrote.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function markManualDays(int $companyId, array &$rows): void
    {
        $candidates = array_keys(array_filter($rows, fn (array $row): bool => $row['status'] === RowStatus::Ok));
        if ($candidates === []) {
            return;
        }

        $dates = array_map(fn (int $index): string => $rows[$index]['attendance_date'], $candidates);
        $records = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', array_unique(array_map(fn (int $index): int => $rows[$index]['employee_id'], $candidates)))
            ->whereBetween('attendance_date', [min($dates), max($dates)])
            ->get(['id', 'employee_id', 'attendance_date', 'check_in', 'check_out', 'status', 'source', 'notes']);

        $byDay = $records->keyBy(fn (AttendanceRecord $record): string => $record->employee_id.'|'.$record->attendance_date->toDateString());
        $ownership = $this->dayOwnerships($records);

        foreach ($candidates as $index) {
            $record = $byDay->get($rows[$index]['employee_id'].'|'.$rows[$index]['attendance_date']);
            if ($record !== null && $ownership[$record->id] !== 'owned') {
                $rows[$index]['status'] = RowStatus::ManualDay;
                $rows[$index]['message'] = $this->manualDayMessage($record, $ownership[$record->id]);
            }
        }
    }

    /**
     * Device ID first, then employee code, then (users who may see it) an
     * exact CNIC. Only employees of the batch's company are candidates.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $byDevice
     * @param  array<string, int>  $byCode
     * @param  array<string, int>  $byCnic
     */
    private function matchEmployee(array $row, array $byDevice, array $byCode, array $byCnic): ?int
    {
        $values = array_values(array_filter([$row['identifier'], $row['employee_code']], fn (?string $value): bool => filled($value)));

        foreach ($values as $value) {
            if (isset($byDevice[self::deviceKey($value)])) {
                return $byDevice[self::deviceKey($value)];
            }
        }

        foreach (array_reverse($values) as $value) {
            if (isset($byCode[self::codeKey($value)])) {
                return $byCode[self::codeKey($value)];
            }
        }

        foreach ($values as $value) {
            $cnic = Cnic::normalize($value);
            if ($cnic !== null && isset($byCnic[$cnic])) {
                return $byCnic[$cnic];
            }
        }

        return null;
    }

    private function outsideEmployment(?Employee $employee, string $date): ?string
    {
        if ($employee === null) {
            return null;
        }

        $joined = $employee->joining_date?->toDateString();
        if ($joined !== null && $date < $joined) {
            return 'Before the joining date ('.CarbonImmutable::parse($joined)->format('d M Y').').';
        }

        $departure = $employee->departure_date ?? $employee->leaving_date;
        if ($departure !== null && $departure !== '') {
            $left = CarbonImmutable::parse($departure)->toDateString();
            if ($date > $left) {
                return 'After the leaving date ('.CarbonImmutable::parse($left)->format('d M Y').').';
            }
        }

        return null;
    }

    /** Device user numbers compare without leading zeros ("0012" = "12"); Excel often drops them. */
    public static function deviceKey(string $value): string
    {
        $value = strtoupper(trim($value));

        return ctype_digit($value) ? (ltrim($value, '0') ?: '0') : $value;
    }

    public static function codeKey(string $value): string
    {
        return strtoupper(trim($value));
    }

    /**
     * The attendance models refuse writes into a finalised month by throwing
     * AttendanceMonthLockedException (AttendanceMonthLockService). Any other
     * "locked" refusal counts too; database lock timeouts / deadlocks do not.
     */
    private function isMonthLock(Throwable $exception): bool
    {
        if (is_a($exception, 'Webkul\\Employee\\Exceptions\\AttendanceMonthLockedException')) {
            return true;
        }

        if ($exception instanceof QueryException) {
            return false;
        }

        return str_contains(strtolower(class_basename($exception).' '.$exception->getMessage()), 'lock');
    }

    // ------------------------------------------------------------------
    // Rows <-> storage
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function workRow(ParsedPunchRow $row): array
    {
        return [
            'id'             => null,
            'row_number'     => $row->rowNumber,
            'identifier'     => $row->identifier,
            'employee_code'  => $row->employeeCode,
            'employee_name'  => $row->employeeName,
            'raw_datetime'   => $row->rawDateTime,
            'local_datetime' => $row->localDateTime,
            'raw_direction'  => $row->rawDirection,
            'direction'      => $row->direction,
            'raw'            => $row->cells,
            'parse_error'    => $row->error,
            'employee_id'    => null,
            'punched_at'     => null,
            'attendance_date'=> null,
            'status'         => RowStatus::Ok,
            'message'        => null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadRows(AttendanceImportBatch $batch): array
    {
        return AttendanceImportRow::query()
            ->where('batch_id', $batch->id)
            ->orderBy('row_number')
            ->get()
            ->map(fn (AttendanceImportRow $row): array => [
                'id'             => (int) $row->id,
                'row_number'     => (int) $row->row_number,
                'identifier'     => $row->identifier,
                'employee_code'  => $row->employee_code,
                'employee_name'  => $row->employee_name,
                'raw_datetime'   => $row->raw_datetime,
                'local_datetime' => $row->local_datetime,
                'raw_direction'  => $row->raw_direction,
                'direction'      => $row->direction,
                'raw'            => $row->raw ?? [],
                'parse_error'    => $row->local_datetime === null ? $row->message : null,
                'employee_id'    => null,
                'punched_at'     => null,
                'attendance_date'=> null,
                'status'         => RowStatus::Ok,
                'message'        => null,
            ])
            ->all();
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function insertRows(AttendanceImportBatch $batch, array $rows): void
    {
        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            AttendanceImportRow::query()->insert(array_map(
                fn (array $row): array => $this->rowAttributes($batch, $row, $now) + ['created_at' => $now],
                $chunk,
            ));
        }
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function persistRows(AttendanceImportBatch $batch, array $rows): void
    {
        $now = now();

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            AttendanceImportRow::query()->upsert(
                array_map(fn (array $row): array => ['id' => $row['id']] + $this->rowAttributes($batch, $row, $now) + ['created_at' => $now], $chunk),
                ['id'],
                ['employee_id', 'punched_at', 'attendance_date', 'status', 'message', 'updated_at'],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowAttributes(AttendanceImportBatch $batch, array $row, CarbonInterface $now): array
    {
        return [
            'batch_id'             => $batch->id,
            'company_id'           => $batch->company_id,
            'row_number'           => $row['row_number'],
            'identifier'           => $row['identifier'],
            'employee_code'        => $row['employee_code'],
            'employee_name'        => $row['employee_name'],
            'raw_datetime'         => $row['raw_datetime'],
            'local_datetime'       => $row['local_datetime'],
            'raw_direction'        => $row['raw_direction'],
            'direction'            => $row['direction'],
            'raw'                  => json_encode($row['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'employee_id'          => $row['employee_id'],
            'punched_at'           => $row['punched_at'] ? $this->storedTime($row['punched_at']) : null,
            'attendance_date'      => $row['attendance_date'],
            'status'               => $row['status']->value,
            'message'              => $row['message'] !== null ? mb_substr((string) $row['message'], 0, 255) : null,
            'punch_key'            => null,
            'attendance_record_id' => null,
            'updated_at'           => $now,
        ];
    }

    private function storedTime(CarbonImmutable $moment): string
    {
        return $moment->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------
    // Summary
    // ------------------------------------------------------------------

    /**
     * Counts per status and, per employee, the rows and days the file gives
     * (new days, days added to an earlier import, days that will need review
     * for a missing check-out, days left alone because of manual entries).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(AttendanceImportBatch $batch, array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = ($counts[$row['status']->value] ?? 0) + 1;
        }

        $good = array_filter($rows, fn (array $row): bool => in_array($row['status'], [RowStatus::Ok, RowStatus::Imported], true));
        $employeeIds = array_values(array_unique(array_filter(array_column($rows, 'employee_id'))));
        $employees = Employee::withTrashed()->whereIn('id', $employeeIds)->get(['id', 'name', 'employee_number'])->keyBy('id');
        $dates = array_values(array_filter(array_column($good, 'attendance_date')));

        // Punches of other imports on the same days (merging, and whether the day stays open).
        $existing = [];
        if ($good !== []) {
            AttendanceImportRow::query()
                ->livePunches()
                ->where('batch_id', '!=', $batch->id)
                ->whereIn('employee_id', array_unique(array_column($good, 'employee_id')))
                ->whereBetween('attendance_date', [min($dates), max($dates)])
                ->get(['employee_id', 'attendance_date', 'punched_at', 'direction'])
                ->each(function (AttendanceImportRow $punch) use (&$existing): void {
                    $existing[$punch->employee_id.'|'.$punch->attendance_date->toDateString()][] = [
                        'at'        => CarbonImmutable::instance($punch->punched_at)->utc(),
                        'direction' => $punch->direction,
                    ];
                });
        }

        $perEmployee = [];
        $byDay = [];
        foreach ($good as $row) {
            $byDay[$row['employee_id'].'|'.$row['attendance_date']][] = ['at' => $row['punched_at'], 'direction' => $row['direction']];
        }

        foreach ($byDay as $key => $punches) {
            [$employeeId] = explode('|', $key);
            $entry = &$perEmployee[(int) $employeeId];
            $entry ??= ['rows' => 0, 'days' => 0, 'new_days' => 0, 'merged_days' => 0, 'review_days' => 0, 'flagged_days' => 0, 'problem_rows' => 0];

            $earlier = $existing[$key] ?? [];
            $pairing = $this->pairer->pair([...$earlier, ...$punches], $batch->pairingMode());

            $entry['rows'] += count($punches);
            $entry['days']++;
            $entry[$earlier === [] ? 'new_days' : 'merged_days']++;
            $entry['review_days'] += $pairing['flags'] !== [] ? 1 : 0;
            unset($entry);
        }

        $flaggedDays = [];
        foreach ($rows as $row) {
            if ($row['employee_id'] === null) {
                continue;
            }

            $entry = &$perEmployee[(int) $row['employee_id']];
            $entry ??= ['rows' => 0, 'days' => 0, 'new_days' => 0, 'merged_days' => 0, 'review_days' => 0, 'flagged_days' => 0, 'problem_rows' => 0];
            if ($row['status']->isProblem()) {
                $entry['problem_rows']++;
            }
            if ($row['status'] === RowStatus::ManualDay && ! isset($flaggedDays[$row['employee_id'].'|'.$row['attendance_date']])) {
                $flaggedDays[$row['employee_id'].'|'.$row['attendance_date']] = true;
                $entry['flagged_days']++;
            }
            unset($entry);
        }

        $list = [];
        foreach ($perEmployee as $employeeId => $entry) {
            $employee = $employees->get($employeeId);
            $list[] = ['employee_id' => $employeeId, 'name' => $employee?->name ?? '#'.$employeeId, 'code' => $employee?->employee_number] + $entry;
        }
        usort($list, fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return [
            'counts'       => $counts,
            'date_from'    => $dates === [] ? null : min($dates),
            'date_to'      => $dates === [] ? null : max($dates),
            'days'         => count($byDay),
            'review_days'  => array_sum(array_column($list, 'review_days')),
            'flagged_days' => count($flaggedDays),
            'employees'    => $list,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @return array<string, int>
     */
    private function countColumns(array $rows, array $summary): array
    {
        $counts = $summary['counts'] ?? [];
        $problems = 0;
        foreach ($rows as $row) {
            $problems += $row['status']->isProblem() ? 1 : 0;
        }

        return [
            'total_rows'     => count($rows),
            'imported_rows'  => (int) ($counts[RowStatus::Imported->value] ?? 0),
            'duplicate_rows' => (int) ($counts[RowStatus::Duplicate->value] ?? 0),
            'problem_rows'   => $problems,
            'review_days'    => (int) ($summary['review_days'] ?? 0),
            'flagged_days'   => (int) ($summary['flagged_days'] ?? 0),
        ];
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, int $companyId): void
    {
        if (! self::canManage($user, $companyId)) {
            throw new AuthorizationException('Only HR of this company can import device attendance.');
        }
    }
}
