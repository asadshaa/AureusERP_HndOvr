<?php

namespace Webkul\Employee\Enums;

/**
 * What a device-file import made of one row. Ok is the preview verdict;
 * Imported / Undone are written by the import and the undo.
 */
enum DeviceImportRowStatus: string
{
    case Ok = 'ok';

    case Imported = 'imported';

    case UnknownEmployee = 'unknown_employee';

    case Duplicate = 'duplicate';

    case OutsideEmployment = 'outside_employment';

    case BadDate = 'bad_date';

    case ManualDay = 'manual_day';

    case LockedMonth = 'locked_month';

    case Error = 'error';

    case Undone = 'undone';

    public function label(): string
    {
        return match ($this) {
            self::Ok                => 'OK',
            self::Imported          => 'Imported',
            self::UnknownEmployee   => 'Unknown employee',
            self::Duplicate         => 'Duplicate',
            self::OutsideEmployment => 'Outside employment dates',
            self::BadDate           => 'Bad date',
            self::ManualDay         => 'Day already has manual entries',
            self::LockedMonth       => 'Locked month',
            self::Error             => 'Error',
            self::Undone            => 'Undone',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok, self::Imported         => 'success',
            self::Duplicate, self::Undone    => 'gray',
            self::ManualDay                  => 'warning',
            self::LockedMonth                => 'info',
            default                          => 'danger',
        };
    }

    /** Rows that need HR's attention in the report (not OK, not a harmless duplicate). */
    public function isProblem(): bool
    {
        return ! in_array($this, [self::Ok, self::Imported, self::Duplicate, self::Undone], true);
    }
}
