<?php

namespace Webkul\Employee;

use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\WorkLocationResource;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Support\HrPermissions;
use Webkul\PluginManager\Package;
use Webkul\Support\Enums\NavigationGroup;

class EmployeePlugin implements Plugin
{
    public function getId(): string
    {
        return 'employees';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        if (! Package::isPluginInstalled($this->getId())) {
            return;
        }

        $panel
            ->when($panel->getId() == 'admin', function (Panel $panel) {
                $panel
                    ->discoverResources(
                        in: __DIR__.'/Filament/Resources',
                        for: 'Webkul\\Employee\\Filament\\Resources'
                    )
                    ->discoverPages(
                        in: __DIR__.'/Filament/Pages',
                        for: 'Webkul\\Employee\\Filament\\Pages'
                    )
                    ->discoverClusters(
                        in: __DIR__.'/Filament/Clusters',
                        for: 'Webkul\\Employee\\Filament\\Clusters'
                    )
                    ->discoverClusters(
                        in: __DIR__.'/Filament/Widgets',
                        for: 'Webkul\\Employee\\Filament\\Widgets'
                    )
                    ->navigationItems($this->attendanceNavigationItems())
                    // The installable "Aureus" check-in app: manifest, theme colour, service worker.
                    ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('employees::pwa.head')->render());
            });
    }

    /**
     * HR-side shortcuts in the Attendance section, mirroring how Time Off groups
     * "My Time" (employee) and "Management" (HR). The workplace / geofence
     * screen itself stays in Employees -> Configurations; this only links to it.
     *
     * @return array<int, NavigationItem>
     */
    protected function attendanceNavigationItems(): array
    {
        return [
            NavigationItem::make('Needs Review')
                ->group(NavigationGroup::Attendance)
                ->icon('heroicon-o-exclamation-triangle')
                ->sort(20)
                ->url(fn (): string => AttendanceRecordResource::getUrl('index', [
                    'filters' => ['verification_status' => ['value' => 'needs_review']],
                ]))
                ->visible(fn (): bool => (bool) Auth::user()?->can(HrPermissions::ReviewAttendanceVerifications)
                    && AttendanceRecordResource::canViewAny()),
            NavigationItem::make('Workplaces & Geofences')
                ->group(NavigationGroup::Attendance)
                ->icon('heroicon-o-map')
                ->sort(30)
                ->url(fn (): string => WorkLocationResource::getUrl('index'))
                ->visible(fn (): bool => (bool) Auth::user()?->can('view_any_employee_work::location')),
        ];
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
