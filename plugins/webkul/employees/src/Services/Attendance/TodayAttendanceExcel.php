<?php

namespace Webkul\Employee\Services\Attendance;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Today's attendance as one Excel sheet: the same rows, in the same order
 * (problems first), as Attendance -> Today's attendance shows.
 */
class TodayAttendanceExcel
{
    /** @var array<int, string> */
    public const HEADERS = ['Code', 'Employee', 'Department', 'Line manager', 'Status', 'First in', 'Last out', 'Worked (h:mm)', 'Source', 'Remarks'];

    /** @param  array<string, mixed>  $report  TodayAttendance::forCompany() */
    public function build(array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance '.$report['date']);

        $sheet->setCellValue('A1', "Today's attendance");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $report['company']['name'].' — '.$report['date_label']);

        $headerRow = 4;
        $sheet->fromArray(self::HEADERS, null, 'A'.$headerRow);
        $sheet->getStyle('A'.$headerRow.':J'.$headerRow)->getFont()->setBold(true);

        $row = $headerRow + 1;
        foreach (TodayAttendance::sortProblemsFirst($report['rows']) as $line) {
            $values = [
                $line['code'],
                $line['employee'],
                $line['department'],
                $line['line_manager'],
                $line['status_label'],
                $line['first_in'],
                $line['last_out'],
                $line['worked'],
                trim($line['source'].($line['remote'] ? ' · remote' : ''), ' ·'),
                $line['remarks'],
            ];

            foreach ($values as $index => $value) {
                $sheet->setCellValueExplicit([$index + 1, $row], (string) $value, DataType::TYPE_STRING);
            }

            $row++;
        }

        foreach ([10, 26, 20, 22, 26, 9, 9, 12, 14, 40] as $index => $width) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
        $sheet->freezePane('A'.($headerRow + 1));

        return $spreadsheet;
    }

    /** @param  array<string, mixed>  $report */
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
    public function fileName(array $report): string
    {
        return 'Attendance-'.Str::slug($report['company']['name'] ?: 'company').'-'.$report['date'].'.xlsx';
    }
}
