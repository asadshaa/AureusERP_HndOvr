<?php

namespace Webkul\Employee\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Export to Excel" of the HR reports (Employees -> Reportings), in the
 * style of the monthly attendance workbook (MonthlyAttendanceExcel): Times
 * New Roman 10, title rows (report, company, period, department, generated)
 * above a table with a bold wrapped header, a medium rule under it, thin
 * grey borders, set column widths, AutoFilter and frozen header;
 * d-mmm-yyyy dates.
 */
class HrReportExcel
{
    /** The first table row under the title rows. */
    public const HEADER_ROW = 7;

    private const FONT = 'Times New Roman';

    private const GRID = 'FFBFBFBF';

    /** @param  array<string, mixed>  $report  JoinersLeaversReport::build() */
    public function joinersLeavers(array $report): Spreadsheet
    {
        $spreadsheet = $this->spreadsheet('Joiners & leavers', $report);
        $period = ['Period', $report['period_label']];
        $summary = $report['summary'];

        $sheet = $spreadsheet->getActiveSheet()->setTitle('Summary');
        $this->titleRows($sheet, 'Joiners & Leavers', $report, $period);
        $this->table($sheet, ['Month', 'Joiners', 'Leavers', 'Net change'], [14, 11, 11, 11], array_map(
            fn (array $month): array => [$month['label'], $month['joiners'], $month['leavers'], $month['net']],
            $report['months'],
        ), totals: ['Total', $summary['joiners'], $summary['leavers'], $summary['net']]);
        $after = self::HEADER_ROW + count($report['months']) + 3;
        foreach ([
            'Headcount at start' => $summary['opening'],
            'Headcount at end'   => $summary['closing'],
            'Turnover %'         => $summary['turnover_percent'] / 100,
        ] as $label => $value) {
            $this->writeRow($sheet, $after, [$label, $value]);
            $sheet->getStyle('A'.$after)->getFont()->setBold(true);
            $after++;
        }
        $sheet->getStyle('B'.($after - 1))->getNumberFormat()->setFormatCode('0.0%');

        $sheet = $spreadsheet->createSheet()->setTitle('Joiners');
        $this->titleRows($sheet, 'Joiners', $report, $period);
        $this->table($sheet, ['Code', 'Employee', 'Department', 'Job title', 'Joining date', 'Date source'], [12, 28, 26, 26, 13, 15], array_map(
            fn (array $person): array => [$person['code'], $person['name'], $person['department'], $person['job_title'], $this->excelDate($person['joined']), $person['joined_source']],
            $report['joiners'],
        ), dateColumns: ['E']);

        $sheet = $spreadsheet->createSheet()->setTitle('Leavers');
        $this->titleRows($sheet, 'Leavers', $report, $period);
        $this->table($sheet, ['Code', 'Employee', 'Department', 'Job title', 'Joining date', 'Leaving date', 'Date source', 'Tenure', 'Departure reason', 'Description'], [12, 28, 26, 26, 13, 13, 13, 10, 20, 40], array_map(
            fn (array $person): array => [
                $person['code'], $person['name'], $person['department'], $person['job_title'],
                $this->excelDate($person['joined']), $this->excelDate((string) $person['left']), $person['left_source'], $person['tenure'],
                $person['reason'] ?? JoinersLeaversReport::NO_REASON, $person['description'],
            ],
            $report['leavers'],
        ), dateColumns: ['E', 'F'], wrapColumns: ['J']);

        $sheet = $spreadsheet->createSheet()->setTitle('Departure reasons');
        $this->titleRows($sheet, 'Departure reasons', $report, $period);
        $this->table($sheet, ['Reason', 'Leavers', '% of leavers'], [28, 11, 13], array_map(
            fn (array $reason): array => [$reason['reason'], $reason['count'], $reason['percent'] / 100],
            $report['reasons'],
        ), percentColumns: ['C']);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @param  array<string, mixed>  $report  HeadcountTrendReport::build() */
    public function headcount(array $report): Spreadsheet
    {
        $spreadsheet = $this->spreadsheet('Headcount trend', $report);
        $period = ['Period', $report['period_label']];
        $summary = $report['summary'];

        $sheet = $spreadsheet->getActiveSheet()->setTitle('Headcount');
        $this->titleRows($sheet, 'Headcount Trend', $report, $period);
        $this->table($sheet, ['Month', 'Opening', 'Joiners', 'Leavers', 'Closing', 'Turnover %'], [14, 11, 11, 11, 11, 12], array_map(
            fn (array $month): array => [$month['label'], $month['opening'], $month['joiners'], $month['leavers'], $month['closing'], $month['turnover_percent'] / 100],
            $report['months'],
        ), percentColumns: ['F'], totals: ['Period', $summary['opening'], $summary['joiners'], $summary['leavers'], $summary['closing'], $summary['turnover_percent'] / 100]);

        $after = self::HEADER_ROW + count($report['months']) + 3;
        $this->writeRow($sheet, $after, ['Average headcount', $summary['average_headcount']]);
        $sheet->getStyle('A'.$after)->getFont()->setBold(true);

        $sheet = $spreadsheet->createSheet()->setTitle('By department');
        $this->titleRows($sheet, 'Headcount by Department (closing)', $report, $period);
        $labels = array_column($report['months'], 'label');
        $keys = array_column($report['months'], 'month');
        $this->table($sheet, ['Department', ...$labels], [30, ...array_fill(0, count($labels), 10)], array_map(
            fn (array $department): array => [$department['name'], ...array_map(fn (string $key): int => $department['closing'][$key], $keys)],
            $report['departments'],
        ), totals: ['Total', ...array_column($report['months'], 'closing')]);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @param  array<string, mixed>  $report  LeaveBalanceReport::build() */
    public function leaveBalances(array $report): Spreadsheet
    {
        $spreadsheet = $this->spreadsheet('Leave balances', $report);
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Leave balances');
        $this->titleRows($sheet, 'Leave Balances', $report, ['Year', (string) $report['year']]);

        $headers = ['Code', 'Employee', 'Department'];
        $widths = [12, 28, 26];
        foreach ($report['types'] as $type) {
            foreach (['Allocated', 'Taken', 'Pending', 'Remaining'] as $figure) {
                $headers[] = $figure;
                $widths[] = 10;
            }
        }

        $figures = fn (?array $balance): array => $balance === null
            ? [null, null, null, null]
            : [$balance['allocated'], $balance['taken'], $balance['pending'], $balance['remaining']];

        $rows = array_map(fn (array $row): array => [
            $row['code'], $row['name'], $row['department'],
            ...array_merge(...array_map(fn (array $type): array => $figures($row['balances'][$type['key']]), $report['types'])),
        ], $report['rows']);
        $totals = ['Total', null, null, ...array_merge(...array_map(fn (array $type): array => $figures($report['totals'][$type['key']]), $report['types']))];

        // One row above the header names each leave type over its four columns.
        $groupRow = self::HEADER_ROW;
        $this->table($sheet, $headers, $widths, $rows, totals: $totals, headerRow: $groupRow + 1);
        foreach ($report['types'] as $index => $type) {
            $first = Coordinate::stringFromColumnIndex(4 + $index * 4);
            $last = Coordinate::stringFromColumnIndex(7 + $index * 4);
            $sheet->setCellValueExplicit($first.$groupRow, $type['name'].($type['tracked'] ? '' : ' (no balance)'), DataType::TYPE_STRING);
            $sheet->mergeCells($first.$groupRow.':'.$last.$groupRow);
            $style = $sheet->getStyle($first.$groupRow.':'.$last.$groupRow);
            $style->getFont()->setBold(true);
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $style->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::GRID);
        }

        return $spreadsheet;
    }

    /**
     * Streams the workbook straight to the browser (no file is left on disk).
     */
    public function download(Spreadsheet $spreadsheet, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** e.g. "Joiners-Leavers-ShipLine-2026-01-01-2026-10-31.xlsx" */
    public function fileName(string $report, array $header, string $period): string
    {
        $company = Str::of((string) $header['company']['name'])->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->toString();

        return $report.'-'.($company !== '' ? $company : 'Company').'-'.$period.'.xlsx';
    }

    /** @param  array<string, mixed>  $report */
    protected function spreadsheet(string $title, array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10);
        $spreadsheet->getProperties()
            ->setCreator((string) ($report['generated_by'] ?? config('app.name')))
            ->setTitle($title.' '.$report['company']['name']);

        return $spreadsheet;
    }

    /**
     * Rows 1-5: report name, company, period / year, department, generated.
     *
     * @param  array<string, mixed>  $report
     * @param  array{0: string, 1: string}  $period
     */
    protected function titleRows(Worksheet $sheet, string $title, array $report, array $period): void
    {
        $generatedAt = $report['generated_at'] instanceof CarbonImmutable ? $report['generated_at']->format('d-M-Y h:i A') : (string) $report['generated_at'];

        $this->writeRow($sheet, 1, [$title]);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $this->writeRow($sheet, 2, ['Company', $report['company']['name']]);
        $this->writeRow($sheet, 3, $period);
        $this->writeRow($sheet, 4, ['Department', $report['department'] ?? 'All departments']);
        $this->writeRow($sheet, 5, ['Generated', trim($generatedAt.($report['generated_by'] ? ' by '.$report['generated_by'] : ''))]);
        $sheet->getStyle('A2:A5')->getFont()->setBold(true);
    }

    /**
     * Text is always written as text, so a name such as "=1+1" can never
     * become a formula; numbers stay numbers, null stays empty.
     *
     * @param  array<int, mixed>  $values
     */
    protected function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            if ($value === null) {
                continue;
            }

            $cell = Coordinate::stringFromColumnIndex($index + 1).$row;
            is_string($value)
                ? $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING)
                : $sheet->setCellValue($cell, $value);
        }
    }

    /**
     * The template table style (MonthlyAttendanceExcel::table()), with an
     * optional bold totals row under the data.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, int>  $widths
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $dateColumns
     * @param  array<int, string>  $percentColumns
     * @param  array<int, string>  $wrapColumns
     * @param  array<int, mixed>|null  $totals
     */
    protected function table(
        Worksheet $sheet,
        array $headers,
        array $widths,
        array $rows,
        array $dateColumns = [],
        array $percentColumns = [],
        array $wrapColumns = [],
        ?array $totals = null,
        int $headerRow = self::HEADER_ROW,
    ): void {
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $this->writeRow($sheet, $headerRow, $headers);

        foreach (array_values($rows) as $index => $row) {
            $this->writeRow($sheet, $headerRow + 1 + $index, $row);
        }

        $lastDataRow = $headerRow + count($rows);
        $lastRow = $lastDataRow;
        if ($totals !== null) {
            $lastRow++;
            $this->writeRow($sheet, $lastRow, $totals);
            $sheet->getStyle('A'.$lastRow.':'.$lastColumn.$lastRow)->getFont()->setBold(true);
            $sheet->getStyle('A'.$lastRow.':'.$lastColumn.$lastRow)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');
        }

        $header = $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$headerRow);
        $header->getFont()->setName(self::FONT)->setSize(10)->setBold(true);
        $header->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_BOTTOM)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($headerRow)->setRowHeight(30);

        $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$lastRow)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::GRID);
        $header->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FF000000');

        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        if ($lastRow > $headerRow) {
            foreach ($dateColumns as $column) {
                $sheet->getStyle($column.($headerRow + 1).':'.$column.$lastRow)->getNumberFormat()->setFormatCode('d-mmm-yyyy');
            }
            foreach ($percentColumns as $column) {
                $sheet->getStyle($column.($headerRow + 1).':'.$column.$lastRow)->getNumberFormat()->setFormatCode('0.0%');
            }
            foreach ($wrapColumns as $column) {
                $sheet->getStyle($column.($headerRow + 1).':'.$column.$lastRow)->getAlignment()->setWrapText(true);
            }
            $sheet->getStyle('A'.($headerRow + 1).':'.$lastColumn.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        }

        $sheet->setAutoFilter('A'.$headerRow.':'.$lastColumn.max($headerRow, $lastDataRow));
        $sheet->freezePane('A'.($headerRow + 1));
    }

    protected function excelDate(string $date): float
    {
        return (float) ExcelDate::PHPToExcel(CarbonImmutable::parse($date)->startOfDay());
    }
}
