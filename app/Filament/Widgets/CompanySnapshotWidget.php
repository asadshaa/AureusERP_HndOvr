<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\CompanySnapshotService;
use App\Services\Dashboard\HomeDashboardContext;
use Filament\Widgets\Widget;

/**
 * CEO / admin: one card per company they belong to (headcount, checked in
 * today, open jobs, applications this month). Clicking a card switches the
 * active company (POST security.switch-company, which re-checks membership).
 */
class CompanySnapshotWidget extends Widget
{
    protected string $view = 'filament.widgets.home.company-snapshot';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(HomeDashboardContext::class)->isCeo();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = app(HomeDashboardContext::class)->user();

        return [
            'companies' => $user ? app(CompanySnapshotService::class)->forUser($user) : [],
        ];
    }
}
