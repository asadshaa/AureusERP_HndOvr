<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The Drive sync state machine (export-only for now -- Conflict and
 * MissingInDrive are reserved for the Drive->Aureus import phase, not yet
 * built, but declared here now so the column/UI don't need another
 * migration when that phase lands):
 *
 *   NotSynced --[export]--> Pending --[job succeeds]--> Synced
 *                              |
 *                              +--[job fails]--> Failed --[retry]--> Pending
 *
 *   Synced --[Drive or Aureus changes, import phase]--> Pending
 *   Synced --[both sides changed, import phase]--> Conflict --[resolved]--> Synced
 *   Synced --[Drive file vanished, import phase]--> MissingInDrive
 */
enum DriveSyncStatus: string implements HasColor, HasLabel
{
    case NotSynced = 'not_synced';

    case Pending = 'pending';

    case Synced = 'synced';

    case Failed = 'failed';

    case Conflict = 'conflict';

    case MissingInDrive = 'missing_in_drive';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::NotSynced      => __('accounting::enums/drive-sync-status.not-synced'),
            self::Pending        => __('accounting::enums/drive-sync-status.pending'),
            self::Synced         => __('accounting::enums/drive-sync-status.synced'),
            self::Failed         => __('accounting::enums/drive-sync-status.failed'),
            self::Conflict       => __('accounting::enums/drive-sync-status.conflict'),
            self::MissingInDrive => __('accounting::enums/drive-sync-status.missing-in-drive'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NotSynced      => 'gray',
            self::Pending        => 'info',
            self::Synced         => 'success',
            self::Failed         => 'danger',
            self::Conflict       => 'warning',
            self::MissingInDrive => 'warning',
        };
    }
}
