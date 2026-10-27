<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasLabel;

/** Where the job is done: published on the careers website and in Google's JobPosting data. */
enum JobLocationType: string implements HasLabel
{
    case ON_SITE = 'on_site';
    case HYBRID = 'hybrid';
    case REMOTE = 'remote';

    public function getLabel(): string
    {
        return match ($this) {
            self::ON_SITE => __('On-site'),
            self::HYBRID  => __('Hybrid'),
            self::REMOTE  => __('Remote'),
        };
    }

    /** The candidate has to come to the workplace at least part of the time. */
    public function needsPresence(): bool
    {
        return $this !== self::REMOTE;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type): array => [$type->value => $type->getLabel()])->all();
    }
}
