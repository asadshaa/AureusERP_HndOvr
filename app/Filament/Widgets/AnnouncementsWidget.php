<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\HomeDashboardContext;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Webkul\Employee\Filament\Pages\Announcements;
use Webkul\Employee\Models\Announcement;
use Webkul\Employee\Services\AnnouncementService;

/**
 * Company announcements on the home dashboard: up to three live ones for
 * the user's audience, pinned first, unread ones only (pinned stay until
 * they expire), with "Mark as read" / "Acknowledge" and "View all". Shown
 * only when there is something to read; one query, shared with canView()
 * through the scoped AnnouncementService.
 */
class AnnouncementsWidget extends Widget
{
    protected string $view = 'filament.widgets.home.announcements';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $context = app(HomeDashboardContext::class);

        if (! ($context->isEmployee() || $context->isHr() || $context->isCeo())) {
            return false;
        }

        return static::feed()->isNotEmpty();
    }

    public function markRead(int $announcementId): void
    {
        $this->record($announcementId, acknowledge: false);
    }

    public function acknowledge(int $announcementId): void
    {
        $this->record($announcementId, acknowledge: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $context = app(HomeDashboardContext::class);

        return [
            'announcements' => static::feed(),
            'allUrl'        => $context->pageUrl(Announcements::class),
        ];
    }

    /** @return Collection<int, Announcement> */
    protected static function feed(): Collection
    {
        $context = app(HomeDashboardContext::class);
        $user = $context->user();
        $companyId = $context->companyId();

        if (! $user || $companyId === null) {
            return new Collection;
        }

        // HomeDashboardContext already looked up the user's employee record (or found none).
        return app(AnnouncementService::class)->dashboardFeed($user, $companyId, $context->employee(), employeeKnown: true);
    }

    protected function record(int $announcementId, bool $acknowledge): void
    {
        $user = app(HomeDashboardContext::class)->user();
        $announcement = Announcement::query()->find($announcementId);

        if (! $user || ! $announcement instanceof Announcement) {
            return;
        }

        try {
            $acknowledge
                ? app(AnnouncementService::class)->acknowledge($announcement, $user)
                : app(AnnouncementService::class)->markRead($announcement, $user);
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('This announcement is not addressed to you.')->send();

            return;
        }

        Notification::make()->success()->title($acknowledge ? 'Thanks for acknowledging' : 'Marked as read')->send();
    }
}
