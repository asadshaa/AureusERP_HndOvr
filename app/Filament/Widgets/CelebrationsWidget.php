<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\HomeDashboardContext;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Webkul\Employee\Services\CelebrationService;

/**
 * "This week": birthdays (day and month only) and work anniversaries of
 * the active company, from CelebrationService's daily cache, with a "Send
 * wishes" button that sends the person an in-app note. Shown only when
 * someone celebrates this week.
 */
class CelebrationsWidget extends Widget
{
    protected string $view = 'filament.widgets.home.celebrations';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, string> rows wished from this card, "kind:employee" */
    public array $wished = [];

    public static function canView(): bool
    {
        $context = app(HomeDashboardContext::class);

        if (! ($context->isEmployee() || $context->isHr() || $context->isCeo()) || $context->companyId() === null) {
            return false;
        }

        return app(CelebrationService::class)->thisWeek($context->companyId()) !== [];
    }

    public function sendWishes(int $employeeId, string $kind): void
    {
        $user = app(HomeDashboardContext::class)->user();

        if (! $user || ! in_array($kind, [CelebrationService::Birthday, CelebrationService::Anniversary], true)) {
            return;
        }

        $sent = app(CelebrationService::class)->sendWishes($user, $employeeId, $kind);
        $this->wished[] = $kind.':'.$employeeId;

        $sent
            ? Notification::make()->success()->title('Wishes sent')->send()
            : Notification::make()->info()->title('Already sent today')->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $context = app(HomeDashboardContext::class);

        return [
            'rows'   => $context->companyId() === null ? [] : app(CelebrationService::class)->thisWeek($context->companyId()),
            'userId' => (int) $context->user()?->getKey(),
        ];
    }
}
