<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * The 11:00 HR digest (hr:today-attendance-digest): per company, "Today: N
 * absent, N late, N missing check-out from yesterday", sent as a database
 * notification to the company's HR managers with a link to Attendance ->
 * Today's attendance.
 *
 * Idempotent per company, day and recipient: the notification carries a
 * digest key, and a recipient who already has it for that company and day
 * is skipped, so a re-run or an overlap never notifies twice.
 */
class TodayAttendanceDigest
{
    /** Roles treated as HR, the same list HrHierarchyService gives company-wide visibility (Admin roles excluded). */
    public const HR_ROLES = [
        'hr',
        'hr_manager',
        'hr manager',
        'hr_ops_manager',
        'hr ops manager',
        'hr operations manager',
        'hr_administrator',
        'hr administrator',
        'human resources',
        'human resources manager',
    ];

    public function __construct(
        protected TodayAttendance $attendance,
    ) {}

    /**
     * Send the digest for every active company (or one).
     *
     * @return array{companies: int, notified: int, skipped: int}
     */
    public function send(?int $companyId = null, ?string $date = null): array
    {
        $date ??= $this->attendance->today();
        $result = ['companies' => 0, 'notified' => 0, 'skipped' => 0];

        $companies = Company::query()
            ->where('is_active', true)
            ->when($companyId, fn (Builder $query) => $query->whereKey($companyId))
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($companies as $company) {
            $recipients = $this->recipients((int) $company->id);

            if ($recipients->isEmpty()) {
                continue;
            }

            $summary = $this->summary((int) $company->id, $date);

            // Nothing to say on a weekend / holiday with no loose ends from yesterday.
            if ($summary['off_day'] && $summary['missing_check_out_yesterday'] === 0) {
                continue;
            }

            $result['companies']++;
            $key = $this->key((int) $company->id, $date);

            foreach ($recipients as $user) {
                if ($user->notifications()->where('data->viewData->today_attendance_digest', $key)->exists()) {
                    $result['skipped']++;

                    continue;
                }

                $user->notifyNow($this->notification($company, $summary, $key)->toDatabase());
                $result['notified']++;
            }
        }

        return $result;
    }

    /**
     * @return array{date: string, off_day: bool, absent: int, late: int, missing_check_out_yesterday: int}
     */
    public function summary(int $companyId, string $date): array
    {
        $today = $this->attendance->forWholeCompany($companyId, $date);
        $yesterday = $this->attendance->forWholeCompany(
            $companyId,
            CarbonImmutable::parse($date, $this->attendance->timezone())->subDay()->toDateString(),
        );

        return [
            'date'                        => $date,
            'off_day'                     => $today['off_day'] !== null,
            'absent'                      => $today['counts']['absent'],
            'late'                        => $today['counts']['late'],
            'missing_check_out_yesterday' => $yesterday['counts']['missing_check_out'],
        ];
    }

    /**
     * The company's HR managers: active members of the company with
     * hr_view_all_records or an HR role, who may open the attendance pages.
     *
     * @return Collection<int, User>
     */
    public function recipients(int $companyId): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('default_company_id', $companyId)
                ->orWhereHas('allowedCompanies', fn (Builder $companies) => $companies->whereKey($companyId)))
            ->with('roles')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => ($user->can(HrPermissions::ViewAllRecords) || $user->hasRole(self::HR_ROLES))
                && MonthlyAttendanceReport::canView($user)
                && CurrentCompany::canAccess($companyId, $user))
            ->values();
    }

    public function key(int $companyId, string $date): string
    {
        return $companyId.':'.$date;
    }

    /** @param  array{date: string, off_day: bool, absent: int, late: int, missing_check_out_yesterday: int}  $summary */
    protected function notification(Company $company, array $summary, string $key): Notification
    {
        return Notification::make()
            ->title("Today's attendance — {$company->name}")
            ->body(sprintf(
                'Today: %d absent, %d late, %d missing check-out from yesterday.',
                $summary['absent'],
                $summary['late'],
                $summary['missing_check_out_yesterday'],
            ))
            ->icon('heroicon-o-user-group')
            ->color($summary['absent'] + $summary['missing_check_out_yesterday'] > 0 ? 'warning' : 'success')
            ->viewData(['today_attendance_digest' => $key])
            ->actions([
                Action::make('view')
                    ->button()
                    ->label("View today's attendance")
                    ->url(route('filament.admin.pages.todays-attendance', ['company' => $company->id])),
            ]);
    }
}
