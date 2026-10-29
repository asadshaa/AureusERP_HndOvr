<?php

namespace Webkul\Employee\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Security\Models\User;

/**
 * Employees -> Reportings -> Headcount trend (HR Parity 3.7): the monthly
 * headcount over the last 12-24 months, computed from joining and leaving
 * dates (EmployeeMovements), overall and per department, with turnover.
 *
 * For each month: opening = on the books at the close of the day before
 * the month; joiners and leavers = joining / leaving dates in the month;
 * closing = on the books at the close of the month (of today for the
 * current month), so closing = opening + joiners - leavers. Turnover % =
 * leavers / average of opening and closing x 100; for the whole period,
 * all leavers / the average monthly headcount x 100. Departments are the
 * employees' current departments.
 */
class HeadcountTrendReport
{
    /** @var array<int, int> */
    public const PERIODS = [12, 18, 24];

    public function __construct(
        protected HrReportScope $scope,
        protected EmployeeMovements $movements,
    ) {}

    /**
     * @param  string  $endMonth  Y-m, the last month shown (never after the current month)
     * @return array{
     *     company: array{id: int, name: string}, department: ?string, generated_at: CarbonImmutable, generated_by: string,
     *     period_label: string, as_of: string,
     *     months: array<int, array{month: string, label: string, as_of: string, opening: int, joiners: int, leavers: int, closing: int, turnover_percent: float}>,
     *     summary: array{opening: int, closing: int, joiners: int, leavers: int, change: int, average_headcount: float, turnover_percent: float},
     *     departments: array<int, array{name: string, closing: array<string, int>}>,
     *     chart: array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     * }
     *
     * @throws AuthorizationException
     */
    public function build(User $user, int $companyId, string $endMonth, int $months = 12, ?int $departmentId = null): array
    {
        $months = max(1, min(36, $months));
        $today = HrReportScope::now()->startOfDay();
        $last = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $endMonth) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $endMonth, HrReportScope::timezone())
            : $today->startOfMonth();
        if ($last->gt($today->startOfMonth())) {
            $last = $today->startOfMonth();
        }

        $people = $this->movements->people($user, $companyId, $departmentId)->all();

        $rows = [];
        $byDepartment = [];
        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $monthStart = $last->subMonthsNoOverflow($offset);
            $key = $monthStart->format('Y-m');
            $asOf = min($monthStart->endOfMonth()->toDateString(), $today->toDateString());
            $dayBefore = $monthStart->subDay()->toDateString();
            $firstDay = $monthStart->toDateString();

            $opening = $joiners = $leavers = $closing = 0;
            foreach ($people as $person) {
                $opening += (int) EmployeeMovements::onBooks($person, $dayBefore);
                $joiners += (int) ($person['joined'] >= $firstDay && $person['joined'] <= $asOf);
                $leavers += (int) ($person['left'] !== null && $person['left'] >= $firstDay && $person['left'] <= $asOf);

                if (EmployeeMovements::onBooks($person, $asOf)) {
                    $closing++;
                    $byDepartment[$person['department']][$key] = ($byDepartment[$person['department']][$key] ?? 0) + 1;
                }
            }

            $rows[] = [
                'month'            => $key,
                'label'            => $monthStart->format('M Y'),
                'as_of'            => $asOf,
                'opening'          => $opening,
                'joiners'          => $joiners,
                'leavers'          => $leavers,
                'closing'          => $closing,
                'turnover_percent' => $this->turnover($leavers, ($opening + $closing) / 2),
            ];
        }

        $monthKeys = array_column($rows, 'month');
        ksort($byDepartment, SORT_NATURAL | SORT_FLAG_CASE);
        $departments = [];
        foreach ($byDepartment as $name => $counts) {
            $departments[] = [
                'name'    => (string) $name,
                'closing' => array_combine($monthKeys, array_map(fn (string $key): int => $counts[$key] ?? 0, $monthKeys)),
            ];
        }

        $totalLeavers = array_sum(array_column($rows, 'leavers'));
        $averageHeadcount = array_sum(array_map(fn (array $row): float => ($row['opening'] + $row['closing']) / 2, $rows)) / count($rows);
        $first = $rows[0];
        $lastRow = $rows[count($rows) - 1];

        return $this->scope->header($user, $companyId, $departmentId) + [
            'period_label' => $first['label'].' – '.$lastRow['label'],
            'as_of'        => $lastRow['as_of'],
            'months'       => $rows,
            'summary'      => [
                'opening'           => $first['opening'],
                'closing'           => $lastRow['closing'],
                'joiners'           => array_sum(array_column($rows, 'joiners')),
                'leavers'           => $totalLeavers,
                'change'            => $lastRow['closing'] - $first['opening'],
                'average_headcount' => round($averageHeadcount, 1),
                'turnover_percent'  => $this->turnover($totalLeavers, $averageHeadcount),
            ],
            'departments'  => $departments,
            'chart'        => $this->chart($rows),
        ];
    }

    protected function turnover(int $leavers, float $averageHeadcount): float
    {
        return $averageHeadcount > 0 ? round($leavers / $averageHeadcount * 100, 1) : 0.0;
    }

    /**
     * Chart.js data: closing headcount as a line, joiners and leavers as bars
     * on a second axis.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    protected function chart(array $rows): array
    {
        return [
            'labels'   => array_column($rows, 'label'),
            'datasets' => [
                [
                    'type'            => 'line',
                    'label'           => 'Headcount',
                    'data'            => array_column($rows, 'closing'),
                    'borderColor'     => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
                    'fill'            => true,
                    'tension'         => 0.25,
                    'yAxisID'         => 'y',
                    'order'           => 0,
                ],
                [
                    'type'            => 'bar',
                    'label'           => 'Joiners',
                    'data'            => array_column($rows, 'joiners'),
                    'backgroundColor' => '#16a34a',
                    'yAxisID'         => 'y1',
                    'maxBarThickness' => 18,
                    'order'           => 1,
                ],
                [
                    'type'            => 'bar',
                    'label'           => 'Leavers',
                    'data'            => array_column($rows, 'leavers'),
                    'backgroundColor' => '#dc2626',
                    'yAxisID'         => 'y1',
                    'maxBarThickness' => 18,
                    'order'           => 1,
                ],
            ],
        ];
    }
}
