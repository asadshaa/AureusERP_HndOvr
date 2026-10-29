<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource;

class ListJobOffers extends ListRecords
{
    protected static string $resource = JobOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
