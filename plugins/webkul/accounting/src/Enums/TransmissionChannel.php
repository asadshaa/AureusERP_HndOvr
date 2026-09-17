<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum TransmissionChannel: string implements HasLabel
{
    /** Machine-to-machine, to a paired AureusERP instance. */
    case Peer = 'peer';

    /** Emailed PDF plus an expiring claim link, for recipients with no ERP. */
    case External = 'external';

    public function getLabel(): string
    {
        return match ($this) {
            self::Peer     => 'AureusERP peer',
            self::External => 'Email / claim link',
        };
    }
}
