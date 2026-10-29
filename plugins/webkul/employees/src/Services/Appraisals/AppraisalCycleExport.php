<?php

namespace Webkul\Employee\Services\Appraisals;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;

/**
 * "Export to Excel" of a cycle's HR overview: one row per appraisal (status,
 * self / manager / final score, band, calibration, acknowledgement and the
 * manager's rating of every item), and a summary sheet with completion and
 * the rating distribution. HR of the cycle's (active) company only.
 */
class AppraisalCycleExport
{
    public function __construct(
        protected AppraisalAccess $access,
        protected PerformanceService $performance,
    ) {}

    public function spreadsheet(User $user, PerformanceCycle $cycle): Spreadsheet
    {
        abort_unless($this->access->canViewCycle($user, $cycle), 403);

        $items = AppraisalScoring::items($cycle);
        $reviews = $this->reviews($cycle);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Appraisals');

        $header = [
            'Employee code', 'Employee', 'Department', 'Job', 'Reviewer', 'Status', 'Self review submitted',
            'Self score', 'Manager score', 'Calibrated rating', 'Final score', 'Rating', 'Calibration reason',
            'Reviewed on', 'Acknowledged on', 'Acknowledgement comment',
        ];
        foreach ($items as $item) {
            $header[] = 'Manager: '.$item['title'];
        }

        $rows = [$header];

        foreach ($reviews as $review) {
            $employee = $review->employee;
            $ratings = AppraisalScoring::ratingsOf($review->manager_answers);
            $row = [
                $employee?->employee_number,
                $employee?->name,
                $employee?->department?->name,
                $employee?->job_title ?: $employee?->job?->name,
                $this->access->hasManager($review) ? $review->reviewer?->name : 'HR (no line manager)',
                AppraisalKinds::statusLabel($review->status),
                $review->submitted_at?->timezone(AppraisalKinds::timezone())->format('Y-m-d') ?? ($review->self_skipped_at ? 'Skipped' : null),
                $this->number($review->self_rating),
                $this->number($review->manager_rating),
                $this->number($review->calibrated_rating),
                $this->number($review->final_rating),
                $review->final_band,
                $review->calibration_reason,
                $review->completed_at?->timezone(AppraisalKinds::timezone())->format('Y-m-d'),
                $review->acknowledged_at?->timezone(AppraisalKinds::timezone())->format('Y-m-d'),
                $review->acknowledgement_comment,
            ];

            foreach (array_keys($items) as $key) {
                $row[] = $ratings[$key] ?? null;
            }

            $rows[] = $row;
        }

        $this->write($sheet, $rows);

        $stats = $this->performance->cycleStats($cycle);
        $summary = $spreadsheet->createSheet();
        $summary->setTitle('Summary');
        $summaryRows = [
            ['Cycle', $cycle->name],
            ['Period', $cycle->periodLabel()],
            ['Status', AppraisalKinds::cycleStatuses()[$cycle->status] ?? $cycle->status],
            ['Appraisals', $stats['total']],
            ['Reviewed', $stats['done']],
            ['Completion %', $stats['percent']],
            ['Average final score', $stats['average']],
            [],
            ['Status', 'Count'],
        ];
        foreach (AppraisalKinds::statuses() as $status => $label) {
            $summaryRows[] = [$label, $stats['statuses'][$status] ?? 0];
        }
        $summaryRows[] = [];
        $summaryRows[] = ['Rating', 'Count'];
        foreach ($stats['bands'] as $band) {
            $summaryRows[] = [$band['label'], $band['count']];
        }
        $this->write($summary, $summaryRows, autoFilter: false);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function download(User $user, PerformanceCycle $cycle): StreamedResponse
    {
        $spreadsheet = $this->spreadsheet($user, $cycle);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'Appraisals-'.Str::slug($cycle->name).'-'.now(AppraisalKinds::timezone())->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @return Collection<int, PerformanceReview> */
    protected function reviews(PerformanceCycle $cycle): Collection
    {
        return $cycle->reviews()
            ->with(['employee.department', 'employee.job', 'reviewer.user', 'employee.user'])
            ->get()
            ->sortBy(fn (PerformanceReview $review): string => mb_strtolower((string) $review->employee?->name))
            ->values();
    }

    protected function number(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    /** @param  array<int, array<int, mixed>>  $rows */
    protected function write(Worksheet $sheet, array $rows, bool $autoFilter = true): void
    {
        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $cell = $sheet->getCell([$columnIndex + 1, $rowIndex + 1]);

                is_float($value) || is_int($value)
                    ? $cell->setValue($value)
                    : $cell->setValueExplicit($value === null ? '' : (string) $value, DataType::TYPE_STRING);
            }
        }

        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);

        if ($autoFilter) {
            $sheet->freezePane('C2');
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        }

        foreach (range(1, max(1, max(array_map('count', $rows)))) as $index) {
            $sheet->getColumnDimensionByColumn($index)->setAutoSize(true);
        }
    }
}
