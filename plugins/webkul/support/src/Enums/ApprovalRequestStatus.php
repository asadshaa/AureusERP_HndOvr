<?php

namespace Webkul\Support\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statuses of a support_approval_requests row. The column stays a plain
 * string (callers compare against 'pending' etc.); this enum gives the
 * Approval Queue its labels, badge colours and filter options. Only Pending
 * is still waiting on anyone: every pending count reads status = 'pending'.
 */
enum ApprovalRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    /** Taken back by the employee before a decision (e.g. a withdrawn leave request). */
    case Withdrawn = 'withdrawn';

    /**
     * Sent back to the requester for changes (ApprovalEngine::returnToRequester).
     * Nobody can act on it until the requester resubmits, which restarts it at
     * the first step; it is never counted as pending.
     */
    case Returned = 'returned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending   => __('support::enums/approval-request-status.pending'),
            self::Approved  => __('support::enums/approval-request-status.approved'),
            self::Rejected  => __('support::enums/approval-request-status.rejected'),
            self::Withdrawn => __('support::enums/approval-request-status.withdrawn'),
            self::Returned  => __('support::enums/approval-request-status.returned'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending   => 'warning',
            self::Approved  => 'success',
            self::Rejected  => 'danger',
            self::Withdrawn => 'gray',
            self::Returned  => 'info',
        };
    }

    /** Label for a raw status value; an unknown value is shown as-is, headlined. */
    public static function labelFor(?string $status): string
    {
        return self::tryFrom((string) $status)?->getLabel() ?? str((string) $status)->headline()->toString();
    }

    /** Badge colour for a raw status value; an unknown value is neutral, never pending-looking. */
    public static function colorFor(?string $status): string
    {
        return self::tryFrom((string) $status)?->getColor() ?? 'gray';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Pending->value   => __('support::enums/approval-request-status.pending'),
            self::Approved->value  => __('support::enums/approval-request-status.approved'),
            self::Rejected->value  => __('support::enums/approval-request-status.rejected'),
            self::Withdrawn->value => __('support::enums/approval-request-status.withdrawn'),
            self::Returned->value  => __('support::enums/approval-request-status.returned'),
        ];
    }
}
