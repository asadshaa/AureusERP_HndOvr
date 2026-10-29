<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AnnouncementsWidget;
use App\Filament\Widgets\CelebrationsWidget;
use App\Filament\Widgets\CompanySnapshotWidget;
use App\Filament\Widgets\EmployeeDashboardOverviewWidget;
use App\Filament\Widgets\HomeGreetingWidget;
use App\Filament\Widgets\MyDayWidget;
use App\Filament\Widgets\PendingActionsCenterWidget;
use App\Filament\Widgets\RecruitmentPipelineWidget;
use App\Filament\Widgets\TeamTodayWidget;
use App\Filament\Widgets\TodayAttendanceRollCallWidget;
use App\Services\Dashboard\HomeDashboardContext;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The home screen: one dashboard that adapts to the signed-in user's roles
 * (HomeDashboardContext). Only the home widgets are listed here, so the
 * plugin widgets discovered into the panel stay on their own dashboards
 * (Recruitment, Time Off, Accounting…) instead of each costing a request on
 * every home load. Every widget is rendered with the page (not lazy) and
 * re-checks its own canView() on the server.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Home';

    /**
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        $context = app(HomeDashboardContext::class);

        return array_values(array_filter([
            HomeGreetingWidget::class,
            $context->isCeo() ? CompanySnapshotWidget::class : null,
            PendingActionsCenterWidget::class,
            $context->isEmployee() || $context->isHr() || $context->isCeo() ? AnnouncementsWidget::class : null,
            $context->isEmployee() ? MyDayWidget::class : null,
            $context->isLineManager() ? TeamTodayWidget::class : null,
            $context->isHr() || $context->isCeo() ? TodayAttendanceRollCallWidget::class : null,
            $context->isRecruiter() ? RecruitmentPipelineWidget::class : null,
            $context->isEmployee() ? EmployeeDashboardOverviewWidget::class : null,
            $context->isEmployee() || $context->isHr() || $context->isCeo() ? CelebrationsWidget::class : null,
        ]));
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    /** The greeting card is the page header. */
    public function getHeading(): string|Htmlable
    {
        return '';
    }
}
