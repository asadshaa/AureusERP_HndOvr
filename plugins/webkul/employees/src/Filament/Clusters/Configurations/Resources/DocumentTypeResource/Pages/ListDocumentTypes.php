<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources\DocumentTypeResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\DocumentTypeResource;
use Webkul\Security\Services\CurrentCompany;

class ListDocumentTypes extends ListRecords
{
    protected static string $resource = DocumentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus-circle')
                ->label('New document type')
                ->mutateDataUsing(fn (array $data): array => $data + ['company_id' => CurrentCompany::id()]),
        ];
    }
}
