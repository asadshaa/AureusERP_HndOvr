<?php

namespace Webkul\Employee\Services\Payroll;

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
 * Writes a PayrollInputReport::build() result as one sheet, styled like the
 * monthly attendance workbook (MonthlyAttendanceExcel): Times New Roman 10,
 * who / which month / status on rows 1-5, a bold wrapped header on row 6
 * frozen with the code and name columns, column widths, number formats, an
 * AutoFilter, and a totals row (SUM formulas) under the employees. A month
 * that is not finalised is a DRAFT in the title, the sheet name, the status
 * line and the file name.
 */
class PayrollInputExcel
{
    public const HEADER_ROW = 6;

    private const FONT = 'Times New Roman';

    private const GRID = 'FFBFBFBF';

    private const AMBER = 'FFFFF7E0';

    private const TOTAL = 'FFF2F2F2';

    /** @param  array<string, mixed>  $report  PayrollInputReport::build() */
    public function build(array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10);
        $spreadsheet->getProperties()
            ->setCreator((string) ($report['generated_by'] ?? config('app.name')))
            ->setTitle($this->title($report));

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($report['draft'] ? 'DRAFT Payroll input' : 'Payroll input');

        $this->heading($sheet, $report);
        $this->table($sheet, $report);

        return $spreadsheet;
    }

    /** @param  array<string, mixed>  $report */
    public function title(array $report): string
    {
        return 'Payroll input '.$report['company']['name'].' '.$report['month_label'].($report['draft'] ? ' (DRAFT)' : '');
    }

    /** @param  array<string, mixed>  $report */
    public function fileName(array $report): string
    {
        $company = Str::of((string) $report['company']['name'])->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->toString();

        return 'Payroll-Input-'.($company !== '' ? $company : 'Company').'-'.$report['month'].($report['draft'] ? '-DRAFT' : '').'.xlsx';
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
    protected function heading(Worksheet $sheet, array $report): void
    {
        $sheet->setCellValue('A1', $report['draft'] ? 'Payroll input (DRAFT)' : 'Payroll input');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue('A2', 'Company');
        $sheet->setCellValue('B2', $report['company']['name']);
        $sheet->setCellValue('A3', 'Month');
        $sheet->setCellValue('B3', $report['month_label']);
        $sheet->setCellValue('A4', 'Generated');
        $sheet->setCellValue('B4', $this->moment($report['generated_at']).($report['generated_by'] ? ' by '.$report['generated_by'] : ''));
        $sheet->setCellValue('A5', 'Status');

        if ($report['draft']) {
            $sheet->setCellValue('B5', 'DRAFT: attendance for '.$report['month_label'].' is not finalised, so these figures may still change.');
            $sheet->getStyle('B5')->getFont()->setBold(true)->getColor()->setARGB('FFC00000');
            $sheet->getStyle('B5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AMBER);
        } else {
            $sheet->setCellValue('B5', trim('Finalised on '.$this->moment($report['finalised']['at']).(! empty($report['finalised']['by']) ? ' by '.$report['finalised']['by'] : '')));
        }

        $sheet->getStyle('A2:A5')->getFont()->setBold(true);
    }

    /** @param  array<string, mixed>  $report */
    protected function table(Worksheet $sheet, array $report): void
    {
        $columns = $report['columns'];
        $keys = array_keys($columns);
        $letters = [];
        foreach ($keys as $index => $key) {
            $letters[$key] = Coordinate::stringFromColumnIndex($index + 1);
        }

        $headerRow = self::HEADER_ROW;
        $lastColumn = $letters[$keys[count($keys) - 1]];
        $sheet->fromArray([array_column($columns, 'label')], null, 'A'.$headerRow, true);

        $row = $headerRow + 1;
        foreach ($report['rows'] as $line) {
            foreach ($columns as $key => $column) {
                $cell = $letters[$key].$row;
                $value = $line[$key] ?? null;

                match ($column['type']) {
                    'text'  => $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING),
                    'date'  => $value ? $sheet->setCellValue($cell, ExcelDate::PHPToExcel(CarbonImmutable::parse($value)->startOfDay())) : null,
                    default => $value !== null ? $sheet->setCellValue($cell, $value) : null,
                };
            }
            $row++;
        }

        $firstData = $headerRow + 1;
        $lastData = max($headerRow, $row - 1);
        $totalRow = $row;

        $sheet->setCellValue('A'.$totalRow, 'Total');
        foreach ($columns as $key => $column) {
            if ($column['total'] && $lastData >= $firstData) {
                $sheet->setCellValue($letters[$key].$totalRow, '=SUM('.$letters[$key].$firstData.':'.$letters[$key].$lastData.')');
            }
        }
        $totals = $sheet->getStyle('A'.$totalRow.':'.$lastColumn.$totalRow);
        $totals->getFont()->setBold(true);
        $totals->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::TOTAL);
        $totals->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FF000000');

        $header = $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$headerRow);
        $header->getFont()->setName(self::FONT)->setSize(10)->setBold(true);
        $header->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_BOTTOM)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($headerRow)->setRowHeight(45);

        $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$totalRow)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::GRID);
        $header->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FF000000');

        foreach ($columns as $key => $column) {
            $letter = $letters[$key];
            $sheet->getColumnDimension($letter)->setWidth($this->width($key, $column['type']));
            $range = $letter.$firstData.':'.$letter.$totalRow;
            $style = $sheet->getStyle($range);

            match ($column['type']) {
                'text'    => $style->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText($key === 'notes'),
                'date'    => $style->getNumberFormat()->setFormatCode('d-mmm-yyyy'),
                'int'     => $style->getNumberFormat()->setFormatCode('0'),
                'minutes' => $style->getNumberFormat()->setFormatCode('0;-0;0'),
                'days'    => $style->getNumberFormat()->setFormatCode('0.00'),
                'hours'   => $style->getNumberFormat()->setFormatCode('0.00'),
                'money'   => $style->getNumberFormat()->setFormatCode('#,##0.00'),
                default   => null,
            };

            if ($column['type'] !== 'text') {
                $style->getAlignment()->setHorizontal($column['type'] === 'date' ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_RIGHT)
                    ->setVertical(Alignment::VERTICAL_TOP);
            }
        }

        $sheet->setAutoFilter('A'.$headerRow.':'.$lastColumn.$lastData);
        $sheet->freezePane(($letters['name'] ?? 'A') === 'B' ? 'C'.$firstData : 'A'.$firstData);
    }

    protected function width(string $key, string $type): int
    {
        return match ($key) {
            'code'         => 12,
            'name'         => 26,
            'cnic'         => 17,
            'bank_account' => 22,
            'department'   => 20,
            'designation'  => 22,
            'notes'        => 60,
            default        => match ($type) {
                'date'  => 12,
                'money' => 14,
                default => 11,
            },
        };
    }

    protected function moment(mixed $at): string
    {
        return $at instanceof CarbonImmutable ? $at->format('d-M-Y h:i A') : (string) $at;
    }
}
