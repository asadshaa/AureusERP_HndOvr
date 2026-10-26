<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Outcome of an application's screening answers. "Flagged" never rejects: it
 * moves the applicant aside for a recruiter, who may override it to passed.
 */
enum ScreeningStatus: string implements HasColor, HasLabel
{
    case PASSED = 'passed';
    case FLAGGED = 'flagged';
    case OVERRIDDEN = 'overridden';

    public function getLabel(): string
    {
        return match ($this) {
            self::PASSED     => __('Passed'),
            self::FLAGGED    => __('Not qualified'),
            self::OVERRIDDEN => __('Passed (override)'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PASSED     => 'success',
            self::FLAGGED    => 'danger',
            self::OVERRIDDEN => 'info',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status): array => [$status->value => $status->getLabel()])->all();
    }
}
