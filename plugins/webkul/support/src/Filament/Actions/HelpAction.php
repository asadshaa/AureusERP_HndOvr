<?php

namespace Webkul\Support\Filament\Actions;

use Filament\Actions\Action;
use Illuminate\Support\Facades\Route;

/**
 * The "Help" header button of the HR screens: a link to the matching
 * how-to section of the HR help page (Employees plugin, /admin/hr-help).
 * Resolved by route name so plugins that do not depend on Employees can
 * use it; the button is hidden when that page is not installed.
 */
class HelpAction
{
    public const ROUTE = 'filament.admin.pages.hr-help';

    public static function make(string $topic): Action
    {
        return Action::make('help')
            ->label('Help')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->tooltip('How this screen works')
            ->url(fn (): ?string => static::url($topic))
            ->visible(fn (): bool => Route::has(static::ROUTE));
    }

    public static function url(string $topic): ?string
    {
        return Route::has(static::ROUTE) ? route(static::ROUTE).'#'.$topic : null;
    }
}
