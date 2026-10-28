<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\HomeDashboardContext;
use Filament\Widgets\Widget;

/**
 * Home header for everyone: "Good morning, Hina — Wednesday 28 Oct ·
 * ShipLine", the roles that apply, a hint about the company switcher for
 * people in more than one company, and big quick-action buttons for the
 * user's roles (each one checked against the target page's own access).
 */
class HomeGreetingWidget extends Widget
{
    protected string $view = 'filament.widgets.home.greeting';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(HomeDashboardContext::class)->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $context = app(HomeDashboardContext::class);
        $resolved = $context->resolve();

        return [
            'greeting'        => $context->greeting(),
            'firstName'       => $context->firstName(),
            'dateLabel'       => $context->now()->format('l j M'),
            'companyName'     => $resolved['company_name'],
            'membershipCount' => $resolved['membership_count'],
            'roles'           => $context->roleLabels(),
            'actions'         => $context->quickActions(),
        ];
    }
}
