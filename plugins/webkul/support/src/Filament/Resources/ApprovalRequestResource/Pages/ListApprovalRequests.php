<?php

namespace Webkul\Support\Filament\Resources\ApprovalRequestResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Webkul\Support\Filament\Actions\HelpAction;
use Webkul\Support\Filament\Resources\ApprovalRequestResource;

class ListApprovalRequests extends ListRecords
{
    protected static string $resource = ApprovalRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('approvals'),
        ];
    }
}
