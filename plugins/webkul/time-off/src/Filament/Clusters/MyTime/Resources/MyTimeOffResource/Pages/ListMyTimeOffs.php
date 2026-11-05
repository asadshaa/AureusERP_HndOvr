<?php

namespace Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Support\Filament\Actions\HelpAction;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;

class ListMyTimeOffs extends ListRecords
{
    protected static string $resource = MyTimeOffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('my-leave'),
            CreateAction::make()
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
