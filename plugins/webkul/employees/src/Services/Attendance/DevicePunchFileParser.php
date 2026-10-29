<?php

namespace Webkul\Employee\Services\Attendance;

use DateTimeInterface;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;
use Webkul\Employee\Services\Attendance\Data\ParsedPunchFile;
use Webkul\Employee\Services\Attendance\Data\ParsedPunchRow;

/**
 * Reads a biometric / device attendance file (CSV, TXT, XLSX, XLS) into
 * rows of identifier + device wall-clock date-time + optional direction.
 *
 * Understands the Aureus template ("Employee Code / Biometric ID", "Date
 * Time", "Direction") and common ZKTeco-style exports: "AC-No." / "No." /
 * "User ID" / "Enroll No.", "Name", "Time" / "Date Time" / "Date" + "Time" /
 * "CHECKTIME", "State" / "Check Type" (C/In, C/Out, I, O, 0, 1 ...), with
 * title rows above the header, and the headerless attlog layout (user id,
 * date-time, verify, state). Headers are matched case-, space- and
 * punctuation-insensitively. Nothing is matched against employees here.
 */
class DevicePunchFileParser
{
    public const MAX_ROWS = 50000;

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    private const HEADER_SCAN_ROWS = 20;

    /** Device user number columns, most specific first. */
    private const IDENTIFIER_HEADERS = [
        'employeecodebiometricid', 'biometricid', 'biometricno', 'biometricnumber', 'deviceuserid', 'deviceid',
        'acno', 'enrollno', 'enrollnumber', 'enrollid', 'enno', 'userid', 'userno', 'badgenumber', 'badgeno',
        'badgeid', 'personid', 'personno', 'no', 'id',
    ];

    /** Employee code columns (matched against employee_number), most specific first. */
    private const CODE_HEADERS = [
        'employeecode', 'empcode', 'staffcode', 'employeeno', 'employeenumber', 'empno', 'employeeid', 'empid',
        'staffid', 'staffno', 'payrollno', 'payrollid', 'personnelno', 'code',
    ];

    private const DATETIME_HEADERS = [
        'datetime', 'dateandtime', 'checktime', 'checkinout', 'punchtime', 'punchdatetime', 'logtime',
        'logdatetime', 'timestamp', 'attendancetime', 'recordtime', 'verifytime', 'clocktime', 'clockingtime',
    ];

    private const DATE_HEADERS = ['date', 'punchdate', 'logdate', 'attendancedate', 'checkdate', 'recorddate'];

    private const TIME_HEADERS = ['time', 'clock'];

    private const DIRECTION_HEADERS = [
        'direction', 'checktype', 'state', 'inout', 'io', 'punchstate', 'punchtype', 'attstate', 'inoutmode',
        'inoutstatus', 'type', 'status',
    ];

    private const NAME_HEADERS = ['name', 'employeename', 'empname', 'username', 'fullname', 'staffname'];

    private const ZKTECO_MARKERS = [
        'acno', 'enrollno', 'enrollnumber', 'enno', 'checktype', 'checktime', 'badgenumber', 'verifycode',
        'verifymode', 'workcode', 'machineno', 'deviceno', 'state', 'userid',
    ];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const TIME_PATTERN = '(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?\s*([AaPp]\.?[Mm]\.?)?';

    /**
     * @param  string  $dateOrder  "auto", "dmy" or "mdy" (how 01/10/2026-style dates are read)
     *
     * @throws InvalidArgumentException when the file cannot be read or has no usable columns
     */
    public function parse(string $path, ?string $originalName = null, string $dateOrder = 'auto'): ParsedPunchFile
    {
        $extension = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('Upload a CSV or Excel file (.csv, .txt, .xlsx or .xls).');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The uploaded file could not be read.');
        }

        if (filesize($path) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The file is larger than 10 MB. Export a shorter date range.');
        }

        return $this->parseTable($this->readTable($path, $extension), $dateOrder);
    }

    /**
     * @param  array<int, array<int, mixed>>  $table  rows of cells; index 0 is the file's first row
     *
     * @throws InvalidArgumentException
     */
    public function parseTable(array $table, string $dateOrder = 'auto'): ParsedPunchFile
    {
        $warnings = [];
        $header = $this->detectHeader($table);

        if ($header !== null) {
            [$headerIndex, $columns, $normalized] = $header;
            $start = $headerIndex + 1;
            $format = array_intersect(self::ZKTECO_MARKERS, $normalized) !== [] ? 'zkteco' : 'template';
        } else {
            $columns = $this->detectHeaderless($table, $dateOrder);
            if ($columns === null) {
                throw new InvalidArgumentException('No employee and date / time columns were found. Use the template, or a device export with headers such as "AC-No." and "Time".');
            }
            $start = 0;
            $format = 'zkteco_attlog';
        }

        $order = $this->resolveDateOrder($table, $start, $columns, $dateOrder, $warnings);

        $rows = [];
        $count = count($table);
        for ($index = $start; $index < $count; $index++) {
            $cells = array_values((array) $table[$index]);
            if ($this->isBlank($cells)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw new InvalidArgumentException('The file has more than '.number_format(self::MAX_ROWS).' rows. Export a shorter date range.');
            }

            $rows[] = $this->readRow($index + 1, $cells, $columns, $order);
        }

        if ($rows === []) {
            throw new InvalidArgumentException('The file has no punch rows under its header.');
        }

        return new ParsedPunchFile(
            $format,
            $order,
            array_map(fn (array $column): string => $column['header'], $columns),
            $rows,
            $warnings,
        );
    }

    /** "in", "out" or null for a device state / direction value (C/In, C/Out, I, O, 0, 1, Out Back, OT-In ...). */
    public static function normalizeDirection(?string $value): ?string
    {
        $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $value));

        if ($key === '') {
            return null;
        }

        if (in_array($key, ['outback', 'backin', 'breakin', 'returnin', '3', '4'], true)) {
            return 'in';
        }

        if (in_array($key, ['o', 'out', 'cout', 'checkout', 'clockout', 'signout', 'exit', '1', '2', '5'], true) || str_ends_with($key, 'out')) {
            return 'out';
        }

        if (in_array($key, ['i', 'c', 'in', 'cin', 'checkin', 'clockin', 'signin', 'entry', '0'], true) || str_ends_with($key, 'in')) {
            return 'in';
        }

        return null;
    }

    /** Lower-case letters and digits only: "AC-No." -> "acno", "Date/Time" -> "datetime". */
    public static function normalizeHeader(mixed $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', self::cellText($value)));
    }

    public static function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return floor($value) === $value && abs($value) < 1e15 ? number_format($value, 0, '.', '') : (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $text = (string) preg_replace('/[\x{FEFF}\x{200B}\x{00A0}]/u', ' ', (string) $value);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return ltrim($text, "'");
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** @return array<int, array<int, mixed>> */
    private function readTable(string $path, string $extension): array
    {
        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->readDelimited($path);
        }

        try {
            // Device software often names a tab-separated or HTML export ".xls".
            $type = IOFactory::identify($path);
        } catch (Throwable) {
            throw new InvalidArgumentException('The Excel file could not be read. Save it as .xlsx or .csv and try again.');
        }

        if ($type === 'Csv') {
            return $this->readDelimited($path);
        }

        try {
            $reader = IOFactory::createReader($type);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getSheet(0);

            if ($sheet->getHighestDataRow() > self::MAX_ROWS + self::HEADER_SCAN_ROWS) {
                $spreadsheet->disconnectWorksheets();

                throw new InvalidArgumentException('The file has more than '.number_format(self::MAX_ROWS).' rows. Export a shorter date range.');
            }

            // Raw values: dates stay Excel serial numbers, read below.
            $table = $sheet->toArray(null, true, false, false);
            $spreadsheet->disconnectWorksheets();

            return $table;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidArgumentException('The Excel file could not be read. Save it as .xlsx or .csv and try again.');
        }
    }

    /** @return array<int, array<int, mixed>> */
    private function readDelimited(string $path): array
    {
        $content = (string) file_get_contents($path);

        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        } elseif (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $delimiter = $this->detectDelimiter($content);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $table = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $table[] = $row;

            if (count($table) > self::MAX_ROWS + self::HEADER_SCAN_ROWS) {
                fclose($stream);

                throw new InvalidArgumentException('The file has more than '.number_format(self::MAX_ROWS).' rows. Export a shorter date range.');
            }
        }
        fclose($stream);

        return $table;
    }

    private function detectDelimiter(string $content): string
    {
        $lines = array_slice(array_values(array_filter(explode("\n", $content), fn (string $line): bool => trim($line) !== '')), 0, 10);
        $best = ',';
        $bestCount = 0;

        foreach (["\t", ';', ',', '|'] as $delimiter) {
            $total = array_sum(array_map(fn (string $line): int => substr_count($line, $delimiter), $lines));
            if ($total > $bestCount) {
                $best = $delimiter;
                $bestCount = $total;
            }
        }

        return $best;
    }

    // ------------------------------------------------------------------
    // Columns
    // ------------------------------------------------------------------

    /**
     * The first row (within the first rows of the file) that names an
     * employee column and a date / time column.
     *
     * @param  array<int, array<int, mixed>>  $table
     * @return array{0: int, 1: array<string, array{index: int, header: string}>, 2: array<int, string>}|null
     */
    private function detectHeader(array $table): ?array
    {
        $limit = min(self::HEADER_SCAN_ROWS, count($table));

        for ($index = 0; $index < $limit; $index++) {
            $cells = array_values((array) $table[$index]);
            $positions = [];
            $labels = [];
            foreach ($cells as $position => $cell) {
                $key = self::normalizeHeader($cell);
                if ($key !== '' && ! isset($positions[$key])) {
                    $positions[$key] = $position;
                    $labels[$key] = self::cellText($cell);
                }
            }

            $pick = function (array $aliases, array $exclude = []) use ($positions, $labels): ?array {
                foreach ($aliases as $alias) {
                    if (isset($positions[$alias]) && ! in_array($positions[$alias], $exclude, true)) {
                        return ['index' => $positions[$alias], 'header' => $labels[$alias]];
                    }
                }

                return null;
            };

            $columns = [];
            $columns['identifier'] = $pick(self::IDENTIFIER_HEADERS);
            $taken = $columns['identifier'] ? [$columns['identifier']['index']] : [];
            // ZKTeco "AC-No." + "No.": AC-No. is the device user number, No. the number kept in the device software.
            $columns['code'] = $pick(self::CODE_HEADERS, $taken) ?? $pick(['no'], $taken);

            if ($columns['identifier'] === null && $columns['code'] === null) {
                continue;
            }

            $columns['datetime'] = $pick(self::DATETIME_HEADERS);
            $columns['date'] = $pick(self::DATE_HEADERS);
            $columns['time'] = $pick(self::TIME_HEADERS);

            if ($columns['datetime'] === null && $columns['date'] === null && $columns['time'] === null) {
                continue;
            }

            $used = array_values(array_filter(array_map(fn (?array $column): ?int => $column['index'] ?? null, $columns), fn (?int $position): bool => $position !== null));
            $columns['name'] = $pick(self::NAME_HEADERS, $used);
            $columns['direction'] = $pick(self::DIRECTION_HEADERS, $used);

            return [$index, array_filter($columns), array_keys($positions)];
        }

        return null;
    }

    /**
     * A file without a header (ZKTeco attlog.dat): user id, date-time,
     * verify mode, state, ... when the second cell of the first rows reads as
     * a date-time.
     *
     * @param  array<int, array<int, mixed>>  $table
     * @return array<string, array{index: int, header: string}>|null
     */
    private function detectHeaderless(array $table, string $dateOrder): ?array
    {
        $sample = array_slice(array_values(array_filter($table, fn ($row): bool => ! $this->isBlank(array_values((array) $row)))), 0, 5);
        if ($sample === []) {
            return null;
        }

        $order = $dateOrder === 'mdy' ? 'mdy' : 'dmy';
        foreach ($sample as $row) {
            $cells = array_values((array) $row);
            if (count($cells) < 2 || self::cellText($cells[0]) === '' || $this->parseDateTime($cells[1], $order) === null) {
                return null;
            }
        }

        $columns = [
            'identifier' => ['index' => 0, 'header' => 'Column 1 (user id)'],
            'datetime'   => ['index' => 1, 'header' => 'Column 2 (date-time)'],
        ];

        if (count(array_values((array) $sample[0])) >= 4) {
            $columns['direction'] = ['index' => 3, 'header' => 'Column 4 (state)'];
        }

        return $columns;
    }

    /**
     * @param  array<int, array<int, mixed>>  $table
     * @param  array<string, array{index: int, header: string}>  $columns
     * @param  array<int, string>  $warnings
     */
    private function resolveDateOrder(array $table, int $start, array $columns, string $requested, array &$warnings): string
    {
        if (in_array($requested, ['dmy', 'mdy'], true)) {
            return $requested;
        }

        $position = ($columns['datetime'] ?? $columns['date'] ?? $columns['time'] ?? null)['index'] ?? null;
        if ($position === null) {
            return 'dmy';
        }

        $dayFirst = 0;
        $monthFirst = 0;
        $count = count($table);
        for ($index = $start; $index < $count; $index++) {
            $cell = $table[$index][$position] ?? null;
            if (! is_string($cell) || ! preg_match('/^\s*(\d{1,2})[\/.\-](\d{1,2})[\/.\-]\d{2,4}/', $cell, $match)) {
                continue;
            }

            if ((int) $match[1] > 12) {
                $dayFirst++;
            } elseif ((int) $match[2] > 12) {
                $monthFirst++;
            }
        }

        if ($dayFirst > 0 && $monthFirst > 0) {
            $warnings[] = 'Some dates look like day/month/year and others like month/day/year. They were read as day/month/year; check the bad-date rows, or choose the date order and preview again.';
        }

        return $monthFirst > 0 && $dayFirst === 0 ? 'mdy' : 'dmy';
    }

    // ------------------------------------------------------------------
    // Rows and values
    // ------------------------------------------------------------------

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, array{index: int, header: string}>  $columns
     */
    private function readRow(int $rowNumber, array $cells, array $columns, string $order): ParsedPunchRow
    {
        $text = fn (string $role): string => isset($columns[$role]) ? self::cellText($cells[$columns[$role]['index']] ?? null) : '';

        [$rawDateTime, $localDateTime] = $this->readDateTime($cells, $columns, $order);
        $rawDirection = $text('direction');

        $original = [];
        foreach ($columns as $column) {
            $value = self::cellText($cells[$column['index']] ?? null);
            if ($value !== '') {
                $original[$column['header']] = mb_substr($value, 0, 120);
            }
        }

        $error = null;
        if ($rawDateTime === null || $rawDateTime === '') {
            $error = 'No date / time in this row.';
        } elseif ($localDateTime === null) {
            $error = 'Could not read the date / time "'.mb_substr($rawDateTime, 0, 40).'".';
        }

        return new ParsedPunchRow(
            $rowNumber,
            $this->nullable(mb_substr($text('identifier'), 0, 64)),
            $this->nullable(mb_substr($text('code'), 0, 64)),
            $this->nullable(mb_substr($text('name'), 0, 255)),
            $rawDateTime === null ? null : mb_substr($rawDateTime, 0, 64),
            $localDateTime,
            $this->nullable(mb_substr($rawDirection, 0, 32)),
            self::normalizeDirection($rawDirection),
            $original,
            $error,
        );
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, array{index: int, header: string}>  $columns
     * @return array{0: ?string, 1: ?string} raw text, "Y-m-d H:i:s"
     */
    private function readDateTime(array $cells, array $columns, string $order): array
    {
        $cell = fn (string $role): mixed => isset($columns[$role]) ? ($cells[$columns[$role]['index']] ?? null) : null;

        if (isset($columns['datetime'])) {
            $value = $cell('datetime');

            return [self::cellText($value), $this->parseDateTime($value, $order)];
        }

        if (isset($columns['date'], $columns['time'])) {
            $dateValue = $cell('date');
            $timeValue = $cell('time');
            $raw = trim(self::cellText($dateValue).' '.self::cellText($timeValue));

            $date = $this->parseDate($dateValue, $order);
            $time = $this->parseTime($timeValue);
            if ($date !== null && $time !== null) {
                return [$raw, $date.' '.$time];
            }

            // A full date-time in either column.
            return [$raw, $this->parseDateTime($timeValue, $order) ?? $this->parseDateTime($dateValue, $order)];
        }

        $value = isset($columns['time']) ? $cell('time') : $cell('date');

        return [self::cellText($value), $this->parseDateTime($value, $order)];
    }

    /** "Y-m-d H:i:s" or null. Never guesses free text: only the layouts below are accepted. */
    private function parseDateTime(mixed $value, string $order): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            return $this->fromExcelSerial((float) $value, true);
        }

        $text = self::cellText($value);
        if ($text === '') {
            return null;
        }

        $time = self::TIME_PATTERN;

        if (preg_match('/^(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})(?:[ T]+|\s*,\s*)'.$time.'$/', $text, $m)) {
            return $this->build((int) $m[1], (int) $m[2], (int) $m[3], $m[4], $m[5], $m[6] ?? '', $m[7] ?? '');
        }

        if (preg_match('/^(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{2,4})(?:\s+|\s*,\s*)'.$time.'$/', $text, $m)) {
            [$day, $month] = $order === 'mdy' ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]];

            return $this->build((int) $m[3], $month, $day, $m[4], $m[5], $m[6] ?? '', $m[7] ?? '');
        }

        if (preg_match('/^(\d{1,2})[\-\s\/]([A-Za-z]{3,9})[\-\s\/,]+(\d{2,4})(?:\s+|\s*,\s*)'.$time.'$/', $text, $m)) {
            $month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? 0;

            return $this->build((int) $m[3], $month, (int) $m[1], $m[4], $m[5], $m[6] ?? '', $m[7] ?? '');
        }

        if (preg_match('/^([A-Za-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})(?:\s+|\s*,\s*)'.$time.'$/', $text, $m)) {
            $month = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? 0;

            return $this->build((int) $m[3], $month, (int) $m[2], $m[4], $m[5], $m[6] ?? '', $m[7] ?? '');
        }

        return null;
    }

    /** "Y-m-d" of a date-only (or date-time) cell. */
    private function parseDate(mixed $value, string $order): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            $dateTime = $this->fromExcelSerial((float) $value, false);

            return $dateTime === null ? null : substr($dateTime, 0, 10);
        }

        $text = self::cellText($value);

        if (preg_match('/^(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})(?:[ T].*)?$/', $text, $m)) {
            return $this->buildDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{2,4})(?:\s.*)?$/', $text, $m)) {
            [$day, $month] = $order === 'mdy' ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]];

            return $this->buildDate((int) $m[3], $month, $day);
        }

        if (preg_match('/^(\d{1,2})[\-\s\/]([A-Za-z]{3,9})[\-\s\/,]+(\d{2,4})$/', $text, $m)) {
            return $this->buildDate((int) $m[3], self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? 0, (int) $m[1]);
        }

        return null;
    }

    /** "H:i:s" of a time-only cell (text or an Excel day fraction). */
    private function parseTime(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            $fraction = (float) $value - floor((float) $value);
            $seconds = (int) round($fraction * 86400);
            if ($seconds >= 86400) {
                return null;
            }

            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        if (! preg_match('/^'.self::TIME_PATTERN.'$/', self::cellText($value), $m)) {
            return null;
        }

        $dateTime = $this->build(2000, 1, 1, $m[1], $m[2], $m[3] ?? '', $m[4] ?? '');

        return $dateTime === null ? null : substr($dateTime, 11);
    }

    private function fromExcelSerial(float $serial, bool $requireTime): ?string
    {
        // 1960-01-01 .. 2173-10-14: anything else is not a date serial.
        if ($serial < 21916 || $serial > 100000) {
            return null;
        }

        if ($requireTime && floor($serial) === $serial) {
            return null;
        }

        try {
            $seconds = (int) round(($serial - floor($serial)) * 86400);
            $date = ExcelDate::excelToDateTimeObject(floor($serial));

            return $date->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function build(int $year, int $month, int $day, string $hour, string $minute, string $second, string $meridiem): ?string
    {
        $date = $this->buildDate($year, $month, $day);
        $hours = (int) $hour;
        $minutes = (int) $minute;
        $seconds = $second === '' ? 0 : (int) $second;
        $meridiem = strtolower(str_replace('.', '', $meridiem));

        if ($meridiem !== '') {
            if ($hours < 1 || $hours > 12) {
                return null;
            }
            $hours = $meridiem === 'pm' ? ($hours % 12) + 12 : $hours % 12;
        }

        if ($date === null || $hours > 23 || $minutes > 59 || $seconds > 59) {
            return null;
        }

        return sprintf('%s %02d:%02d:%02d', $date, $hours, $minutes, $seconds);
    }

    private function buildDate(int $year, int $month, int $day): ?string
    {
        if ($year < 100) {
            $year += 2000;
        }

        if ($year < 1990 || $year > 2100 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** @param  array<int, mixed>  $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (self::cellText($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
