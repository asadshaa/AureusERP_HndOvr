<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Services\OrgChartService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Models\Company;

/**
 * Employees -> Org Chart (HR Parity 3.1): the active company's reporting
 * lines as a collapsible tree with search, a department filter and a
 * print view. Everything is sent once and expanded in the browser, so
 * opening levels and searching cost no request. OrgChartService decides
 * who appears (HrHierarchyService scope, own chain and peers).
 */
class OrgChart extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-share';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'employees/org-chart';

    protected string $view = 'employees::filament.pages.org-chart';

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return $user instanceof User && CurrentCompany::id($user) !== null;
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Org Chart';
    }

    public function getTitle(): string
    {
        return 'Org chart';
    }

    public function getSubheading(): ?string
    {
        $companyId = CurrentCompany::id();

        return $companyId !== null ? (string) Company::query()->whereKey($companyId)->value('name') : null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = CurrentCompany::user();
        $companyId = CurrentCompany::id($user);

        abort_unless($user instanceof User && $companyId !== null, 403);

        try {
            $chart = app(OrgChartService::class)->forUser($user, $companyId);
        } catch (AuthorizationException) {
            abort(403);
        }

        return [
            'chart'   => $chart,
            'viewUrl' => EmployeeResource::getUrl('view', ['record' => '__id__']),
        ];
    }
}
