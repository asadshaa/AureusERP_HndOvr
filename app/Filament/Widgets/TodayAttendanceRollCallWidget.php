<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Enums\AttendanceVerificationStatus;
use Webkul\Employee\Filament\Pages\TodayAttendance as TodayAttendancePage;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Services\Attendance\TodayAttendance;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Dashboard roll call for today. The numbers come from the Today's
 * attendance page's service, so "On leave" is APPROVED time off only (a
 * pending request no longer hides an absence), "Not checked in" is the
 * real absent count (holidays, weekends, approved leave and On-Duty
 * excluded), and a line manager sees only their own people.
 */
class TodayAttendanceRollCallWidget extends Widget
{
    protected string $view = 'filament.widgets.today-attendance-roll-call';

    protected static bool $isLazy = false;

    protected static ?int $sort = -90;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return $user->hasRole(['Admin', 'Super Admin'])
            || (int) $user->id === 1
            || $user->hasRole(['Hr_manager', 'HR Administrator'])
            || $user->can(HrPermissions::ManageAttendance)
            || $user->can(HrPermissions::ViewAttendance);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = CurrentCompany::user();
        $companyId = CurrentCompany::id($user);
        $service = app(TodayAttendance::class);
        $counts = [];
        $dateLabel = now($service->timezone())->format('l, d M Y');
        $offDay = null;

        if ($user instanceof User && $companyId !== null) {
            try {
                $report = $service->forCompany($user, $companyId);
            } catch (AuthorizationException) {
                // Admin roles that may see the widget without the attendance permissions: whole active company, as before.
                $report = $service->forWholeCompany($companyId);
            }

            $counts = $report['counts'];
            $dateLabel = $report['date_label'];
            $offDay = $report['off_day'];
        }

        // The review queue is not limited to one day: every flagged punch of the company still waiting for HR.
        $needsReviewCount = $companyId === null ? 0 : AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->where('verification_status', AttendanceVerificationStatus::NeedsReview->value)
            ->count();

        $pageUrl = TodayAttendancePage::canAccess() ? TodayAttendancePage::getUrl() : null;
        $statusUrl = fn (array $statuses): ?string => $pageUrl ? TodayAttendancePage::getUrl(['filters' => ['status' => ['values' => $statuses]]]) : null;

        return [
            'totalEmployees'   => $counts['total'] ?? 0,
            'presentCount'     => $counts['present'] ?? 0,
            'lateCount'        => $counts['late'] ?? 0,
            'onLeaveCount'     => $counts['on_leave'] ?? 0,
            'notCheckedIn'     => $counts['absent'] ?? 0,
            'needsReviewCount' => $needsReviewCount,
            'attendanceUrl'    => AttendanceRecordResource::getUrl('index'),
            'offDay'           => $offDay,
            'todayUrl'         => $pageUrl,
            'presentUrl'       => $statusUrl([TodayAttendance::PRESENT, TodayAttendance::LATE, TodayAttendance::HALF_DAY, TodayAttendance::SHORT_LEAVE, TodayAttendance::MISSING_CHECK_OUT]),
            'lateUrl'          => $statusUrl([TodayAttendance::LATE]),
            'leaveUrl'         => $statusUrl([TodayAttendance::ON_LEAVE, TodayAttendance::HALF_DAY, TodayAttendance::SHORT_LEAVE]),
            'absentUrl'        => $statusUrl([TodayAttendance::ABSENT]),
            'todayDate'        => $dateLabel,
        ];
    }
}
