<?php

namespace Webkul\Recruitment\Traits;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Services\JobOpeningPublicationService;
use Webkul\Recruitment\Services\JobPostingService;

/**
 * Posting lifecycle buttons for the job opening View/Edit pages. Each button
 * calls JobOpeningPublicationService, which authorises the user and enforces
 * the allowed transitions; visibility here only hides buttons that cannot apply.
 */
trait HasJobPublicationActions
{
    /** @return array<int, Action> */
    protected function getJobPublicationActions(): array
    {
        return [
            $this->jobPublicationAction('submitForApproval', 'Submit for approval', 'heroicon-o-paper-airplane', 'primary', [JobPostingStatus::DRAFT], 'Submitted for approval', 'The approver has been notified.'),
            $this->jobPublicationAction('publish', 'Publish', 'heroicon-o-globe-alt', 'success', [JobPostingStatus::APPROVED, JobPostingStatus::PAUSED], 'Job published', null),
            $this->jobPublicationAction('pause', 'Pause', 'heroicon-o-pause-circle', 'warning', [JobPostingStatus::PUBLISHED], 'Job paused', null),
            $this->jobPublicationAction('close', 'Close job', 'heroicon-o-lock-closed', 'danger', [JobPostingStatus::APPROVED, JobPostingStatus::PUBLISHED, JobPostingStatus::PAUSED], 'Job closed', null)
                ->modalDescription(fn (JobPosition $record): ?string => app(JobPostingService::class)->closeImpactSummary($record))
                ->requiresConfirmation(),
            $this->jobPublicationAction('reopen', 'Reopen as draft', 'heroicon-o-arrow-uturn-left', 'gray', [JobPostingStatus::CLOSED], 'Job reopened', 'It must be approved again before it is published.')
                ->requiresConfirmation(),
        ];
    }

    /** @param array<int, JobPostingStatus> $fromStatuses */
    private function jobPublicationAction(string $method, string $label, string $icon, string $color, array $fromStatuses, string $successTitle, ?string $successBody): Action
    {
        return Action::make('job_'.$method)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (JobPosition $record): bool => in_array($record->postingStatus(), $fromStatuses, true)
                && (bool) Auth::user()?->can('update', $record))
            ->action(function (JobPosition $record) use ($method, $successTitle, $successBody): void {
                try {
                    app(JobOpeningPublicationService::class)->{$method}($record, Auth::user());
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Could not update the job opening')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($successTitle)->body($successBody)->send();
                $this->refreshFormData(['posting_status', 'published_at']);
                $this->dispatch('job-postings-changed');
            });
    }
}
