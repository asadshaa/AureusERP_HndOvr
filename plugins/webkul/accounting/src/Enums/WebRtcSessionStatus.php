<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WebRtcSessionStatus: string implements HasColor, HasLabel
{
    case Waiting = 'waiting';

    case Connected = 'connected';

    case Completed = 'completed';

    case Expired = 'expired';

    case Cancelled = 'cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Waiting   => 'Waiting for Peer',
            self::Connected => 'Connected / Transferring',
            self::Completed => 'Completed',
            self::Expired   => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Waiting   => 'warning',
            self::Connected => 'info',
            self::Completed => 'success',
            self::Expired   => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
