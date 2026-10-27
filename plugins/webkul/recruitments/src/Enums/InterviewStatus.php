<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an interview stands. Only a scheduled interview can be rescheduled,
 * cancelled or closed (completed / no-show); feedback is given on scheduled
 * (once started) and completed interviews.
 */
enum InterviewStatus: string implements HasColor, HasLabel
{
    case SCHEDULED = 'scheduled';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case NO_SHOW = 'no_show';

    public function getLabel(): string
    {
        return match ($this) {
            self::SCHEDULED => __('Scheduled'),
            self::COMPLETED => __('Completed'),
            self::CANCELLED => __('Cancelled'),
            self::NO_SHOW   => __('No-show'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SCHEDULED => 'info',
            self::COMPLETED => 'success',
            self::CANCELLED => 'gray',
            self::NO_SHOW   => 'danger',
        };
    }

    /** Feedback may be given (once the interview has started). */
    public function acceptsFeedback(): bool
    {
        return in_array($this, [self::SCHEDULED, self::COMPLETED], true);
    }

    /** @return array<int, string> */
    public static function feedbackValues(): array
    {
        return [self::SCHEDULED->value, self::COMPLETED->value];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status): array => [$status->value => $status->getLabel()])->all();
    }
}
