<?php

namespace Webkul\Employee\Filament\Resources\PerformanceCycleResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource;
use Webkul\Support\Filament\Actions\HelpAction;

class ManagePerformanceCycles extends ManageRecords
{
    protected static string $resource = PerformanceCycleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('appraisals'),
        ];
    }
}
