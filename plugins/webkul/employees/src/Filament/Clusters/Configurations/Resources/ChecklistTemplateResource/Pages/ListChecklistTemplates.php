<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources\ChecklistTemplateResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ChecklistTemplateResource;
use Webkul\Security\Services\CurrentCompany;

class ListChecklistTemplates extends ListRecords
{
    protected static string $resource = ChecklistTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus-circle')
                ->label('New checklist template')
                ->modalWidth('6xl')
                ->mutateDataUsing(fn (array $data): array => ['company_id' => CurrentCompany::id()] + $data),
        ];
    }
}
