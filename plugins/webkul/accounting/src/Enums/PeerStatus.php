<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PeerStatus: string implements HasColor, HasLabel
{
    /** Pairing code issued, handshake not yet completed. */
    case Pending = 'pending';

    case Active = 'active';

    /** Deliberately kept rather than deleted: inbound history stays readable. */
    case Revoked = 'revoked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active  => 'Active',
            self::Revoked => 'Revoked',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active  => 'success',
            self::Revoked => 'danger',
        };
    }
}
