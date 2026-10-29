<?php

namespace Webkul\Employee\Enums;

/**
 * How the punches of one employee-day from a device file become check-in /
 * check-out pairs (a company setting, employees_attendance_policies).
 */
enum PunchPairingMode: string
{
    /** First punch of the day is the check-in, last punch the check-out. */
    case FirstInLastOut = 'first_last';

    /** Every punch paired in time order: 1st in, 2nd out, 3rd in, ... */
    case Sequential = 'paired';

    public function label(): string
    {
        return match ($this) {
            self::FirstInLastOut => 'First in / last out',
            self::Sequential     => 'All punches paired in order',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FirstInLastOut => 'The first punch of the day is the check-in and the last one the check-out. Punches in between are kept but not paired.',
            self::Sequential     => 'Punches are paired in time order (in, out, in, out). Gaps between the pairs do not count as worked time.',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
