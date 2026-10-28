<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\HomeDashboardContext;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Employee\Filament\Pages\TodayAttendance as TodayAttendancePage;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\Attendance\TodayAttendance;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;

/**
 * A line manager's team on the home dashboard: who is in, late, absent or
 * on leave today (the Today's attendance service, limited to the manager's
 * reporting tree by HrHierarchyService) with names, and the team's time off
 * this week. Not shown to HR, who get the whole-company roll call instead.
 */
class TeamTodayWidget extends Widget
{
    protected string $view = 'filament.widgets.home.team-today';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** Names listed per status before "+N more". */
    protected const NAMES_SHOWN = 6;

    public static function canView(): bool
    {
        return app(HomeDashboardContext::class)->isLineManager();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $context = app(HomeDashboardContext::class);
        $user = $context->user();
        $companyId = $context->companyId();
        $teamIds = $context->teamIds();
        $now = $context->now();

        $groups = null;
        $offDay = null;

        if ($user && $companyId !== null && MonthlyAttendanceReport::canView($user)) {
            try {
                $report = app(TodayAttendance::class)->forCompany($user, $companyId);
                $rows = collect($report['rows'])->only($teamIds);
                $offDay = $report['off_day'];
                $groups = $this->groups($rows->all());
            } catch (AuthorizationException) {
                $groups = null;
            }
        }

        $weekStart = $now->startOfWeek();
        $weekEnd = $now->endOfWeek();

        $leaves = $teamIds === [] ? collect() : Leave::query()
            ->whereIn('employee_id', $teamIds)
            ->where('company_id', $companyId)
            ->whereIn('state', [State::VALIDATE_TWO->value, State::CONFIRM->value, State::VALIDATE_ONE->value])
            ->whereDate('request_date_from', '<=', $weekEnd->toDateString())
            ->whereDate('request_date_to', '>=', $weekStart->toDateString())
            ->with(['employee:id,name', 'holidayStatus:id,name'])
            ->orderBy('request_date_from')
            ->limit(10)
            ->get(['id', 'employee_id', 'holiday_status_id', 'state', 'request_date_from', 'request_date_to', 'number_of_days']);

        $pageUrl = $context->pageUrl(TodayAttendancePage::class);
        $statusUrl = fn (array $statuses): ?string => $pageUrl ? TodayAttendancePage::getUrl(['filters' => ['status' => ['values' => $statuses]]]) : null;

        return [
            'teamSize'  => count($teamIds),
            'groups'    => $groups,
            'offDay'    => $offDay,
            'dateLabel' => $now->format('l j M'),
            'pageUrl'   => $pageUrl,
            'urls'      => [
                'present'  => $statusUrl([TodayAttendance::PRESENT, TodayAttendance::LATE, TodayAttendance::HALF_DAY, TodayAttendance::SHORT_LEAVE, TodayAttendance::MISSING_CHECK_OUT]),
                'late'     => $statusUrl([TodayAttendance::LATE]),
                'absent'   => $statusUrl([TodayAttendance::ABSENT]),
                'on_leave' => $statusUrl([TodayAttendance::ON_LEAVE, TodayAttendance::HALF_DAY, TodayAttendance::SHORT_LEAVE]),
            ],
            'weekLabel' => $weekStart->format('j M').' – '.$weekEnd->format('j M'),
            'leaves'    => $leaves->map(fn (Leave $leave): array => [
                'employee' => (string) ($leave->employee?->name ?? 'Employee'),
                'type'     => (string) ($leave->holidayStatus?->name ?? 'Time off'),
                'dates'    => $this->range($leave),
                'approved' => ($leave->state instanceof State ? $leave->state->value : (string) $leave->state) === State::VALIDATE_TWO->value,
            ])->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{count: int, names: array<int, string>, more: int}>
     */
    protected function groups(array $rows): array
    {
        $rows = collect($rows);

        $pick = function (Collection $subset): array {
            $names = $subset->pluck('employee')->sort()->values();

            return [
                'count' => $names->count(),
                'names' => $names->take(self::NAMES_SHOWN)->all(),
                'more'  => max(0, $names->count() - self::NAMES_SHOWN),
            ];
        };

        return [
            'present'  => $pick($rows->where('checked_in', true)),
            'late'     => $pick($rows->where('late', true)),
            'absent'   => $pick($rows->where('status', TodayAttendance::ABSENT)),
            'on_leave' => $pick($rows->where('on_leave', true)),
        ];
    }

    protected function range(Leave $leave): string
    {
        $from = $leave->request_date_from ? Carbon::parse($leave->request_date_from)->format('D j M') : '';
        $to = $leave->request_date_to ? Carbon::parse($leave->request_date_to)->format('D j M') : $from;

        return $from === $to ? $from : $from.' – '.$to;
    }
}
