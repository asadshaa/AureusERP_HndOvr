<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendanceRecord;

/**
 * Keeps the check-in / check-out intervals of a day and the day summary
 * (employees_attendance_records) in step:
 *
 *  - check_in      = the first interval's check-in;
 *  - check_out     = the last interval's check-out, or NULL while an interval
 *                    is still open (so "open shift" queries keep working);
 *  - worked_hours  = the SUM of the closed intervals (never first-in to
 *                    last-out: the gaps between pairs are not work).
 *
 * Writers: GeofencedAttendanceService (check-in opens a new interval when
 * the day's last one is closed; check-out closes the open one) and the
 * AttendanceRecord model hook (any direct edit of check_in / check_out --
 * Filament edit, HR correction, approved time-change request, import --
 * replaces the day's intervals with that one pair, voiding the old ones).
 */
class AttendanceIntervalService
{
    private static ?bool $tableExists = null;

    public static function available(): bool
    {
        return self::$tableExists ??= Schema::hasTable('employees_attendance_intervals');
    }

    /** @return Collection<int, AttendanceInterval> active intervals in check-in order */
    public function intervalsFor(AttendanceRecord $record): Collection
    {
        if (! self::available() || ! $record->exists) {
            return collect();
        }

        $this->ensureBackfilled($record);

        return AttendanceInterval::query()
            ->where('attendance_record_id', $record->id)
            ->active()
            ->orderBy('check_in')
            ->orderBy('id')
            ->get();
    }

    /**
     * Active intervals of many days at once (for reports and the Today page).
     *
     * @param  array<int, int>  $recordIds
     * @return Collection<int, Collection<int, AttendanceInterval>> keyed by attendance_record_id
     */
    public function intervalsForRecords(array $recordIds): Collection
    {
        if (! self::available() || $recordIds === []) {
            return collect();
        }

        return AttendanceInterval::query()
            ->whereIn('attendance_record_id', $recordIds)
            ->active()
            ->orderBy('check_in')
            ->orderBy('id')
            ->get(['id', 'attendance_record_id', 'check_in', 'check_out'])
            ->groupBy('attendance_record_id');
    }

    public function openInterval(AttendanceRecord $record): ?AttendanceInterval
    {
        return $this->intervalsFor($record)->first(fn (AttendanceInterval $interval): bool => $interval->isOpen());
    }

    /** Opens a new interval on the day; refused while another interval is open or when it would overlap. */
    public function open(AttendanceRecord $record, CarbonInterface $at, string $source, ?int $verificationId = null, ?string $verificationStatus = null): AttendanceInterval
    {
        // The day's first check-in is opened as-is; a later one first backfills a day written without intervals.
        $isFirstCheckIn = $record->check_in !== null && $record->check_in->equalTo($at)
            && ! AttendanceInterval::query()->where('attendance_record_id', $record->id)->active()->exists();
        $intervals = $isFirstCheckIn ? collect() : $this->intervalsFor($record);

        if ($intervals->contains(fn (AttendanceInterval $interval): bool => $interval->isOpen())) {
            throw new RuntimeException('This day already has an open check-in.');
        }

        $last = $intervals->last();
        if ($last && $last->check_out && Carbon::instance($at)->lessThan($last->check_out)) {
            throw new InvalidArgumentException('A new check-in cannot be earlier than the previous check-out.');
        }

        return AttendanceInterval::query()->create([
            'company_id'            => $record->company_id,
            'employee_id'           => $record->employee_id,
            'attendance_record_id'  => $record->id,
            'check_in'              => $at,
            'source'                => $source,
            'start_verification_id' => $verificationId,
            'verification_status'   => $verificationStatus,
        ]);
    }

    /** Closes the day's open interval. */
    public function close(AttendanceRecord $record, CarbonInterface $at, ?int $verificationId = null, ?string $verificationStatus = null): AttendanceInterval
    {
        $open = $this->openInterval($record) ?? throw new RuntimeException('There is no open check-in to close.');

        $open->fill([
            'check_out'           => $at,
            'end_verification_id' => $verificationId,
            'verification_status' => $verificationStatus ?? $open->verification_status,
        ])->save();

        return $open;
    }

    /** Minutes of the closed intervals (what counts as worked). */
    public function workedMinutes(AttendanceRecord $record): int
    {
        return (int) $this->intervalsFor($record)->sum(fn (AttendanceInterval $interval): int => $interval->durationMinutes());
    }

    /**
     * Rewrites the day summary from the intervals. $extra is written in the
     * same save (status, verification ids, ...). The write never re-triggers
     * the interval replacement or the correction audit hook.
     *
     * @param  array<string, mixed>  $extra
     */
    public function syncSummary(AttendanceRecord $record, array $extra = []): AttendanceRecord
    {
        $intervals = $this->intervalsFor($record);

        if ($intervals->isNotEmpty()) {
            $hasOpen = $intervals->contains(fn (AttendanceInterval $interval): bool => $interval->isOpen());

            $record->check_in = $intervals->min('check_in');
            $record->check_out = $hasOpen ? null : $intervals->max('check_out');
            $record->worked_hours = round($intervals->sum(fn (AttendanceInterval $interval): int => $interval->durationMinutes()) / 60, 4);
        }

        $record->fill($extra);

        self::quietly(fn () => $record->isDirty() ? $record->save() : null);

        return $record;
    }

    /**
     * After a direct edit of the day's check_in / check_out: when only the
     * check-out was set and the last interval is open, that interval is
     * closed (a forgotten check-out on a multi-pair day keeps its earlier
     * pairs). Otherwise the day's intervals are voided and replaced by the
     * one edited pair. Returns the intervals as they were before.
     *
     * @return array<int, array{check_in: ?string, check_out: ?string}>
     */
    public function applyDirectEdit(AttendanceRecord $record, bool $created = false, ?string $reason = null): array
    {
        if (! self::available()) {
            return [];
        }

        $intervals = AttendanceInterval::query()
            ->where('attendance_record_id', $record->id)
            ->active()
            ->orderBy('check_in')
            ->orderBy('id')
            ->get();
        $before = self::describeRows($intervals);

        $onlyCheckOut = ! $created && $record->wasChanged('check_out') && ! $record->wasChanged('check_in');
        $last = $intervals->last();

        if ($onlyCheckOut && $last && $last->isOpen() && $record->check_out && $record->check_out->greaterThan($last->check_in)) {
            $last->update(['check_out' => $record->check_out]);
            $this->syncSummary($record);

            return $before;
        }

        $sameSingle = $intervals->count() === 1
            && $record->check_in !== null
            && $last->check_in->equalTo($record->check_in)
            && (($last->check_out === null && $record->check_out === null) || ($last->check_out && $record->check_out && $last->check_out->equalTo($record->check_out)));

        if (! $sameSingle) {
            $now = now();
            foreach ($intervals as $interval) {
                $interval->update([
                    'voided_at'   => $now,
                    'voided_by'   => Auth::id(),
                    'void_reason' => mb_substr($reason ?: 'Replaced by an edit of the day\'s check-in / check-out', 0, 255),
                ]);
            }

            if ($record->check_in !== null) {
                AttendanceInterval::query()->create([
                    'company_id'            => $record->company_id,
                    'employee_id'           => $record->employee_id,
                    'attendance_record_id'  => $record->id,
                    'check_in'              => $record->check_in,
                    'check_out'             => $record->check_out && $record->check_out->greaterThan($record->check_in) ? $record->check_out : null,
                    'source'                => (string) ($record->source ?: 'manual'),
                    'start_verification_id' => $record->check_in_verification_id,
                    'end_verification_id'   => $record->check_out_verification_id,
                    'verification_status'   => $record->verification_status,
                ]);
            }
        }

        $this->syncSummary($record);

        return $before;
    }

    /**
     * "10:00–12:00, 14:30–18:00" in the given time zone; an open interval
     * shows "10:00–…".
     *
     * @param  iterable<AttendanceInterval|array{0: ?CarbonInterface, 1: ?CarbonInterface}>  $intervals
     */
    public static function describe(iterable $intervals, string $timezone): string
    {
        $parts = [];
        foreach ($intervals as $interval) {
            [$in, $out] = $interval instanceof AttendanceInterval ? [$interval->check_in, $interval->check_out] : $interval;
            if (! $in) {
                continue;
            }
            $parts[] = Carbon::instance($in)->setTimezone($timezone)->format('H:i').'–'.($out ? Carbon::instance($out)->setTimezone($timezone)->format('H:i') : '…');
        }

        return implode(', ', $parts);
    }

    /**
     * Creates the single interval of a day written before intervals existed
     * (or by a raw query that bypassed the model).
     */
    private function ensureBackfilled(AttendanceRecord $record): void
    {
        if ($record->check_in === null) {
            return;
        }

        $exists = AttendanceInterval::query()->where('attendance_record_id', $record->id)->exists();
        if ($exists) {
            return;
        }

        AttendanceInterval::query()->create([
            'company_id'            => $record->company_id,
            'employee_id'           => $record->employee_id,
            'attendance_record_id'  => $record->id,
            'check_in'              => $record->check_in,
            'check_out'             => $record->check_out && $record->check_out->greaterThan($record->check_in) ? $record->check_out : null,
            'source'                => 'system_backfill',
            'start_verification_id' => $record->check_in_verification_id,
            'end_verification_id'   => $record->check_out_verification_id,
            'verification_status'   => $record->verification_status,
        ]);
    }

    /**
     * @param  Collection<int, AttendanceInterval>  $intervals
     * @return array<int, array{check_in: ?string, check_out: ?string}>
     */
    private static function describeRows(Collection $intervals): array
    {
        return $intervals->map(fn (AttendanceInterval $interval): array => [
            'check_in'  => $interval->check_in?->toDateTimeString(),
            'check_out' => $interval->check_out?->toDateTimeString(),
        ])->values()->all();
    }

    /** Runs a record write with the interval-replacement and correction-audit hooks switched off. */
    public static function quietly(callable $callback): mixed
    {
        $previousSync = AttendanceRecord::$syncingIntervals;
        $previousAudit = AttendanceRecord::$suppressCorrectionAudit;
        AttendanceRecord::$syncingIntervals = true;
        AttendanceRecord::$suppressCorrectionAudit = true;

        try {
            return $callback();
        } finally {
            AttendanceRecord::$syncingIntervals = $previousSync;
            AttendanceRecord::$suppressCorrectionAudit = $previousAudit;
        }
    }
}
