<?php

namespace Webkul\TimeOff\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Webkul\Employee\Models\Department;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\TimeOff\Services\LeaveBalanceService;
use Webkul\TimeOff\Services\LeaveCalendarService;
use Webkul\TimeOff\Services\LeaveOversight;

/**
 * Time Off -> Who's on leave: one month, day by day, the names of everyone
 * on approved leave (optionally also pending), for the active company and
 * the viewer's HR hierarchy (LeaveOversight: HR sees the company, a line
 * manager their team), with a department filter. The Overview calendar is
 * the year view; this is the per-day list.
 */
class LeaveCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'time-off/whos-on-leave';

    protected string $view = 'time-off::filament.pages.leave-calendar';

    #[Url]
    public ?string $month = null;

    #[Url(as: 'department')]
    public ?int $departmentId = null;

    #[Url(as: 'pending')]
    public bool $includePending = false;

    public static function canAccess(): bool
    {
        return LeaveOversight::canOversee();
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::TimeOff;
    }

    public static function getNavigationLabel(): string
    {
        return "Who's on leave";
    }

    public function getTitle(): string
    {
        return "Who's on leave";
    }

    public function mount(): void
    {
        if (! $this->month || ! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = LeaveBalanceService::today()->format('Y-m');
        }
    }

    public function previousMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->safeMonth().'-01')->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->safeMonth().'-01')->addMonth()->format('Y-m');
    }

    /** @return array<int, string> */
    public function getDepartmentOptions(): array
    {
        return Department::query()
            ->where('company_id', Auth::user()?->default_company_id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, mixed> */
    public function getCalendar(): array
    {
        return app(LeaveCalendarService::class)->month(
            (int) Auth::user()?->default_company_id,
            $this->safeMonth(),
            LeaveOversight::visibleEmployeeIds(),
            $this->departmentId ?: null,
            $this->includePending,
        );
    }

    private function safeMonth(): string
    {
        return $this->month && preg_match('/^\d{4}-\d{2}$/', $this->month)
            ? $this->month
            : LeaveBalanceService::today()->format('Y-m');
    }
}
