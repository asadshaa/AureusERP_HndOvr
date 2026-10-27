<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How an interview takes place. The interview's "location" holds the
 * address (in person), the meeting link (video) or the number to call
 * (phone).
 */
enum InterviewMode: string implements HasIcon, HasLabel
{
    case IN_PERSON = 'in_person';
    case VIDEO = 'video';
    case PHONE = 'phone';

    public function getLabel(): string
    {
        return match ($this) {
            self::IN_PERSON => __('In person'),
            self::VIDEO     => __('Video call'),
            self::PHONE     => __('Phone call'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::IN_PERSON => 'heroicon-o-building-office',
            self::VIDEO     => 'heroicon-o-video-camera',
            self::PHONE     => 'heroicon-o-phone',
        };
    }

    public function locationLabel(): string
    {
        return match ($this) {
            self::IN_PERSON => __('Address'),
            self::VIDEO     => __('Meeting link'),
            self::PHONE     => __('Phone number'),
        };
    }

    /** In-person and video interviews need an address / link; for a phone call it is optional. */
    public function requiresLocation(): bool
    {
        return $this !== self::PHONE;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $mode): array => [$mode->value => $mode->getLabel()])->all();
    }
}
