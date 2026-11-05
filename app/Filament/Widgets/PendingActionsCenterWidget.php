<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\HomeDashboardContext;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * The "Today" box at the top of home: a short list of today's work with a
 * count and one link each (HomeDashboardContext::todayTasks(), only pages
 * the user may open, active company only), followed by the remaining
 * action center items (onboarding tasks, appraisals, attendance flags…)
 * that the short list does not already show.
 */
class PendingActionsCenterWidget extends Widget
{
    protected string $view = 'filament.widgets.pending-actions-center';

    protected static bool $isLazy = false;

    protected static ?int $sort = -100;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $context = app(HomeDashboardContext::class);

        if (! $context->user()) {
            return ['data' => ['pending_count' => 0, 'items' => []], 'today' => ['mine' => [], 'team' => [], 'team_label' => ''], 'dateLabel' => ''];
        }

        $data = $context->pendingActions();
        $replaced = $context->todayReplacedActionIds();
        $items = array_values(array_filter($data['items'], fn (array $item): bool => ! in_array($item['id'], $replaced, true)));

        return [
            'data'      => ['pending_count' => count($items), 'items' => $items],
            'today'     => $context->todayTasks(),
            'dateLabel' => $context->now()->format('l j M'),
        ];
    }
}
