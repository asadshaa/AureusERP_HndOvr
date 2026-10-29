<?php

namespace Webkul\Employee\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Webkul\Security\Models\User;

/**
 * Employees -> Reportings -> Joiners & leavers (HR Parity 3.7): who joined
 * and who left between two dates, month by month, with the departure
 * reasons and the headcount at both ends of the period. Joining and leaving
 * dates as EmployeeMovements derives them (archived employees included).
 */
class JoinersLeaversReport
{
    public const NO_REASON = 'Not recorded';

    public function __construct(
        protected HrReportScope $scope,
        protected EmployeeMovements $movements,
    ) {}

    /**
     * @param  string  $from  Y-m-d
     * @param  string  $to  Y-m-d
     * @return array{
     *     company: array{id: int, name: string}, department: ?string, generated_at: CarbonImmutable, generated_by: string,
     *     from: string, to: string, period_label: string,
     *     summary: array{joiners: int, leavers: int, net: int, opening: int, closing: int, turnover_percent: float},
     *     months: array<int, array{month: string, label: string, joiners: int, leavers: int, net: int}>,
     *     reasons: array<int, array{reason: string, count: int, percent: float}>,
     *     joiners: array<int, array<string, mixed>>,
     *     leavers: array<int, array<string, mixed>>
     * }
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function build(User $user, int $companyId, string $from, string $to, ?int $departmentId = null): array
    {
        $start = CarbonImmutable::parse($from, HrReportScope::timezone())->startOfDay();
        $end = CarbonImmutable::parse($to, HrReportScope::timezone())->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }

        $people = $this->movements->people($user, $companyId, $departmentId);
        $from = $start->toDateString();
        $to = $end->toDateString();
        $dayBefore = $start->subDay()->toDateString();

        $joiners = $people->filter(fn (array $person): bool => $person['joined'] >= $from && $person['joined'] <= $to)
            ->sortBy([['joined', 'asc'], ['name', 'asc']])
            ->values();
        $leavers = $people->filter(fn (array $person): bool => $person['left'] !== null && $person['left'] >= $from && $person['left'] <= $to)
            ->sortBy([['left', 'asc'], ['name', 'asc']])
            ->values();

        $opening = $people->filter(fn (array $person): bool => EmployeeMovements::onBooks($person, $dayBefore))->count();
        $closing = $people->filter(fn (array $person): bool => EmployeeMovements::onBooks($person, $to))->count();
        $average = ($opening + $closing) / 2;

        $months = [];
        for ($month = $start->startOfMonth(); $month->lte($end); $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $monthJoiners = $joiners->filter(fn (array $person): bool => str_starts_with($person['joined'], $key))->count();
            $monthLeavers = $leavers->filter(fn (array $person): bool => str_starts_with((string) $person['left'], $key))->count();
            $months[] = [
                'month'   => $key,
                'label'   => $month->format('M Y'),
                'joiners' => $monthJoiners,
                'leavers' => $monthLeavers,
                'net'     => $monthJoiners - $monthLeavers,
            ];
        }

        $reasons = $leavers->countBy(fn (array $person): string => $person['reason'] ?? self::NO_REASON)
            ->sortDesc()
            ->map(fn (int $count, string $reason): array => [
                'reason'  => $reason,
                'count'   => $count,
                'percent' => round($count / max(1, $leavers->count()) * 100, 1),
            ])
            ->values()
            ->all();

        return $this->scope->header($user, $companyId, $departmentId) + [
            'from'         => $from,
            'to'           => $to,
            'period_label' => $start->format('d M Y').' – '.$end->format('d M Y'),
            'summary'      => [
                'joiners'          => $joiners->count(),
                'leavers'          => $leavers->count(),
                'net'              => $joiners->count() - $leavers->count(),
                'opening'          => $opening,
                'closing'          => $closing,
                'turnover_percent' => $average > 0 ? round($leavers->count() / $average * 100, 1) : 0.0,
            ],
            'months'       => $months,
            'reasons'      => $reasons,
            'joiners'      => $joiners->all(),
            'leavers'      => $leavers->map(fn (array $person): array => $person + ['tenure' => $this->tenure($person['joined'], (string) $person['left'])])->all(),
        ];
    }

    /** Time from joining to leaving, e.g. "2 y 3 m" or "5 m". */
    public function tenure(string $joined, string $left): string
    {
        $months = (int) CarbonImmutable::parse($joined)->diffInMonths(CarbonImmutable::parse($left)->addDay());
        $years = intdiv($months, 12);
        $rest = $months % 12;

        return match (true) {
            $years > 0 && $rest > 0 => "{$years} y {$rest} m",
            $years > 0              => "{$years} y",
            default                 => "{$rest} m",
        };
    }
}
