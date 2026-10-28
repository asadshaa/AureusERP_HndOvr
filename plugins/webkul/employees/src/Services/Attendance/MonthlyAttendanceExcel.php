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
 * workbook: a "Summary" sheet, then one sheet per employee with the daily
 * table (template headers, Times New Roman 10, d-MMM-yyyy dates,
 * h:mm:ss AM/PM times, [h]:mm durations).
 */
class MonthlyAttendanceExcel
{
    public const HEADER_ROW = 8;

    public const SUMMARY_HEADER_ROW = 6;

    private const FONT = 'Times New Roman';

    private const GREY = 'FFE7E6E6';

    private const RED = 'FFFFC7CE';

    private const AMBER = 'FFFFEB9C';

    private const HEADER_FILL = 'FFD9E1F2';

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
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Company');
        $sheet->setCellValue('B2', $report['company']['name']);
        $sheet->setCellValue('A3', 'Month');
        $sheet->setCellValue('B3', $report['month_label']);
        $sheet->setCellValue('A4', 'Generated');
        $sheet->setCellValue('B4', $this->generatedLine($report));
        $sheet->getStyle('A2:A4')->getFont()->setBold(true);

        $headers = ['Code', 'Employee', 'Department', 'Designation', 'Working days', 'Present', 'Absent', 'Leave', 'OD', 'Late', 'Missing check-out', 'Holidays', 'Total Hrs Worked', 'Total Hrs Req', 'Delta', 'Penalty', 'Extra (outside required)'];
        $widths = [10, 26, 20, 24, 10, 9, 9, 8, 8, 8, 11, 9, 11, 11, 9, 9, 12];
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
            ]], null, 'A'.$row, true);

            if ($summary['penalty_percent'] > 0) {
                $sheet->getStyle('O'.$row.':P'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::RED);
            }

            $row++;
        }

        $lastRow = max($headerRow, $row - 1);
        $this->table($sheet, $headers, $widths, $headerRow, $lastRow);

        if ($lastRow > $headerRow) {
            $sheet->getStyle('M'.($headerRow + 1).':O'.$lastRow)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('P'.($headerRow + 1).':P'.$lastRow)->getNumberFormat()->setFormatCode('0.00%');
            $sheet->getStyle('Q'.($headerRow + 1).':Q'.$lastRow)->getNumberFormat()->setFormatCode('[h]:mm');
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $employeeReport
     */
    protected function employeeSheet(Worksheet $sheet, array $report, array $employeeReport): void
    {
        $employee = $employeeReport['employee'];
        $summary = $employeeReport['summary'];

        $sheet->setCellValue('A1', $report['company']['name']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $info = [
            ['Employee', $employee['name'], 'Month', $report['month_label']],
            ['Code', $employee['code'], 'Generated', $this->generatedLine($report)],
            ['Department', $employee['department'], '', ''],
            ['Designation', $employee['designation'], '', ''],
        ];
        $sheet->fromArray($info, null, 'A2', true);
        $sheet->getStyle('A2:A5')->getFont()->setBold(true);
        $sheet->getStyle('C2:C5')->getFont()->setBold(true);

        $headers = $this->report->dailyHeaders();
        $widths = [12, 12, 12, 24, 12, 12, 11, 11, 11, 11, 16, 11, 11, 9, 9];
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
                $sheet->setCellValue('L'.$row, $summary['worked_hours']);
                $sheet->setCellValue('M'.$row, $summary['required_hours']);
                $sheet->setCellValue('N'.$row, $summary['delta_hours']);
                $sheet->setCellValue('O'.$row, $summary['penalty_percent'] / 100);
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
            $sheet->setCellValue('L'.$row, $summary['worked_hours']);
            $sheet->setCellValue('M'.$row, $summary['required_hours']);
            $sheet->setCellValue('N'.$row, $summary['delta_hours']);
            $sheet->setCellValue('O'.$row, $summary['penalty_percent'] / 100);
            $row++;
        }

        $lastRow = $row - 1;
        $this->table($sheet, $headers, $widths, $headerRow, $lastRow);

        $first = $headerRow + 1;
        $sheet->getStyle('A'.$first.':A'.$lastRow)->getNumberFormat()->setFormatCode('d-mmm-yyyy');
        $sheet->getStyle('B'.$first.':C'.$lastRow)->getNumberFormat()->setFormatCode('h:mm:ss AM/PM');
        $sheet->getStyle('E'.$first.':F'.$lastRow)->getNumberFormat()->setFormatCode('h:mm:ss AM/PM');
        $sheet->getStyle('I'.$first.':I'.$lastRow)->getNumberFormat()->setFormatCode('[h]:mm');
        $sheet->getStyle('K'.$first.':K'.$lastRow)->getNumberFormat()->setFormatCode('[h]:mm');
        $sheet->getStyle('L'.$first.':N'.$lastRow)->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle('O'.$first.':O'.$lastRow)->getNumberFormat()->setFormatCode('0.00%');
        $sheet->getStyle('D'.$first.':D'.$lastRow)->getAlignment()->setWrapText(true);
        $sheet->getStyle('L'.$first.':O'.$first)->getFont()->setBold(true);
    }

    /**
     * Bold, wrapped, bordered header row; borders on the body; filter and frozen header.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, int>  $widths
     */
    protected function table(Worksheet $sheet, array $headers, array $widths, int $headerRow, int $lastRow): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->fromArray([$headers], null, 'A'.$headerRow, true);

        $header = $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$headerRow);
        $header->getFont()->setBold(true);
        $header->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->getRowDimension($headerRow)->setRowHeight(54);

        $sheet->getStyle('A'.$headerRow.':'.$lastColumn.max($headerRow, $lastRow))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

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

    /** @param  array<string, mixed>  $report */
    protected function generatedLine(array $report): string
    {
        $at = $report['generated_at'] instanceof CarbonImmutable ? $report['generated_at']->format('d-M-Y h:i A') : (string) $report['generated_at'];

        return trim($at.($report['generated_by'] ? ' by '.$report['generated_by'] : ''));
    }
}
