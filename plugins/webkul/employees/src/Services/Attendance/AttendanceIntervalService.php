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
 * moves only the first interval's start / the last interval's end, so the
 * breaks between pairs stay breaks; see applyDirectEdit()).
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
            throw new RuntimeException('This day already has a check-in without a check-out. Add the check-out first.');
        }

        $last = $intervals->last();
        if ($last && $last->check_out && Carbon::instance($at)->lessThan($last->check_out)) {
            throw new InvalidArgumentException('A new check-in must be later than the previous check-out.');
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
        $open = $this->openInterval($record) ?? throw new RuntimeException('There is no check-in waiting for a check-out.');

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
     * After a direct edit of the day's check_in / check_out (HR edit, HR
     * correction, approved correction request, import), only the interval the
     * edit targets changes, so the breaks between pairs stay breaks:
     *
     *  - a new check-in moves the first interval's start (pairs that ended
     *    before it are voided);
     *  - a new check-out moves the last interval's end, closing it when it was
     *    open (pairs that start after it are voided); clearing the check-out
     *    re-opens the last interval.
     *
     * The day's intervals are voided and replaced by the one edited pair only
     * when the day is created, has no intervals yet, the edit leaves no valid
     * interval, or the writer explicitly replaces the whole day
     * (AttendanceRecord::$replaceIntervalsOnEdit, e.g. an approved missed-day
     * request on a day that already has a record). Voided intervals are kept
     * for audit. Returns the intervals as they were before.
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

        // Runs inside the record's created / updated event, before Eloquent syncs its
        // originals: mark the just-written values as stored, so syncSummary() saves the
        // interval sum even when it equals the value from before the edit.
        $record->syncOriginalAttributes(array_values(array_intersect(['check_in', 'check_out', 'worked_hours'], array_keys($record->getAttributes()))));

        if (! $created && ! AttendanceRecord::$replaceIntervalsOnEdit && $intervals->isNotEmpty() && $record->check_in !== null
            && $this->editTargetedIntervals($record, $intervals, $reason)) {
            $this->syncSummary($record);

            return $before;
        }

        $last = $intervals->last();
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
     * The targeted part of applyDirectEdit(): moves the first interval's
     * start to the day's new check-in and the last interval's end to its new
     * check-out, voiding the pairs the new times leave outside the day. False
     * (nothing written) when no valid interval would be left.
     *
     * @param  Collection<int, AttendanceInterval>  $intervals  the day's active intervals, in check-in order
     */
    private function editTargetedIntervals(AttendanceRecord $record, Collection $intervals, ?string $reason): bool
    {
        $checkIn = $record->check_in;
        $checkOut = $record->check_out;
        $checkInChanged = $record->wasChanged('check_in');
        $checkOutChanged = $record->wasChanged('check_out');

        $kept = $intervals->values();
        $dropped = collect();

        if ($checkInChanged) {
            // Pairs that ended at or before the new check-in are no longer part of the day.
            [$before, $kept] = $kept->partition(fn (AttendanceInterval $interval): bool => $interval->check_out !== null && $interval->check_out->lessThanOrEqualTo($checkIn));
            $dropped = $dropped->merge($before);
        }

        if ($checkOutChanged && $checkOut !== null) {
            // Pairs that start at or after the new check-out are no longer part of the day.
            [$after, $kept] = $kept->partition(fn (AttendanceInterval $interval): bool => $interval->check_in->greaterThanOrEqualTo($checkOut));
            $dropped = $dropped->merge($after);
        }

        $kept = $kept->values();
        if ($kept->isEmpty() || $kept->slice(0, -1)->contains(fn (AttendanceInterval $interval): bool => $interval->isOpen())) {
            return false;
        }

        $first = $kept->first();
        $last = $kept->last();

        // Validated before anything is changed: every kept pair must still end after it starts.
        $firstStart = $checkInChanged ? $checkIn : $first->check_in;
        $lastEnd = $checkOutChanged ? $checkOut : $last->check_out;
        $firstEnd = $first->is($last) ? $lastEnd : $first->check_out;
        $lastStart = $first->is($last) ? $firstStart : $last->check_in;
        if (($firstEnd !== null && $firstEnd->lessThanOrEqualTo($firstStart))
            || ($lastEnd !== null && $lastEnd->lessThanOrEqualTo($lastStart))) {
            return false;
        }

        if ($checkInChanged) {
            $first->check_in = $checkIn;
        }
        if ($checkOutChanged) {
            // A cleared check-out re-opens the last pair.
            $last->check_out = $checkOut;
        }

        $now = now();
        foreach ($dropped as $interval) {
            $interval->update([
                'voided_at'   => $now,
                'voided_by'   => Auth::id(),
                'void_reason' => mb_substr($reason ?: 'Outside the day after an edit of its check-in / check-out', 0, 255),
            ]);
        }

        foreach ([$first, $last] as $interval) {
            if ($interval->isDirty()) {
                $interval->save();
            }
        }

        return true;
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
