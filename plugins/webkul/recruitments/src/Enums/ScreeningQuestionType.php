<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasLabel;

/** The kinds of screening question HR can ask, and the must-have rule each one supports. */
enum ScreeningQuestionType: string implements HasLabel
{
    case YES_NO = 'yes_no';
    case NUMBER = 'number';
    case SINGLE_CHOICE = 'single_choice';
    case MULTI_CHOICE = 'multi_choice';
    case TEXT = 'text';

    public function getLabel(): string
    {
        return match ($this) {
            self::YES_NO        => __('Yes / No'),
            self::NUMBER        => __('Number'),
            self::SINGLE_CHOICE => __('Single choice'),
            self::MULTI_CHOICE  => __('Multiple choice'),
            self::TEXT          => __('Text'),
        };
    }

    public function hasOptions(): bool
    {
        return in_array($this, [self::SINGLE_CHOICE, self::MULTI_CHOICE], true);
    }

    /** Free text is reviewed by a person; every other type can carry a must-have rule. */
    public function supportsKnockout(): bool
    {
        return $this !== self::TEXT;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type): array => [$type->value => $type->getLabel()])->all();
    }
}
