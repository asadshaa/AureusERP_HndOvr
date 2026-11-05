<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource;
use Webkul\Support\Filament\Actions\HelpAction;

class ListJobOffers extends ListRecords
{
    protected static string $resource = JobOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('job-offers'),
        ];
    }
}
