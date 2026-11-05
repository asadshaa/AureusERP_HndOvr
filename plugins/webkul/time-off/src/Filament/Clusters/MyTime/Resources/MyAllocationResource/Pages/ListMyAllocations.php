<?php

namespace Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyAllocationResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Support\Filament\Actions\HelpAction;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyAllocationResource;

class ListMyAllocations extends ListRecords
{
    protected static string $resource = MyAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('my-allocations'),
            CreateAction::make(),
        ];
    }
}
