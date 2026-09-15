<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InboundTransmissionStatus: string implements HasColor, HasLabel
{
    /** Held as evidence. Nothing in the ledger yet. */
    case Received = 'received';

    case Accepted = 'accepted';

    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Received => 'Awaiting review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Received => 'warning',
            self::Accepted => 'success',
            self::Rejected => 'danger',
        };
    }
}
