<?php

namespace Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;
use Webkul\TimeOff\Traits\TimeOffHelper;

class CreateMyTimeOff extends CreateRecord
{
    use TimeOffHelper;

    protected static string $resource = MyTimeOffResource::class;

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

    protected function getCreatedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title(__('time-off::filament/clusters/my-time/resources/my-time-off/pages/create-time-off.notification.success.title'))
            ->body('Saved. Press Submit for approval on the request to send it to your line manager.');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->mutateTimeOffData($data, $this->record?->id);
    }
}
