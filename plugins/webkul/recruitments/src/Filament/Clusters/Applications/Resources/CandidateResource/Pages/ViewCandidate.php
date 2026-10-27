<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Webkul\Chatter\Filament\Actions\ChatterAction;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Traits\HandlesEmployeeConversion;
use Webkul\Support\Traits\HasRecordNavigationTabs;

class ViewCandidate extends ViewRecord
{
    use HandlesEmployeeConversion;
    use HasRecordNavigationTabs;

    protected static string $resource = CandidateResource::class;

    protected function getHeaderActions(): array
    {
        return CandidateResource::actionsForRecordCompany([
            Action::make('gotoEmployee')
                ->tooltip(__('recruitments::filament/clusters/applications/resources/candidate/pages/view-candidate.goto-employee-tooltip'))
                ->visible(fn ($record) => $record->employee_id)
                ->icon('heroicon-s-arrow-top-right-on-square')
                ->iconButton()
                ->action(fn (Candidate $record) => $this->convertAndRedirect(fn () => $record->createEmployee(), 'view')),
            Action::make('createEmployee')
                ->label(__('recruitments::filament/clusters/applications/resources/candidate/pages/view-candidate.create-employee'))
                ->hidden(fn ($record) => $record->employee_id)
                ->visible(fn (): bool => static::userCanConvert())
                ->action(fn (Candidate $record) => $this->convertAndRedirect(fn () => $record->createEmployee(), 'edit')),
            CandidateResource::downloadCvAction(),
            ChatterAction::make()
                ->resource(static::$resource)
                ->activityPlans($this->getRecord()->activityPlans()),
            DeleteAction::make()
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('recruitments::filament/clusters/applications/resources/candidate/pages/view-candidate.header-actions.delete.notification.title'))
                        ->body(__('recruitments::filament/clusters/applications/resources/candidate/pages/view-candidate.header-actions.delete.notification.body'))
                ),
        ], $this->getRecord(), ['downloadCv']);
    }
}
