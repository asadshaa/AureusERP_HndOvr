<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages;

use Webkul\Employee\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages\ListJobPositions as BaseListJobPositions;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Support\Filament\Actions\HelpAction;

class ListJobPositions extends BaseListJobPositions
{
    protected static string $resource = JobPositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('job-positions'),
        ];
    }
}
