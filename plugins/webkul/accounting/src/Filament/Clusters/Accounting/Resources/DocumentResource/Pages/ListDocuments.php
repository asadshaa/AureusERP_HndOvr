<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources\DocumentResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\DocumentResource;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload document')
                ->icon('heroicon-o-arrow-up-tray')
                ->authorize(AccountingPermissions::ManageDocuments)
                ->schema(DocumentResource::uploadFormSchema())
                ->action(function (array $data): void {
                    try {
                        app(DocumentService::class)->upload(
                            Auth::user(),
                            Auth::user()->default_company_id,
                            DocumentType::from($data['document_type']),
                            $data['title'],
                            $data['description'] ?? null,
                            $data['file'],
                            request()->ip(),
                        );

                        Notification::make()->success()->title('Document uploaded')->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Could not upload this document')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
