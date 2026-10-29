<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources\AppraisalTemplateResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\AppraisalTemplateResource;
use Webkul\Security\Services\CurrentCompany;

class ListAppraisalTemplates extends ListRecords
{
    protected static string $resource = AppraisalTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus-circle')
                ->label('New appraisal template')
                ->modalWidth('6xl')
                ->mutateDataUsing(fn (array $data): array => ['company_id' => CurrentCompany::id()] + $data),
        ];
    }
}
