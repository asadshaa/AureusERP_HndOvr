<?php

namespace Webkul\Employee\Services\Attendance;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * The 10:30 line-manager digest (hr:line-manager-attendance-digest): each
 * line manager gets the late and absent people among their own direct
 * reports so far today, and nothing when there is nothing to report or on
 * a company weekend / holiday. HR is not a recipient as such (HR has the
 * 11:00 company digest, TodayAttendanceDigest).
 *
 * "Absent" counts only people already due (an approved first half off is
 * due at 14:00); approved leave, On-Duty and holidays are never absent.
 * Idempotent per company, day and manager.
 */
class LineManagerAttendanceDigest
{
    public function __construct(
        protected TodayAttendance $attendance,
        protected HierarchicalNotificationService $notifications,
    ) {}

    /**
     * @return array{companies: int, notified: int, skipped: int}
     */
    public function send(?int $companyId = null, ?string $date = null): array
    {
        $result = ['companies' => 0, 'notified' => 0, 'skipped' => 0];

        if (! EmployeeAttendanceNotifier::enabled('line_manager_digest')) {
            return $result;
        }

        $date ??= $this->attendance->today();
        $now = now($this->attendance->timezone());
        $secondsNow = $date === $now->toDateString()
            ? $now->hour * 3600 + $now->minute * 60 + $now->second
            : ($date < $now->toDateString() ? PHP_INT_MAX : -1);

        $companies = Company::query()
            ->where('is_active', true)
            ->when($companyId, fn (Builder $query) => $query->whereKey($companyId))
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($companies as $company) {
            $day = $this->attendance->forWholeCompany((int) $company->id, $date);

            if ($day['off_day'] !== null) {
                continue;
            }

            $teams = $this->teams($day['rows'], $secondsNow);
            if ($teams->isEmpty()) {
                continue;
            }

            $managers = Employee::query()
                ->where('company_id', $company->id)
                ->whereIn('id', $teams->keys()->all())
                ->whereNotNull('user_id')
                ->with('user')
                ->get(['id', 'company_id', 'user_id', 'name']);

            $result['companies']++;

            foreach ($managers as $manager) {
                $user = $manager->user;
                if (! $user instanceof User || ! $user->is_active || ! CurrentCompany::canAccess((int) $company->id, $user)) {
                    continue;
                }

                $team = $teams->get((int) $manager->id);
                $key = "attendance_team_digest_{$company->id}_{$date}_{$user->id}";

                $sent = $this->notifications->sendIdempotentNotification($user, $this->notification($company, $day, $team, $user), $key, [
                    'event'      => 'attendance_line_manager_digest',
                    'company_id' => (int) $company->id,
                    'manager_id' => (int) $manager->id,
                    'date'       => $date,
                    'absent'     => count($team['absent']),
                    'late'       => count($team['late']),
                ]);

                $result[$sent ? 'notified' : 'skipped']++;
            }
        }

        return $result;
    }

    /**
     * Late / absent rows grouped by line manager (employee id); managers with nothing to report are left out.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, array{absent: array<int, string>, late: array<int, string>}>
     */
    public function teams(array $rows, int $secondsNow): Collection
    {
        return collect($rows)
            ->filter(fn (array $row): bool => $row['line_manager_id'] !== null
                && $row['line_manager_id'] !== $row['employee_id']
                && ($row['late'] || $this->isAbsentSoFar($row, $secondsNow)))
            ->groupBy('line_manager_id')
            ->map(fn (Collection $team): array => [
                'absent' => $team->filter(fn (array $row): bool => $this->isAbsentSoFar($row, $secondsNow))
                    ->sortBy(fn (array $row): string => mb_strtolower($row['employee']))
                    ->pluck('employee')->values()->all(),
                'late' => $team->filter(fn (array $row): bool => $row['late'] && ! $this->isAbsentSoFar($row, $secondsNow))
                    ->sortBy(fn (array $row): string => mb_strtolower($row['employee']))
                    ->map(fn (array $row): string => $row['employee'].' ('.$this->minutes((int) $row['late_minutes']).')')
                    ->values()->all(),
            ]);
    }

    /** @param  array<string, mixed>  $row */
    protected function isAbsentSoFar(array $row, int $secondsNow): bool
    {
        $grace = max(0, (int) config('attendance.monthly_report.late_grace_minutes', 0)) * 60;

        return $row['status'] === TodayAttendance::ABSENT
            && ! $row['checked_in']
            && $row['due_start_seconds'] !== null
            && $secondsNow > $row['due_start_seconds'] + $grace;
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array{absent: array<int, string>, late: array<int, string>}  $team
     */
    protected function notification(Company $company, array $day, array $team, User $user): Notification
    {
        $lines = [];
        if ($team['absent'] !== []) {
            $lines[] = 'Absent ('.count($team['absent']).'): '.implode(', ', $team['absent']).'.';
        }
        if ($team['late'] !== []) {
            $lines[] = 'Late ('.count($team['late']).'): '.implode(', ', $team['late']).'.';
        }

        $notification = Notification::make()
            ->warning()
            ->icon('heroicon-o-user-group')
            ->title(sprintf('Your team today: %d absent, %d late', count($team['absent']), count($team['late'])))
            ->body($day['date_label'].' ('.$company->name.'). '.implode(' ', $lines));

        if (MonthlyAttendanceReport::canView($user)) {
            $notification->actions([
                Action::make('view')
                    ->button()
                    ->label("View today's attendance")
                    ->url(route('filament.admin.pages.todays-attendance', ['company' => $company->id])),
            ]);
        }

        return $notification;
    }

    protected function minutes(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : $minutes.'m';
    }
}
