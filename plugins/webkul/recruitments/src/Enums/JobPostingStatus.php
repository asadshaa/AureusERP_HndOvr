<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a job opening's public posting. A job only reaches
 * "published" after its approval request has been approved; every allowed
 * move is listed in transitions() and enforced by JobPosition on save.
 */
enum JobPostingStatus: string implements HasColor, HasLabel
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case PUBLISHED = 'published';
    case PAUSED = 'paused';
    case CLOSED = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT            => __('Draft'),
            self::PENDING_APPROVAL => __('Pending approval'),
            self::APPROVED         => __('Approved'),
            self::PUBLISHED        => __('Published'),
            self::PAUSED           => __('Paused'),
            self::CLOSED           => __('Closed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DRAFT            => 'gray',
            self::PENDING_APPROVAL => 'warning',
            self::APPROVED         => 'info',
            self::PUBLISHED        => 'success',
            self::PAUSED           => 'warning',
            self::CLOSED           => 'danger',
        };
    }

    /** @return array<int, self> */
    public function transitions(): array
    {
        return match ($this) {
            self::DRAFT            => [self::PENDING_APPROVAL],
            self::PENDING_APPROVAL => [self::APPROVED, self::DRAFT],
            self::APPROVED         => [self::PUBLISHED, self::CLOSED],
            self::PUBLISHED        => [self::PAUSED, self::CLOSED],
            self::PAUSED           => [self::PUBLISHED, self::CLOSED],
            self::CLOSED           => [self::DRAFT],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /** A missing or unrecognised stored value is treated as a draft. */
    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::DRAFT;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->getLabel()])
            ->all();
    }
}
