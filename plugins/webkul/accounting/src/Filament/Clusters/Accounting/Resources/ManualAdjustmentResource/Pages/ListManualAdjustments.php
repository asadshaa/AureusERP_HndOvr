<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources\ManualAdjustmentResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\ManualAdjustmentResource;
use Webkul\Accounting\Support\AccountingPermissions;

class ListManualAdjustments extends ListRecords
{
    protected static string $resource = ManualAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        // Found live during the 4-role manual test walkthrough: unlike the
        // /create route (Filament\Resources\Pages\CreateRecord has its own
        // built-in abort_unless(canCreate(), 403)), this header button is a
        // self-contained Filament\Actions\CreateAction that saves through
        // its own modal, entirely bypassing that page-level check. Every
        // other action in this resource (Edit/Approve/Generate/Post) is
        // already explicitly ->authorize()'d -- this one was missed, which
        // let a read-only role (e.g. Internal Auditor, holding only
        // ViewManualAdjustments) both see AND actually submit this button.
        return [
            CreateAction::make()->authorize(AccountingPermissions::ManageManualAdjustments),
        ];
    }
}
