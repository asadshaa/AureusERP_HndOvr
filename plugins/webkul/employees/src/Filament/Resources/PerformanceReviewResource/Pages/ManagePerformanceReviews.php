<?php

namespace Webkul\Employee\Filament\Resources\PerformanceReviewResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource;
use Webkul\Support\Filament\Actions\HelpAction;

class ManagePerformanceReviews extends ManageRecords
{
    protected static string $resource = PerformanceReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('appraisals'),
        ];
    }
}
