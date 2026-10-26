<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources\ScreeningQuestionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\ScreeningQuestionResource;

class ManageScreeningQuestions extends ManageRecords
{
    protected static string $resource = ScreeningQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->icon('heroicon-o-plus-circle'),
        ];
    }
}
