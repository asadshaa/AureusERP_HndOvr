<?php

namespace Webkul\TimeOff\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum State: string implements HasColor, HasLabel
{
    case CONFIRM = 'confirm';

    case REFUSE = 'refuse';

    case VALIDATE_ONE = 'validate_one';

    case VALIDATE_TWO = 'validate_two';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::CONFIRM      => __('time-off::enums/state.confirm'),
            self::REFUSE       => __('time-off::enums/state.refuse'),
            self::VALIDATE_ONE => __('time-off::enums/state.validate_one'),
            self::VALIDATE_TWO => __('time-off::enums/state.validate_two'),
        };
    }

    /**
     * Was missing entirely -- every ->badge() column using this enum (Time
     * Off management, My Time Off, the dashboard widget) rendered as a
     * plain uncolored badge, with no visual distinction between "just
     * submitted", "waiting on HR", "fully approved", or "refused".
     */
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::CONFIRM      => 'warning',
            self::VALIDATE_ONE => 'info',
            self::VALIDATE_TWO => 'success',
            self::REFUSE       => 'danger',
        };
    }

    public static function options(): array
    {
        return [
            self::CONFIRM->value      => __('time-off::enums/state.confirm'),
            self::REFUSE->value       => __('time-off::enums/state.refuse'),
            self::VALIDATE_ONE->value => __('time-off::enums/state.validate_one'),
            self::VALIDATE_TWO->value => __('time-off::enums/state.validate_two'),
        ];
    }
}
