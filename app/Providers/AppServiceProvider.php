<?php

namespace App\Providers;

use App\Listeners\NotifyUsersOnMoveConfirmed;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Edge\Components\Navigation\BottomNav;
use Native\Mobile\Edge\Components\Navigation\BottomNavItem;
use Native\Mobile\Edge\Components\Navigation\Fab;
use Native\Mobile\Edge\Components\Navigation\HorizontalDivider;
use Native\Mobile\Edge\Components\Navigation\SideNav;
use Native\Mobile\Edge\Components\Navigation\SideNavGroup;
use Native\Mobile\Edge\Components\Navigation\SideNavHeader;
use Native\Mobile\Edge\Components\Navigation\SideNavItem;
use Native\Mobile\Edge\Components\Navigation\TopBar;
use Native\Mobile\Edge\Components\Navigation\TopBarAction;
use Webkul\Account\Events\MoveConfirmed;
use Webkul\Security\Models\User;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Authenticatable::class, User::class);

        $this->registerNativeMobileComponents();
    }

    public function boot(): void
    {
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        // Every list shows the newest records first (Filament orders by the record key as a final
        // tie-breaker; this makes that descending). Tables with their own defaultSort keep it.
        Table::configureUsing(fn (Table $table): Table => $table->defaultSort(null, 'desc'));

        Event::listen(
            MoveConfirmed::class,
            NotifyUsersOnMoveConfirmed::class
        );
    }

    private function registerNativeMobileComponents(): void
    {
        foreach (
            [
                'native-bottom-nav'         => BottomNav::class,
                'native-bottom-nav-item'    => BottomNavItem::class,
                'native-fab'                => Fab::class,
                'native-horizontal-divider' => HorizontalDivider::class,
                'native-side-nav'           => SideNav::class,
                'native-side-nav-group'     => SideNavGroup::class,
                'native-side-nav-header'    => SideNavHeader::class,
                'native-side-nav-item'      => SideNavItem::class,
                'native-top-bar'            => TopBar::class,
                'native-top-bar-action'     => TopBarAction::class,
            ] as $alias => $componentClass
        ) {
            Blade::component($alias, $componentClass);
        }
    }
}
