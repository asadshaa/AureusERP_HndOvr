<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages;

use Webkul\Employee\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages\ViewJobPosition as BaseViewJobPosition;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Recruitment\Traits\HasJobPublicationActions;

class ViewJobPosition extends BaseViewJobPosition
{
    use HasJobPublicationActions;

    protected static string $resource = JobPositionResource::class;

    protected function getHeaderActions(): array
    {
        return JobPositionResource::actionsForRecordCompany([
            ...$this->getJobPublicationActions(),
            ...parent::getHeaderActions(),
        ], $this->getRecord());
    }
}
