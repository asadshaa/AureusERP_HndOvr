<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentStatus: string implements HasLabel
{
    case Active = 'active';

    case Archived = 'archived';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Active   => __('accounting::enums/document-status.active'),
            self::Archived => __('accounting::enums/document-status.archived'),
        };
    }

    public static function options(): array
    {
        return [
            self::Active->value   => __('accounting::enums/document-status.active'),
            self::Archived->value => __('accounting::enums/document-status.archived'),
        ];
    }
}
