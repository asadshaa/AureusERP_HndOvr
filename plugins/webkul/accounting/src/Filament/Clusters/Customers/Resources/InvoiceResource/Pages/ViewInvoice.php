<?php

namespace Webkul\Accounting\Filament\Clusters\Customers\Resources\InvoiceResource\Pages;

use Webkul\Account\Filament\Resources\InvoiceResource\Pages\ViewInvoice as BaseViewInvoice;
use Webkul\Accounting\Filament\Actions\SendInvoiceToPeerAction;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\CreditNoteResource;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\InvoiceResource;

class ViewInvoice extends BaseViewInvoice
{
    protected static string $resource = InvoiceResource::class;

    protected static string $reverseResource = CreditNoteResource::class;

    /**
     * Appends the peer-exchange entry point to whatever the base resource
     * already offers, rather than redefining that list -- so Pay, Confirm,
     * Reverse and the rest keep working and keep their own authorization.
     */
    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            SendInvoiceToPeerAction::make(),
        ];
    }
}
