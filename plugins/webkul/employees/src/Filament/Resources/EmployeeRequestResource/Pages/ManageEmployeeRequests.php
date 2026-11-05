<?php

namespace Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Support\Filament\Actions\HelpAction;

class ManageEmployeeRequests extends ManageRecords
{
    protected static string $resource = EmployeeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('my-requests'),
        ];
    }
}
