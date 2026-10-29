<?php

namespace Webkul\Employee\Services\Attendance;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Enums\DeviceImportRowStatus;
use Webkul\Employee\Enums\PunchPairingMode;
use Webkul\Employee\Models\AttendanceImportBatch;
use Webkul\Employee\Models\AttendanceImportRow;

/**
 * Workbooks of the device import: the blank template HR fills in, and the
 * row-level report of one batch (every row with its status and message,
 * plus a summary and the per-employee counts).
 */
class DeviceImportExcel
{
    public const TEMPLATE_HEADERS = ['Employee Code / Biometric ID', 'Date Time', 'Direction'];

    private const HEADER_FILL = 'FFE8EEF8';

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('Aureus device attendance template');

        $punches = $spreadsheet->getActiveSheet()->setTitle('Punches');
        $this->header($punches, self::TEMPLATE_HEADERS);
        // Text, so Excel keeps leading zeros of device numbers ("0012").
        $punches->getStyle('A2:A2000')->getNumberFormat()->setFormatCode('@');
        foreach (['A' => 30, 'B' => 22, 'C' => 12] as $column => $width) {
            $punches->getColumnDimension($column)->setWidth($width);
        }

        $example = $spreadsheet->createSheet()->setTitle('Example');
        $this->header($example, self::TEMPLATE_HEADERS);
        $rows = [
            ['1001', '2026-10-01 09:02', 'IN'],
            ['1001', '2026-10-01 13:00', 'OUT'],
            ['1001', '2026-10-01 14:01', 'IN'],
            ['1001', '2026-10-01 18:05', 'OUT'],
            ['SHL-0007', '01/10/2026 09:15:40', ''],
            ['SHL-0007', '01/10/2026 18:30:02', ''],
        ];
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                $example->setCellValueExplicit([$column + 1, $index + 2], $value, DataType::TYPE_STRING);
            }
        }
        foreach (['A' => 30, 'B' => 22, 'C' => 12] as $column => $width) {
            $example->getColumnDimension($column)->setWidth($width);
        }

        $notes = $spreadsheet->createSheet()->setTitle('How to fill');
        $lines = [
            'Fill the "Punches" sheet: one row per punch on the device.',
            'Employee Code / Biometric ID: the user number on the device (set it on the employee as "Biometric / device ID"), or the employee code (e.g. TII-0001).',
            'Date Time: the device time in Pakistan time, e.g. 2026-10-01 09:02, 01/10/2026 09:02:15 or 1-Oct-2026 9:02 AM.',
            'Direction (optional): IN or OUT. With "first in / last out" pairing it is ignored.',
            'ZKTeco exports can be uploaded as they are (columns such as AC-No., Name, Time, State / Check Type).',
            'Pairing: '.PunchPairingMode::FirstInLastOut->label().' (default) - '.PunchPairingMode::FirstInLastOut->description(),
            'Or: '.PunchPairingMode::Sequential->label().' - '.PunchPairingMode::Sequential->description(),
            'An odd number of punches leaves the last check-out empty and puts the day in Needs Review; no time is made up.',
            'Days that already have manual or GPS attendance are not changed; they are listed in the import report.',
        ];
        foreach ($lines as $index => $line) {
            $notes->setCellValue([1, $index + 1], $line);
        }
        $notes->getColumnDimension('A')->setWidth(140);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function report(AttendanceImportBatch $batch): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('Device import #'.$batch->id.' report');

        $rowsSheet = $spreadsheet->getActiveSheet()->setTitle('Rows');
        $headers = ['Row', 'Device ID (file)', 'Employee code (file)', 'Name (file)', 'Date / time (file)', 'Direction (file)', 'Matched employee', 'Code', 'Punch (local)', 'Day', 'Status', 'Message'];
        $this->header($rowsSheet, $headers);

        $line = 2;
        AttendanceImportRow::query()
            ->where('batch_id', $batch->id)
            ->with('employee:id,name,employee_number')
            ->orderBy('row_number')
            ->chunk(1000, function ($rows) use ($rowsSheet, &$line): void {
                foreach ($rows as $row) {
                    $status = $row->status instanceof DeviceImportRowStatus ? $row->status : DeviceImportRowStatus::tryFrom((string) $row->status);
                    $values = [
                        $row->row_number,
                        (string) $row->identifier,
                        (string) $row->employee_code,
                        (string) $row->employee_name,
                        (string) $row->raw_datetime,
                        (string) $row->raw_direction,
                        (string) $row->employee?->name,
                        (string) $row->employee?->employee_number,
                        (string) $row->local_datetime,
                        $row->attendance_date?->toDateString() ?? '',
                        $status?->label() ?? (string) $row->status,
                        (string) $row->message,
                    ];

                    foreach ($values as $column => $value) {
                        $rowsSheet->setCellValueExplicit([$column + 1, $line], $value, is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }

                    if ($status?->isProblem()) {
                        $rowsSheet->getStyle([1, $line, count($values), $line])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFDECEC');
                    }

                    $line++;
                }
            });

        foreach (range(1, count($headers)) as $column) {
            $rowsSheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
        $rowsSheet->setAutoFilter([1, 1, count($headers), max(1, $line - 1)]);
        $rowsSheet->freezePane('A2');

        $summary = $spreadsheet->createSheet()->setTitle('Summary');
        $data = $batch->summary ?? [];
        $info = [
            ['Import', '#'.$batch->id],
            ['File', $batch->file_name],
            ['Status', $batch->statusLabel()],
            ['Uploaded by', (string) $batch->creator?->name],
            ['Uploaded at', $batch->created_at?->setTimezone($batch->timezone)->format('d M Y H:i')],
            ['Imported by', (string) $batch->importer?->name],
            ['Imported at', (string) $batch->imported_at?->setTimezone($batch->timezone)->format('d M Y H:i')],
            ['Pairing', $batch->pairingMode()->label()],
            ['Dates read as', $batch->date_order === 'mdy' ? 'month / day / year' : 'day / month / year'],
            ['Device time zone', $batch->timezone],
            ['Days', ($data['date_from'] ?? '').' to '.($data['date_to'] ?? '')],
            ['Rows', $batch->total_rows],
        ];
        foreach (DeviceImportRowStatus::cases() as $status) {
            if (($data['counts'][$status->value] ?? 0) > 0) {
                $info[] = ['Rows: '.$status->label(), $data['counts'][$status->value]];
            }
        }
        $info[] = ['Days written', $batch->days_written];
        $info[] = ['Days needing review (missing check-out)', $batch->review_days];
        $info[] = ['Days not changed (manual entries)', $batch->flagged_days];
        if ($batch->undone_at) {
            $info[] = ['Undone at', $batch->undone_at->setTimezone($batch->timezone)->format('d M Y H:i')];
            $info[] = ['Days removed / rebuilt / kept', ($batch->undo_summary['days_removed'] ?? 0).' / '.($batch->undo_summary['days_rebuilt'] ?? 0).' / '.($batch->undo_summary['days_kept'] ?? 0)];
        }
        foreach ($info as $index => [$label, $value]) {
            $summary->setCellValue([1, $index + 1], $label);
            $summary->setCellValueExplicit([2, $index + 1], (string) $value, DataType::TYPE_STRING);
        }
        $summary->getColumnDimension('A')->setWidth(42);
        $summary->getColumnDimension('B')->setWidth(48);

        $people = $spreadsheet->createSheet()->setTitle('Employees');
        $peopleHeaders = ['Employee', 'Code', 'Punches', 'Days', 'New days', 'Added to earlier import', 'Need review', 'Not changed (manual)', 'Problem rows'];
        $this->header($people, $peopleHeaders);
        foreach (array_values($data['employees'] ?? []) as $index => $entry) {
            $values = [$entry['name'], (string) $entry['code'], $entry['rows'], $entry['days'], $entry['new_days'], $entry['merged_days'], $entry['review_days'], $entry['flagged_days'], $entry['problem_rows']];
            foreach ($values as $column => $value) {
                $people->setCellValue([$column + 1, $index + 2], $value);
            }
        }
        foreach (range(1, count($peopleHeaders)) as $column) {
            $people->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function download(Spreadsheet $spreadsheet, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array<int, string>  $headers */
    private function header(Worksheet $sheet, array $headers): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        $style = $sheet->getStyle([1, 1, count($headers), 1]);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
    }
}
