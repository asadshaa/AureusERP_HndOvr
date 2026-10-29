<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Webkul\Employee\Enums\PunchPairingMode;

/**
 * Turns one employee-day of device punches into check-in / check-out pairs.
 * The day is the attendance date, not the calendar date: the import gives
 * an overnight shift's after-midnight punches the date the shift started
 * (DeviceAttendanceImportService::assignShiftDates()), so a 22:00-06:00
 * shift pairs as one interval instead of two half days.
 * A missing check-out is never made up: an odd punch leaves its pair open
 * and the day is flagged "missing_check_out" for HR review.
 */
class PunchPairer
{
    /**
     * @param  array<int, array{at: CarbonImmutable, direction: ?string}>  $punches  one employee-day, any order
     * @return array{pairs: array<int, array{0: CarbonImmutable, 1: ?CarbonImmutable}>, flags: array<int, string>}
     */
    public function pair(array $punches, PunchPairingMode $mode): array
    {
        usort($punches, fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        // The same instant twice is one punch.
        $unique = [];
        foreach ($punches as $punch) {
            $key = $punch['at']->getTimestamp();
            $unique[$key] ??= $punch;
        }
        $punches = array_values($unique);

        if ($punches === []) {
            return ['pairs' => [], 'flags' => []];
        }

        if (count($punches) === 1) {
            return ['pairs' => [[$punches[0]['at'], null]], 'flags' => ['missing_check_out']];
        }

        if ($mode === PunchPairingMode::FirstInLastOut) {
            // Device in/out keys are often pressed wrongly, so this mode ignores them.
            return ['pairs' => [[$punches[0]['at'], $punches[count($punches) - 1]['at']]], 'flags' => []];
        }

        $pairs = [];
        $flags = [];
        $directed = ! in_array(null, array_column($punches, 'direction'), true);

        foreach (array_chunk($punches, 2) as $chunk) {
            [$in, $out] = [$chunk[0], $chunk[1] ?? null];
            $pairs[] = [$in['at'], $out['at'] ?? null];

            if ($directed && ($in['direction'] !== 'in' || ($out !== null && $out['direction'] !== 'out'))) {
                $flags['direction_mismatch'] = 'direction_mismatch';
            }
        }

        if (count($punches) % 2 === 1) {
            $flags['missing_check_out'] = 'missing_check_out';
        }

        return ['pairs' => $pairs, 'flags' => array_values($flags)];
    }

    /** Human wording of a flag for the review queue and the report. */
    public static function describeFlag(string $flag): string
    {
        return match ($flag) {
            'missing_check_out'  => 'odd number of punches: the last check-out is missing',
            'direction_mismatch' => 'the device in/out states do not match the pairing',
            default              => str_replace('_', ' ', $flag),
        };
    }
}
