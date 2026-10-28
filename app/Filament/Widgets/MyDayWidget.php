<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\HomeDashboardContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Security\Models\User;
use Webkul\Support\Models\CalendarLeave;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;
use Webkul\TimeOff\Models\Leave;

/**
 * The employee's day on the home dashboard: check in / check out status
 * (GeofencedAttendanceService::todayState, the My Attendance page's own
 * state), this month's grace balance (MonthlyAttendanceReport::graceLedger),
 * requests still waiting for approval and the next public holidays. Leave
 * balances stay in EmployeeDashboardOverviewWidget, shown right below.
 */
class MyDayWidget extends Widget
{
    protected string $view = 'filament.widgets.home.my-day';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(HomeDashboardContext::class)->isEmployee();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $context = app(HomeDashboardContext::class);
        $user = $context->user();
        $employee = $context->employee();

        if (! $user || ! $employee) {
            return ['attendance' => null, 'grace' => null, 'pending' => [], 'holidays' => [], 'urls' => []];
        }

        $now = $context->now();
        $attendanceUrl = $context->pageUrl(MyAttendance::class);

        return [
            'attendance' => $attendanceUrl ? $this->attendance($user) : null,
            'grace'      => $this->grace($user, $employee, $now),
            'pending'    => $this->pending($user, $employee, $context->companyId()),
            'holidays'   => $this->holidays($employee, $context->companyId(), $now),
            'urls'       => [
                'attendance'  => $attendanceUrl,
                'my_leaves'   => $context->resourceUrl(MyTimeOffResource::class),
                'my_requests' => $context->resourceUrl(EmployeeRequestResource::class),
            ],
        ];
    }

    /** @return array{state: string, label: string, detail: string, tone: string, button: string}|null */
    protected function attendance(User $user): ?array
    {
        $state = app(GeofencedAttendanceService::class)->todayState($user);

        if ($state === null) {
            return null;
        }

        return match ($state['state'] ?? 'none') {
            'checked_in' => [
                'state'  => 'checked_in',
                'label'  => 'Checked in',
                'detail' => 'Since '.($state['check_in'] ?? '—').(isset($state['elapsed_formatted']) && $state['elapsed_formatted'] ? ' · '.$state['elapsed_formatted'].' so far' : ''),
                'tone'   => 'success',
                'button' => 'Check out',
            ],
            'checked_out' => [
                'state'  => 'checked_out',
                'label'  => 'Checked out',
                'detail' => ($state['check_in'] ?? '—').' – '.($state['check_out'] ?? '—').($state['worked'] ? ' · '.$state['worked'].' worked' : ''),
                'tone'   => 'info',
                'button' => 'Check in again',
            ],
            default => [
                'state'  => 'not_checked_in',
                'label'  => 'Not checked in yet',
                'detail' => ($state['mode'] ?? 'none') === 'none' ? 'Attendance is not set up for you today.' : 'Tap Check in when you start work.',
                'tone'   => ($state['mode'] ?? 'none') === 'none' ? 'gray' : 'warning',
                'button' => 'Check in',
            ],
        };
    }

    /** @return array{month: string, allowance: string, used: string, balance: string, balance_minutes: int, percentage: int, tone: string}|null */
    protected function grace(User $user, Employee $employee, CarbonImmutable $now): ?array
    {
        try {
            $ledger = app(MonthlyAttendanceReport::class)->graceLedger($user, $employee, $now->format('Y-m'));
        } catch (AuthorizationException) {
            return null;
        }

        $available = max(0, (int) $ledger['allowance_minutes'] + (int) $ledger['carry_in_minutes'] + (int) $ledger['earned_minutes']);
        $balance = (int) $ledger['balance_minutes'];
        $percentage = $available > 0 ? max(0, min(100, (int) round($balance / $available * 100))) : 0;

        return [
            'month'           => $ledger['month_label'],
            'allowance'       => $this->minutes($available),
            'used'            => $this->minutes((int) $ledger['used_minutes']),
            'balance'         => ($balance < 0 ? '−' : '').$this->minutes(abs($balance)),
            'balance_minutes' => $balance,
            'percentage'      => $percentage,
            'tone'            => $balance <= 0 ? 'danger' : ($percentage < 30 ? 'warning' : 'success'),
        ];
    }

    /** @return array<int, array{label: string, count: int, url: ?string}> */
    protected function pending(User $user, Employee $employee, ?int $companyId): array
    {
        $leaves = Leave::query()
            ->where('employee_id', $employee->id)
            ->whereIn('state', [State::CONFIRM->value, State::VALIDATE_ONE->value])
            ->count();

        $requests = EmployeeRequest::query()
            ->where('company_id', $companyId)
            ->where('requested_by', $user->id)
            ->where('status', 'pending_approval')
            ->count();

        return [
            ['label' => 'Time off', 'count' => $leaves, 'url' => app(HomeDashboardContext::class)->resourceUrl(MyTimeOffResource::class)],
            ['label' => 'Other requests', 'count' => $requests, 'url' => app(HomeDashboardContext::class)->resourceUrl(EmployeeRequestResource::class)],
        ];
    }

    /** @return array<int, array{name: string, date: string, days: string}> */
    protected function holidays(Employee $employee, ?int $companyId, CarbonImmutable $now): array
    {
        $calendarId = $employee->calendar_id ? (int) $employee->calendar_id : null;

        return CalendarLeave::query()
            ->where(fn (Builder $query) => $query->where('time_type', 'leave')->orWhereNull('time_type'))
            ->where(fn (Builder $query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where(fn (Builder $query) => $query->whereNull('calendar_id')->when($calendarId, fn (Builder $inner) => $inner->orWhere('calendar_id', $calendarId)))
            ->whereNull('resource_id')
            ->whereDate('date_to', '>=', $now->toDateString())
            ->whereDate('date_from', '<=', $now->addDays(90)->toDateString())
            ->orderBy('date_from')
            ->limit(3)
            ->get(['id', 'name', 'date_from', 'date_to'])
            ->map(function (CalendarLeave $holiday) use ($now): array {
                $from = CarbonImmutable::parse($holiday->date_from)->startOfDay();
                $to = CarbonImmutable::parse($holiday->date_to)->startOfDay();
                $days = (int) $from->diffInDays($to) + 1;
                $until = (int) $now->startOfDay()->diffInDays($from, false);

                return [
                    'name' => (string) $holiday->name,
                    'date' => $days > 1 ? $from->format('D j M').' – '.$to->format('D j M') : $from->format('D j M'),
                    'days' => $until <= 0 ? 'Today' : ($until === 1 ? 'Tomorrow' : 'In '.$until.' days'),
                ];
            })
            ->all();
    }

    protected function minutes(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : $minutes.'m';
    }
}
