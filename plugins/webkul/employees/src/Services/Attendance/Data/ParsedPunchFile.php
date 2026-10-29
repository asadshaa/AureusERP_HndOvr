<?php

namespace Webkul\Employee\Services\Attendance\Data;

/** A device file read by DevicePunchFileParser. */
final readonly class ParsedPunchFile
{
    /**
     * @param  string  $format  "template", "zkteco" or "zkteco_attlog" (headerless)
     * @param  string  $dateOrder  "dmy" or "mdy": how d/m/y-style dates were read
     * @param  array<string, string>  $columns  role (identifier, code, name, datetime, date, time, direction) => header as in the file
     * @param  array<int, ParsedPunchRow>  $rows
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public string $format,
        public string $dateOrder,
        public array $columns,
        public array $rows,
        public array $warnings = [],
    ) {}
}
