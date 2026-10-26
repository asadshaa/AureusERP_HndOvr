<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Enums\AttendanceVerificationStatus;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Services\CurrentCompany;
use Webkul\TimeOff\Models\Leave;

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
        $user = Auth::user();
        $companyId = (int) CurrentCompany::id();
        $today = Carbon::today(config('app.timezone', 'Asia/Karachi'))->toDateString();

        $totalEmployees = Employee::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->count();

        $todayRecords = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereDate('attendance_date', $today)
            ->get();

        $presentCount = $todayRecords->where('status', 'present')->count();
        $lateCount = $todayRecords->where('late_minutes', '>', 0)->count();

        // Approved leaves for today
        $onLeaveCount = Leave::query()
            ->where('company_id', $companyId)
            ->where('state', 'confirm')
            ->whereDate('date_from', '<=', $today)
            ->whereDate('date_to', '>=', $today)
            ->count();

        // Flagged punches pending HR review
        $needsReviewCount = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->where('verification_status', AttendanceVerificationStatus::NeedsReview->value)
            ->count();

        $notCheckedIn = max(0, $totalEmployees - ($presentCount + $onLeaveCount));

        $attendanceUrl = AttendanceRecordResource::getUrl('index');

        return [
            'totalEmployees'   => $totalEmployees,
            'presentCount'     => $presentCount,
            'lateCount'        => $lateCount,
            'onLeaveCount'     => $onLeaveCount,
            'notCheckedIn'     => $notCheckedIn,
            'needsReviewCount' => $needsReviewCount,
            'attendanceUrl'    => $attendanceUrl,
            'todayDate'        => Carbon::today()->format('l, d M Y'),
        ];
    }
}
