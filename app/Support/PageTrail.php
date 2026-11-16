<?php

namespace App\Support;

use App\Filament\Pages\Dashboard;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Contracts\HasLabel;
use Throwable;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

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
     * @return array{back: string, crumbs: array<int, array{label: string, url: ?string}>, companies: array<int, string>, active_company: ?int}|null null on the home page
     */
    public static function for(array $scopes): ?array
    {
        $pageClass = is_string($scopes[0] ?? null) ? $scopes[0] : null;
        $panel = Filament::getCurrentPanel();

        if ($pageClass === null || $panel === null || is_a($pageClass, Dashboard::class, true)) {
            return null;
        }

        $home = $panel->getUrl() ?? url('/');
        $companies = self::companiesFor($pageClass);
        $active = $companies === [] ? null : CurrentCompany::id();

        if (is_a($pageClass, ResourcePage::class, true)) {
            $resource = is_string($scopes[1] ?? null) ? $scopes[1] : null;
            $back = $home;

            if ($resource !== null && ! is_a($pageClass, ListRecords::class, true) && $resource::hasPage('index')) {
                $back = $resource::getUrl('index');
            }

            return ['back' => $back, 'crumbs' => [], 'companies' => $companies, 'active_company' => $active];
        }

        if (filled($pageClass::getCluster())) {
            return ['back' => $home, 'crumbs' => [], 'companies' => $companies, 'active_company' => $active];
        }

        $crumbs = [['label' => 'Home', 'url' => $home]];

        $group = self::groupLabel($pageClass);
        if ($group !== null) {
            $crumbs[] = ['label' => $group, 'url' => null];
        }

        $crumbs[] = ['label' => self::pageLabel($pageClass), 'url' => null];

        return ['back' => $home, 'crumbs' => $crumbs, 'companies' => $companies, 'active_company' => $active];
    }

    /**
     * The companies a person may switch between, offered on list and report pages
     * (not on a form for one record) and only when they belong to more than one.
     *
     * @return array<int, string> company id => name
     */
    private static function companiesFor(string $pageClass): array
    {
        if (is_a($pageClass, CreateRecord::class, true) || is_a($pageClass, EditRecord::class, true) || is_a($pageClass, ViewRecord::class, true)) {
            return [];
        }

        $user = CurrentCompany::user();
        $ids = $user ? CurrentCompany::membershipIds($user) : [];

        if (count($ids) < 2) {
            return [];
        }

        return Company::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
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
