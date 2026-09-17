<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentTransferStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';

    case Delivered = 'delivered';

    case Expired = 'expired';

    case Cancelled = 'cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Delivered => 'Delivered',
            self::Expired   => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Pending   => 'warning',
            self::Delivered => 'success',
            self::Expired   => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
