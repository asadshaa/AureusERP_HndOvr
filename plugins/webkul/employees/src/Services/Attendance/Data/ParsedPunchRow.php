<?php

namespace Webkul\Employee\Services\Attendance\Data;

/** One data row of a device file, as read. Nothing here is matched or validated against the database yet. */
final readonly class ParsedPunchRow
{
    /**
     * @param  int  $rowNumber  the row's number in the file / sheet (1-based, header included)
     * @param  ?string  $localDateTime  "Y-m-d H:i:s" device wall-clock time, or null when unreadable
     * @param  ?string  $direction  "in", "out" or null when the file has none / it is not recognised
     * @param  array<string, string>  $cells  header => original cell text
     */
    public function __construct(
        public int $rowNumber,
        public ?string $identifier,
        public ?string $employeeCode,
        public ?string $employeeName,
        public ?string $rawDateTime,
        public ?string $localDateTime,
        public ?string $rawDirection,
        public ?string $direction,
        public array $cells,
        public ?string $error = null,
    ) {}
}
