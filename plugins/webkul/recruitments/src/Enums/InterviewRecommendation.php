<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** An interviewer's hiring recommendation on their scorecard. */
enum InterviewRecommendation: string implements HasColor, HasLabel
{
    case STRONG_YES = 'strong_yes';
    case YES = 'yes';
    case NO = 'no';
    case STRONG_NO = 'strong_no';

    public function getLabel(): string
    {
        return match ($this) {
            self::STRONG_YES => __('Strong yes'),
            self::YES        => __('Yes'),
            self::NO         => __('No'),
            self::STRONG_NO  => __('Strong no'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::STRONG_YES => 'success',
            self::YES        => 'success',
            self::NO         => 'warning',
            self::STRONG_NO  => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $recommendation): array => [$recommendation->value => $recommendation->getLabel()])->all();
    }
}
