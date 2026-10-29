<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a MonthlyAttendanceReport::forCompany() result as the HR template
 * workbook: a "Summary" sheet (with the time-bank / grace balance columns,
 * then OT approved / OT pending / weekend-holiday hours), then one sheet per
 * employee (named after the employee) whose daily table starts on row 1: the
 * template's 15 headers in order (worded from the employee's schedule), then
 * Check-ins, Late, Left early, Extra, Away without approval, Grace balance,
 * Schedule, OT approved, OT pending and Break deducted. Times New Roman 10, d-mmm-yyyy
 * dates, h:mm:ss AM/PM times, [h]:mm durations, month totals on the first
 * data row only. A finalised month says so on the Summary sheet (row 5).
 */
class MonthlyAttendanceExcel
{
    /** The per-employee table starts on row 1, like HR's sheet. */
    public const HEADER_ROW = 1;

    public const SUMMARY_HEADER_ROW = 6;

    private const FONT = 'Times New Roman';

    // Subtle flags only (the template has no heavy colours).
    private const GREY = 'FFF2F2F2';

    private const RED = 'FFFDECEC';

    private const AMBER = 'FFFFF7E0';

    private const GRID = 'FFBFBFBF';

    public function __construct(
        protected MonthlyAttendanceReport $report,
    ) {}

    /** @param  array<string, mixed>  $report  MonthlyAttendanceReport::forCompany() */
    public function build(array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10);
        $spreadsheet->getProperties()
            ->setCreator((string) ($report['generated_by'] ?? config('app.name')))
            ->setTitle('Attendance '.$report['company']['name'].' '.$report['month_label']);

        $this->summarySheet($spreadsheet->getActiveSheet(), $report);

        $usedTitles = ['summary'];
        foreach ($report['employees'] as $employeeReport) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->sheetTitle($employeeReport['employee']['name'], $usedTitles));
            $this->employeeSheet($sheet, $report, $employeeReport);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @param  array<string, mixed>  $report */
    public function fileName(array $report): string
    {
        $company = Str::of((string) $report['company']['name'])->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->toString();

        return 'Attendance-'.($company !== '' ? $company : 'Company').'-'.$report['month'].'.xlsx';
    }

    /**
     * Streams the workbook straight to the browser (no file is left on disk).
     *
     * @param  array<string, mixed>  $report
     */
    public function download(array $report): StreamedResponse
    {
        $spreadsheet = $this->build($report);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $this->fileName($report), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array<string, mixed>  $report */
    protected function summarySheet(Worksheet $sheet, array $report): void
    {
        $sheet->setTitle('Summary');

        $sheet->setCellValue('A1', 'Monthly Attendance Report');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue('A2', 'Company');
        $sheet->setCellValue('B2', $report['company']['name']);
        $sheet->setCellValue('A3', 'Month');
        $sheet->setCellValue('B3', $report['month_label']);
        $sheet->setCellValue('A4', 'Generated');
        $sheet->setCellValue('B4', $this->generatedLine($report));
        if (! empty($report['finalised'])) {
            $sheet->setCellValue('A5', 'Status');
            $sheet->setCellValue('B5', $this->finalisedLine($report['finalised']));
        }
        $sheet->getStyle('A2:A5')->getFont()->setBold(true);

        $headers = [
            'Code', 'Employee', 'Department', 'Designation', 'Working days', 'Present', 'Absent', 'Leave', 'OD', 'Late', 'Missing check-out', 'Holidays',
            'Total Hrs Worked', 'Total Hrs Req', 'Delta', 'Penalty', 'Extra (outside required)',
            'Late (mins)', 'Left early (mins)', 'Extra (mins)', 'Away without approval (mins)', 'Net balance (mins)', 'Grace allowance (mins)', 'Grace balance (mins)',
            'OT approved (hrs)', 'OT pending (hrs)', 'Weekend / holiday (hrs)', 'Break deducted (mins)',
        ];
        $widths = [10, 26, 20, 24, 10, 9, 9, 8, 8, 8, 11, 9, 11, 11, 9, 9, 12, 10, 10, 10, 12, 11, 11, 11, 11, 11, 12, 11];
        $headerRow = self::SUMMARY_HEADER_ROW;

        $row = $headerRow + 1;
        foreach ($report['employees'] as $employeeReport) {
            $employee = $employeeReport['employee'];
            $summary = $employeeReport['summary'];

            $sheet->fromArray([[
                $employee['code'],
                $employee['name'],
                $employee['department'],
                $employee['designation'],
                $summary['working_days'],
                $summary['present_days'],
                $summary['absent_days'],
                $summary['leave_days'],
                $summary['od_days'],
                $summary['late_days'],
                $summary['missing_check_outs'],
                $summary['holidays'],
                $summary['worked_hours'],
                $summary['required_hours'],
                $summary['delta_hours'],
                $summary['penalty_percent'] / 100,
                $summary['extra_minutes'] / 1440,
                $summary['late_minutes'],
                $summary['left_early_minutes'] ?? 0,
                $summary['time_bank_extra_minutes'] ?? 0,
                $summary['gap_minutes'] ?? 0,
                $summary['net_balance_minutes'] ?? 0,
                ($summary['grace_allowance_minutes'] ?? 0) + ($summary['grace_carry_in_minutes'] ?? 0),
                $summary['grace_balance_minutes'] ?? 0,
                $summary['overtime_approved_hours'] ?? 0,
                $summary['overtime_pending_hours'] ?? 0,
                $summary['weekend_holiday_hours'] ?? 0,
                $summary['break_deducted_minutes'] ?? 0,
            ]], null, 'A'.$row, true);

            if ($summary['penalty_percent'] > 0) {
                $sheet->getStyle('O'.$row.':P'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::RED);
            }

            $row++;
        }

        $lastRow = max($headerRow, $row - 1);
        $this->table($sheet, $headers, $widths, $headerRow, $lastRow);

        if ($lastRow > $headerRow) {
            $sheet->getStyle('A'.($headerRow + 1).':AB'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('M'.($headerRow + 1).':O'.$lastRow)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('P'.($headerRow + 1).':P'.$lastRow)->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle('Q'.($headerRow + 1).':Q'.$lastRow)->getNumberFormat()->setFormatCode('[h]:mm');
            $sheet->getStyle('R'.($headerRow + 1).':X'.$lastRow)->getNumberFormat()->setFormatCode('0');
            $sheet->getStyle('Y'.($headerRow + 1).':AA'.$lastRow)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('AB'.($headerRow + 1).':AB'.$lastRow)->getNumberFormat()->setFormatCode('0');
        }
    }

    /**
     * One employee's month, laid out like HR's sheet: the header on row 1
     * (who and which month is the sheet name and the Summary sheet), the
     * template's 15 columns, then the added columns; month totals only on
     * the first data row in L-O.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $employeeReport
     */
    protected function employeeSheet(Worksheet $sheet, array $report, array $employeeReport): void
    {
        $summary = $employeeReport['summary'];
        $credit = isset($report['policy']['daily_extra_credit_cap_minutes']) ? (int) $report['policy']['daily_extra_credit_cap_minutes'] : null;

        $headers = [...$this->report->dailyHeaders($credit, $employeeReport['schedule'] ?? null), ...$this->report->extraHeaders()];
        $widths = [14, 13, 13, 18, 14, 14, 14, 14, 14, 14, 20, 13, 13, 13, 13, 24, 11, 11, 11, 13, 13, 22, 11, 11, 11];
        $headerRow = self::HEADER_ROW;

        $row = $headerRow + 1;
        foreach ($employeeReport['rows'] as $index => $day) {
            $sheet->setCellValue('A'.$row, ExcelDate::PHPToExcel(CarbonImmutable::parse($day['date'])->startOfDay()));
            $this->setTime($sheet, 'B'.$row, $day['first_in']);
            $this->setTime($sheet, 'C'.$row, $day['last_out']);
            $sheet->setCellValueExplicit('D'.$row, (string) $day['remarks'], DataType::TYPE_STRING);
            $this->setTime($sheet, 'E'.$row, $day['clamped_in']);
            $this->setTime($sheet, 'F'.$row, $day['clamped_out']);

            if ($day['working'] || $day['off_day_work']) {
                $sheet->setCellValue('G'.$row, $day['before_minutes']);
                $sheet->setCellValue('H'.$row, $day['after_minutes']);
                $sheet->setCellValue('I'.$row, $day['inside_seconds'] / 86400);
                $sheet->setCellValue('J'.$row, $day['credit_minutes']);
                $sheet->setCellValue('K'.$row, $day['final_seconds'] / 86400);
            }

            if ($index === 0) {
                $this->monthTotals($sheet, $row, $summary);
            }

            $sheet->setCellValueExplicit('P'.$row, (string) ($day['intervals_text'] ?? ''), DataType::TYPE_STRING);
            if ($day['working']) {
                $sheet->setCellValue('Q'.$row, $day['late_minutes']);
                $sheet->setCellValue('R'.$row, $day['left_early_minutes'] ?? 0);
                $sheet->setCellValue('S'.$row, $day['extra_minutes'] ?? 0);
                $sheet->setCellValue('T'.$row, $day['gap_minutes'] ?? 0);
            }
            if (isset($day['grace_balance_seconds'])) {
                $sheet->setCellValue('U'.$row, intdiv((int) $day['grace_balance_seconds'], 60));
            }
            $sheet->setCellValueExplicit('V'.$row, (string) ($day['schedule'] ?? ''), DataType::TYPE_STRING);
            if (($day['overtime_minutes'] ?? 0) > 0) {
                $sheet->setCellValue('W'.$row, (int) $day['overtime_minutes']);
            }
            if (($day['overtime_pending_minutes'] ?? 0) > 0) {
                $sheet->setCellValue('X'.$row, (int) $day['overtime_pending_minutes']);
            }
            if (($day['break_deducted_minutes'] ?? 0) > 0) {
                $sheet->setCellValue('Y'.$row, (int) $day['break_deducted_minutes']);
            }

            $fill = match (true) {
                in_array($day['type'], ['weekend', 'holiday'], true) => self::GREY,
                $day['absent'] || $day['missing_check_out']          => self::RED,
                $day['late']                                         => self::AMBER,
                default                                              => null,
            };
            if ($fill !== null) {
                $sheet->getStyle('A'.$row.':K'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fill);
            }

            $row++;
        }

        if ($employeeReport['rows'] === []) {
            $sheet->setCellValue('A'.$row, 'No working days in this month.');
            $this->monthTotals($sheet, $row, $summary);
            $row++;
        }

        $lastRow = $row - 1;
        $this->table($sheet, $headers, $widths, $headerRow, $lastRow);

        $first = $headerRow + 1;
        $sheet->getStyle('A'.$first.':Y'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A'.$first.':A'.$lastRow)->getNumberFormat()->setFormatCode('d-mmm-yyyy');
        $sheet->getStyle('B'.$first.':C'.$lastRow)->getNumberFormat()->setFormatCode('h:mm:ss AM/PM');
        $sheet->getStyle('E'.$first.':F'.$lastRow)->getNumberFormat()->setFormatCode('h:mm:ss AM/PM');
        $sheet->getStyle('G'.$first.':H'.$lastRow)->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle('I'.$first.':I'.$lastRow)->getNumberFormat()->setFormatCode('[h]:mm');
        $sheet->getStyle('J'.$first.':J'.$lastRow)->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle('K'.$first.':K'.$lastRow)->getNumberFormat()->setFormatCode('[h]:mm');
        $sheet->getStyle('L'.$first.':N'.$lastRow)->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle('O'.$first.':O'.$lastRow)->getNumberFormat()->setFormatCode('0.00%');
        $sheet->getStyle('Q'.$first.':U'.$lastRow)->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle('W'.$first.':Y'.$lastRow)->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle('D'.$first.':D'.$lastRow)->getAlignment()->setWrapText(true);
        $sheet->getStyle('P'.$first.':P'.$lastRow)->getAlignment()->setWrapText(true);
    }

    /** @param  array<string, mixed>  $summary */
    protected function monthTotals(Worksheet $sheet, int $row, array $summary): void
    {
        $sheet->setCellValue('L'.$row, $summary['worked_hours']);
        $sheet->setCellValue('M'.$row, $summary['required_hours']);
        $sheet->setCellValue('N'.$row, $summary['delta_hours']);
        $sheet->setCellValue('O'.$row, $summary['penalty_percent'] / 100);
    }

    /**
     * Template table style: Times New Roman 10 bold header, wrapped, centred,
     * bottom-aligned, no fill, a medium rule under it; thin light-grey
     * borders on the body; AutoFilter on the header row and frozen below it.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, int>  $widths
     */
    protected function table(Worksheet $sheet, array $headers, array $widths, int $headerRow, int $lastRow): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->fromArray([$headers], null, 'A'.$headerRow, true);

        $header = $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$headerRow);
        $header->getFont()->setName(self::FONT)->setSize(10)->setBold(true);
        $header->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_BOTTOM)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($headerRow)->setRowHeight(60);

        $sheet->getStyle('A'.$headerRow.':'.$lastColumn.max($headerRow, $lastRow))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::GRID);
        $header->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FF000000');

        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        $sheet->setAutoFilter('A'.$headerRow.':'.$lastColumn.max($headerRow, $lastRow));
        $sheet->freezePane('A'.($headerRow + 1));
    }

    protected function setTime(Worksheet $sheet, string $cell, ?int $seconds): void
    {
        if ($seconds === null) {
            return;
        }

        // Time of day only; an after-midnight check-out still shows its clock time.
        $sheet->setCellValue($cell, ($seconds % 86400) / 86400);
    }

    /** @param  array<int, string>  $used */
    protected function sheetTitle(string $name, array &$used): string
    {
        $base = trim((string) preg_replace('/[\\\\\/\?\*\[\]:\']+/', ' ', $name)) ?: 'Employee';
        $base = mb_substr($base, 0, 31);
        $title = $base;
        $counter = 2;

        while (in_array(mb_strtolower($title), $used, true)) {
            $suffix = ' ('.$counter++.')';
            $title = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
        }

        $used[] = mb_strtolower($title);

        return $title;
    }

    /** @param  array{at: mixed, by?: ?string}  $finalised */
    protected function finalisedLine(array $finalised): string
    {
        $at = $finalised['at'] instanceof CarbonImmutable ? $finalised['at']->format('d-M-Y h:i A') : (string) $finalised['at'];

        return trim('Finalised on '.$at.(! empty($finalised['by']) ? ' by '.$finalised['by'] : ''));
    }

    /** @param  array<string, mixed>  $report */
    protected function generatedLine(array $report): string
    {
        $at = $report['generated_at'] instanceof CarbonImmutable ? $report['generated_at']->format('d-M-Y h:i A') : (string) $report['generated_at'];

        return trim($at.($report['generated_by'] ? ' by '.$report['generated_by'] : ''));
    }
}
