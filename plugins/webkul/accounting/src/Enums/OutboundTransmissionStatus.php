<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OutboundTransmissionStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';

    case Delivered = 'delivered';

    /** Terminal. Either the peer refused it (4xx) or retries ran out. */
    case Failed = 'failed';

    /** External channel only: the claim link lapsed unopened. */
    case Expired = 'expired';

    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued    => 'Queued',
            self::Delivered => 'Delivered',
            self::Failed    => 'Failed',
            self::Expired   => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued    => 'warning',
            self::Delivered => 'success',
            self::Failed    => 'danger',
            self::Expired   => 'gray',
            self::Cancelled => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Queued;
    }
}
