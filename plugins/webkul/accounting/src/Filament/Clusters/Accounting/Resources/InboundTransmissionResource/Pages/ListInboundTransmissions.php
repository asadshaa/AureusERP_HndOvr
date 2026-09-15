<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources\InboundTransmissionResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\InboundTransmissionResource;

class ListInboundTransmissions extends ListRecords
{
    protected static string $resource = InboundTransmissionResource::class;

    /** Inbound records arrive over the wire; nobody creates one by hand. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
