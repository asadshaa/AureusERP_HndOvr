<?php

namespace App\Support;

use App\Filament\Pages\Dashboard;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Contracts\HasLabel;
use Throwable;

/**
 * The "Back" button and the "Home > Group > Page" trail shown above every page.
 * Back returns to the page the person came from (the browser keeps their
 * filters and page number); only when there is no earlier page in this visit
 * (for example a bookmarked link) does it use the fallback computed here: the
 * list a record belongs to, or Home. Resource pages already carry Filament's
 * own breadcrumbs (list > record > action), so only plain pages get a trail.
 */
final class PageTrail
{
    /**
     * @param  array<int, mixed>  $scopes  the render-hook scopes of the page: page class first, resource class second
     * @return array{back: string, crumbs: array<int, array{label: string, url: ?string}>}|null null on the home page
     */
    public static function for(array $scopes): ?array
    {
        $pageClass = is_string($scopes[0] ?? null) ? $scopes[0] : null;
        $panel = Filament::getCurrentPanel();

        if ($pageClass === null || $panel === null || is_a($pageClass, Dashboard::class, true)) {
            return null;
        }

        $home = $panel->getUrl() ?? url('/');

        if (is_a($pageClass, ResourcePage::class, true)) {
            $resource = is_string($scopes[1] ?? null) ? $scopes[1] : null;
            $back = $home;

            if ($resource !== null && ! is_a($pageClass, ListRecords::class, true) && $resource::hasPage('index')) {
                $back = $resource::getUrl('index');
            }

            return ['back' => $back, 'crumbs' => []];
        }

        if (filled($pageClass::getCluster())) {
            return ['back' => $home, 'crumbs' => []];
        }

        $crumbs = [['label' => 'Home', 'url' => $home]];

        $group = self::groupLabel($pageClass);
        if ($group !== null) {
            $crumbs[] = ['label' => $group, 'url' => null];
        }

        $crumbs[] = ['label' => self::pageLabel($pageClass), 'url' => null];

        return ['back' => $home, 'crumbs' => $crumbs];
    }

    private static function groupLabel(string $pageClass): ?string
    {
        $group = $pageClass::getNavigationGroup();

        if ($group instanceof HasLabel) {
            return (string) $group->getLabel();
        }

        return is_string($group) && $group !== '' ? $group : null;
    }

    private static function pageLabel(string $pageClass): string
    {
        try {
            return (string) $pageClass::getNavigationLabel();
        } catch (Throwable) {
            return (string) str(class_basename($pageClass))->headline();
        }
    }
}
