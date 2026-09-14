<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentAuditAction: string implements HasLabel
{
    case Uploaded = 'uploaded';

    case VersionAdded = 'version_added';

    case Attached = 'attached';

    case Detached = 'detached';

    case Viewed = 'viewed';

    case Downloaded = 'downloaded';

    case Archived = 'archived';

    case Restored = 'restored';

    case AccessDenied = 'access_denied';

    // Export-direction Drive sync only for now -- see DriveSyncService.
    // Import-direction cases (DriveImported, DriveUpdateDetected, etc.)
    // are intentionally not added yet; they belong to that later phase.
    case DriveExported = 'drive_exported';

    case DriveSyncFailed = 'drive_sync_failed';

    // Device-to-device document transfer. Delivery is server-path in this
    // phase; these same cases cover a direct peer transport later, with
    // the path recorded in the audit row's metadata rather than as a
    // separate action.
    case TransferSent = 'transfer_sent';

    case TransferDelivered = 'transfer_delivered';

    case TransferCancelled = 'transfer_cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Uploaded          => __('accounting::enums/document-audit-action.uploaded'),
            self::VersionAdded      => __('accounting::enums/document-audit-action.version-added'),
            self::Attached          => __('accounting::enums/document-audit-action.attached'),
            self::Detached          => __('accounting::enums/document-audit-action.detached'),
            self::Viewed            => __('accounting::enums/document-audit-action.viewed'),
            self::Downloaded        => __('accounting::enums/document-audit-action.downloaded'),
            self::Archived          => __('accounting::enums/document-audit-action.archived'),
            self::Restored          => __('accounting::enums/document-audit-action.restored'),
            self::AccessDenied      => __('accounting::enums/document-audit-action.access-denied'),
            self::DriveExported     => __('accounting::enums/document-audit-action.drive-exported'),
            self::DriveSyncFailed   => __('accounting::enums/document-audit-action.drive-sync-failed'),
            self::TransferSent      => __('accounting::enums/document-audit-action.transfer-sent'),
            self::TransferDelivered => __('accounting::enums/document-audit-action.transfer-delivered'),
            self::TransferCancelled => __('accounting::enums/document-audit-action.transfer-cancelled'),
        };
    }
}
