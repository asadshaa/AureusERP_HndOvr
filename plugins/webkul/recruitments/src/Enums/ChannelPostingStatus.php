<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Status of one job ad on one channel (a JobPosting row) -- not the job's own
 * JobPostingStatus. Postings follow the job (JobPostingLifecycle) and the
 * recruiter's confirmations (JobPostingService); every move is listed in
 * transitions() and enforced by JobPosting on save.
 */
enum ChannelPostingStatus: string implements HasColor, HasLabel
{
    case PLANNED = 'planned';
    case TO_POST = 'to_post';
    case WAITING = 'waiting';
    case LIVE = 'live';
    case FAILED = 'failed';
    case REMOVAL_PENDING = 'removal_pending';
    case CLOSED = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PLANNED         => __('Planned'),
            self::TO_POST         => __('To post'),
            self::WAITING         => __('Waiting for channel'),
            self::LIVE            => __('Live'),
            self::FAILED          => __('Problem'),
            self::REMOVAL_PENDING => __('Take down'),
            self::CLOSED          => __('Closed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PLANNED         => 'gray',
            self::TO_POST         => 'warning',
            self::WAITING         => 'info',
            self::LIVE            => 'success',
            self::FAILED          => 'danger',
            self::REMOVAL_PENDING => 'danger',
            self::CLOSED          => 'gray',
        };
    }

    /** @return array<int, self> */
    public function transitions(): array
    {
        return match ($this) {
            self::PLANNED         => [self::TO_POST, self::WAITING, self::CLOSED],
            self::TO_POST         => [self::LIVE, self::FAILED, self::CLOSED],
            self::WAITING         => [self::LIVE, self::FAILED, self::CLOSED],
            self::LIVE            => [self::REMOVAL_PENDING, self::CLOSED, self::WAITING, self::FAILED],
            self::FAILED          => [self::TO_POST, self::WAITING, self::LIVE, self::CLOSED],
            self::REMOVAL_PENDING => [self::CLOSED, self::LIVE],
            self::CLOSED          => [self::PLANNED, self::TO_POST, self::WAITING, self::REMOVAL_PENDING],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /** The ad may be publicly visible right now. */
    public function isOutThere(): bool
    {
        return in_array($this, [self::LIVE, self::REMOVAL_PENDING], true);
    }

    /** The recruiter has something to do for this ad. */
    public function needsAction(): bool
    {
        return in_array($this, [self::TO_POST, self::FAILED, self::REMOVAL_PENDING], true);
    }

    /** @return array<int, string> */
    public static function needingActionValues(): array
    {
        return [self::TO_POST->value, self::FAILED->value, self::REMOVAL_PENDING->value];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->getLabel()])
            ->all();
    }
}
