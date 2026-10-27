<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\InterviewResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\InterviewResource;

class ListInterviews extends ListRecords
{
    protected static string $resource = InterviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
