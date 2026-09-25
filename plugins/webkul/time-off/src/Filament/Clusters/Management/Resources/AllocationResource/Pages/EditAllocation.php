<?php

namespace Webkul\TimeOff\Filament\Clusters\Management\Resources\AllocationResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Filament\Actions\ChatterAction;
use Webkul\Employee\Support\HrPermissions;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\AllocationResource;

class EditAllocation extends EditRecord
{
    protected static string $resource = AllocationResource::class;

    public function getSubNavigation(): array
    {
        if (filled($cluster = static::getCluster())) {
            return $this->generateNavigationItems($cluster::getClusteredComponents());
        }

        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.notification.title'))
            ->body(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.notification.body'));
    }

    protected function getHeaderActions(): array
    {
        return [
            ChatterAction::make()
                ->resource(static::$resource)
                ->activityPlans($this->getRecord()->activityPlans()),
            Action::make('approved')
                ->label(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.approved.title'))
                ->color('gray')
                ->authorize(HrPermissions::ApproveLeave)
                ->hidden(fn ($record) => $record->state !== State::CONFIRM->value)
                ->action(function ($record) {
                    // Same self-approval guard as the Management list's approve
                    // action: this page is reached through AllocationResource,
                    // whose query includes the viewer's own record.
                    if ((int) $record->employee?->user_id === (int) Auth::id()) {
                        Notification::make()->danger()->title('You cannot approve your own leave allocation.')->send();

                        return;
                    }

                    $record->update(['state' => State::VALIDATE_TWO->value]);

                    $this->refreshFormData(['state']);

                    Notification::make()
                        ->success()
                        ->title(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.approved.notification.title'))
                        ->body(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.approved.notification.body'))
                        ->send();
                }),
            Action::make('refuse')
                ->label(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.refuse.title'))
                ->color('gray')
                ->authorize(HrPermissions::ApproveLeave)
                ->hidden(fn ($record) => $record->state === State::REFUSE->value)
                ->action(function ($record) {
                    if ((int) $record->employee?->user_id === (int) Auth::id()) {
                        Notification::make()->danger()->title('You cannot refuse your own leave allocation.')->send();

                        return;
                    }

                    $record->update(['state' => State::REFUSE->value]);

                    $this->refreshFormData(['state']);

                    Notification::make()
                        ->success()
                        ->title(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.refuse.notification.title'))
                        ->body(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.refuse.notification.body'))
                        ->send();
                }),
            Action::make('mark_as_ready_to_confirm')
                ->label(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.mark-as-ready-to-confirm.title'))
                ->color('gray')
                ->authorize(HrPermissions::ApproveLeave)
                ->visible(fn ($record) => $record->state === State::REFUSE->value)
                ->action(function ($record) {
                    $record->update(['state' => State::CONFIRM->value]);

                    $this->refreshFormData(['state']);

                    Notification::make()
                        ->success()
                        ->title(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.mark-as-ready-to-confirm.notification.title'))
                        ->body(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.mark-as-ready-to-confirm.notification.body'))
                        ->send();
                }),
            DeleteAction::make()
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.delete.notification.title'))
                        ->body(__('time-off::filament/clusters/management/resources/allocation/pages/edit-allocation.header-actions.delete.notification.body'))
                ),
        ];
    }
}
