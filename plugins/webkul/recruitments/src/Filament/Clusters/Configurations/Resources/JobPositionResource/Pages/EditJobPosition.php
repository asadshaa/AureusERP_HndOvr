<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages\EditJobPosition as BaseEditJobPosition;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Recruitment\Traits\HasJobPublicationActions;

class EditJobPosition extends BaseEditJobPosition
{
    use HasJobPublicationActions;

    protected static string $resource = JobPositionResource::class;

    protected function getHeaderActions(): array
    {
        $inherited = array_map(
            fn (Action $action): Action => $action instanceof DeleteAction
                ? $action->failureNotificationTitle('This job has ads that may still be up. Close it and confirm the ads are taken down first.')
                : $action,
            parent::getHeaderActions(),
        );

        return [
            ...$this->getJobPublicationActions(),
            ...$inherited,
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->prepareData($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->prepareData($data);
    }

    protected function afterSave(): void
    {
        $this->record->refresh();
    }

    public function prepareData($data): array
    {
        $model = $this->record;

        return array_merge($data, [
            'no_of_employee'       => $model->no_of_employee,
            'no_of_hired_employee' => $model->no_of_hired_employee,
            'expected_employees'   => $model->expected_employees,
        ]);
    }
}
