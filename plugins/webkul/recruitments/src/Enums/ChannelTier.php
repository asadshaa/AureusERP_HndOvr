<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a job ad reaches a channel: Aureus lists it itself (automatic), the
 * board pulls an Aureus feed (feed), the recruiter posts it by hand with the
 * text and link Aureus prepares (manual), or a provider API (api, Stage 5).
 */
enum ChannelTier: string implements HasColor, HasLabel
{
    case AUTOMATIC = 'automatic';
    case FEED = 'feed';
    case MANUAL = 'manual';
    case API = 'api';

    public function getLabel(): string
    {
        return match ($this) {
            self::AUTOMATIC => __('Automatic'),
            self::FEED      => __('Feed'),
            self::MANUAL    => __('Posted by recruiter'),
            self::API       => __('API'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AUTOMATIC => 'info',
            self::FEED      => 'info',
            self::MANUAL    => 'primary',
            self::API       => 'success',
        };
    }
}
