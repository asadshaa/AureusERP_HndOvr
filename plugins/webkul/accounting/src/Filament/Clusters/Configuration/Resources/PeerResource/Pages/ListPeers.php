<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\PeerResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\PeerResource;

class ListPeers extends ListRecords
{
    protected static string $resource = PeerResource::class;

    /**
     * No CreateAction: pairing happens through the two header actions on the
     * table (invite / redeem), because a peer is a handshake, not a form.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
