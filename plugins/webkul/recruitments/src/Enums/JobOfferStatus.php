<?php

namespace Webkul\Recruitment\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a job offer stands (HR parity 3.6):
 * draft -> pending approval (-> returned for changes -> pending again)
 * -> approved -> sent -> accepted / declined / expired, or rejected by an
 * approver, or withdrawn by HR at any open point.
 */
enum JobOfferStatus: string implements HasColor, HasIcon, HasLabel
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case RETURNED = 'returned';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case EXPIRED = 'expired';
    case WITHDRAWN = 'withdrawn';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT            => __('Draft'),
            self::PENDING_APPROVAL => __('Pending approval'),
            self::RETURNED         => __('Returned for changes'),
            self::APPROVED         => __('Approved'),
            self::REJECTED         => __('Rejected'),
            self::SENT             => __('Sent'),
            self::ACCEPTED         => __('Accepted'),
            self::DECLINED         => __('Declined'),
            self::EXPIRED          => __('Expired'),
            self::WITHDRAWN        => __('Withdrawn'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DRAFT, self::WITHDRAWN, self::EXPIRED => 'gray',
            self::PENDING_APPROVAL, self::RETURNED      => 'warning',
            self::APPROVED, self::SENT                  => 'info',
            self::ACCEPTED                              => 'success',
            self::REJECTED, self::DECLINED              => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::DRAFT            => 'heroicon-o-pencil-square',
            self::PENDING_APPROVAL => 'heroicon-o-clock',
            self::RETURNED         => 'heroicon-o-arrow-uturn-left',
            self::APPROVED         => 'heroicon-o-check-badge',
            self::REJECTED         => 'heroicon-o-x-circle',
            self::SENT             => 'heroicon-o-paper-airplane',
            self::ACCEPTED         => 'heroicon-o-hand-thumb-up',
            self::DECLINED         => 'heroicon-o-hand-thumb-down',
            self::EXPIRED          => 'heroicon-o-calendar',
            self::WITHDRAWN        => 'heroicon-o-archive-box-x-mark',
        };
    }

    /** Still in play: at most one open offer per application. */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /** The offer's terms may be changed (salary, dates...). */
    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::RETURNED], true);
    }

    /** @return array<int, self> */
    public static function open(): array
    {
        return [self::DRAFT, self::PENDING_APPROVAL, self::RETURNED, self::APPROVED, self::SENT];
    }

    /** @return array<int, string> */
    public static function openValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::open());
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status): array => [$status->value => $status->getLabel()])->all();
    }
}
