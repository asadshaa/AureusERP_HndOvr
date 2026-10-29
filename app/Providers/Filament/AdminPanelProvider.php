<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
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
use App\Http\Middleware\ApplyBrandSettings;
use App\Http\Middleware\SetLocale;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Webkul\Manufacturing\ManufacturingPlugin;
use Webkul\Security\Http\Middleware\EnsureActiveCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Pages\Profile;
use Webkul\Support\GlobalSearchProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        if (! app()->runningInConsole()) {
            set_time_limit(300);
        }

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->favicon(asset('images/favicon.ico'))
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('2rem')
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->unsavedChangesAlerts()
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->userMenuItems([
                'profile' => Action::make('profile')
                    ->label(fn () => Auth::user()?->name)
                    ->url(fn (): string => Profile::getUrl()),
            ])
            ->navigationGroups(NavigationGroup::class)
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                HomeGreetingWidget::class,
                CompanySnapshotWidget::class,
                PendingActionsCenterWidget::class,
                MyDayWidget::class,
                TeamTodayWidget::class,
                TodayAttendanceRollCallWidget::class,
                RecruitmentPipelineWidget::class,
                EmployeeDashboardOverviewWidget::class,
                AnnouncementsWidget::class,
                CelebrationsWidget::class,
            ])
            ->plugins([
                ManufacturingPlugin::make(),
                FilamentShieldPlugin::make()
                    ->gridColumns([
                        'default' => 1,
                        'sm'      => 1,
                        'lg'      => 2,
                        'xl'      => 3,
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm'      => 1,
                        'lg'      => 2,
                        'xl'      => 3,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                        'sm'      => 2,
                    ]),
            ])
            ->globalSearch(provider: GlobalSearchProvider::class)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
                ApplyBrandSettings::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureActiveCompany::class,
            ])
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => view('filament.home.styles')->render(), scopes: Dashboard::class)
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('security::components.company-switcher')->render())
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ]);
    }
}
