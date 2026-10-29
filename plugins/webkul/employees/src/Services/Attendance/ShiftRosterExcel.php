<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The shift roster as one Excel sheet, laid out like the Shift roster
 * page: one row per employee, one column per date, the shift code in each
 * cell in the shift's colour ("Off" for a day off, * after a code changed
 * for that day), and a legend of the company's shifts below.
 */
class ShiftRosterExcel
{
    private const FIXED_COLUMNS = 3;

    /** @param  array<string, mixed>  $calendar  ShiftRosterService::calendar() */
    public function build(array $calendar): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Roster');

        $sheet->setCellValue('A1', 'Shift roster');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $calendar['company']['name'].' — '.CarbonImmutable::parse($calendar['from'])->format('d M Y').' to '.CarbonImmutable::parse($calendar['to'])->format('d M Y'));

        $headerRow = 4;
        $sheet->setCellValue('A'.$headerRow, 'Code');
        $sheet->setCellValue('B'.$headerRow, 'Employee');
        $sheet->setCellValue('C'.$headerRow, 'Department');
        foreach ($calendar['dates'] as $index => $date) {
            $sheet->setCellValue([self::FIXED_COLUMNS + 1 + $index, $headerRow], $date['day']."\n".$date['label']);
        }
        $lastColumn = Coordinate::stringFromColumnIndex(self::FIXED_COLUMNS + max(1, count($calendar['dates'])));
        $header = $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$headerRow);
        $header->getFont()->setBold(true);
        $header->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = $headerRow + 1;
        foreach ($calendar['rows'] as $line) {
            $sheet->setCellValueExplicit([1, $row], $line['code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $row], $line['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $row], $line['department'], DataType::TYPE_STRING);

            foreach ($calendar['dates'] as $index => $date) {
                $cell = $line['cells'][$date['date']] ?? null;
                if ($cell === null) {
                    continue;
                }

                $column = self::FIXED_COLUMNS + 1 + $index;
                $sheet->setCellValueExplicit([$column, $row], $cell['code'].($cell['override'] ? '*' : ''), DataType::TYPE_STRING);

                if (! $cell['off'] && $cell['color']) {
                    $style = $sheet->getStyle([$column, $row]);
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF'.strtoupper(ltrim($cell['color'], '#')));
                    $style->getFont()->getColor()->setARGB($this->isDark($cell['color']) ? 'FFFFFFFF' : 'FF000000');
                }
            }

            $row++;
        }

        if ($row > $headerRow + 1) {
            $sheet->getStyle('D'.($headerRow + 1).':'.$lastColumn.($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $row++;
        $sheet->setCellValue('A'.$row, 'Legend');
        $sheet->getStyle('A'.$row)->getFont()->setBold(true);
        foreach ($calendar['shifts'] as $shift) {
            $row++;
            $sheet->setCellValueExplicit([1, $row], $shift['code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $row], $shift['name'].' '.$shift['hours'].($shift['break_minutes'] > 0 ? ', unpaid break '.$shift['break_minutes'].' min' : ''), DataType::TYPE_STRING);
            $sheet->getStyle([1, $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF'.strtoupper(ltrim($shift['color'], '#')));
            $sheet->getStyle([1, $row])->getFont()->getColor()->setARGB($this->isDark($shift['color']) ? 'FFFFFFFF' : 'FF000000');
        }
        $row++;
        $sheet->setCellValue('A'.$row, 'Off = day off; * = changed for that day.');

        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(26);
        $sheet->getColumnDimension('C')->setWidth(18);
        foreach (array_keys($calendar['dates']) as $index) {
            $sheet->getColumnDimensionByColumn(self::FIXED_COLUMNS + 1 + $index)->setWidth(7);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(30);
        $sheet->freezePane('D'.($headerRow + 1));

        return $spreadsheet;
    }

    /** @param  array<string, mixed>  $calendar */
    public function download(array $calendar): StreamedResponse
    {
        $spreadsheet = $this->build($calendar);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $this->fileName($calendar), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array<string, mixed>  $calendar */
    public function fileName(array $calendar): string
    {
        return 'Shift-roster-'.Str::slug($calendar['company']['name'] ?: 'company').'-'.$calendar['from'].'.xlsx';
    }

    private function isDark(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return false;
        }

        [$red, $green, $blue] = array_map('hexdec', str_split($hex, 2));

        return (0.299 * $red + 0.587 * $green + 0.114 * $blue) < 140;
    }
}
