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

    // Intra-instance user-to-user transfer (DocumentTransferService). These
    // were referenced by that service but never defined here, so every call
    // to send()/claim()/cancel() fatalled -- found while designing the peer
    // exchange subsystem.
    case TransferSent = 'transfer_sent';

    case TransferDelivered = 'transfer_delivered';

    case TransferCancelled = 'transfer_cancelled';

    // Cross-deployment peer exchange (DocumentExchangeService).
    case PeerPaired = 'peer_paired';

    case PeerRevoked = 'peer_revoked';

    case TransmissionSent = 'transmission_sent';

    case TransmissionDelivered = 'transmission_delivered';

    case TransmissionFailed = 'transmission_failed';

    case TransmissionReceived = 'transmission_received';

    case TransmissionAccepted = 'transmission_accepted';

    case TransmissionRejected = 'transmission_rejected';

    /** A peer request that failed signature, token, skew or replay checks. */
    case PeerAuthFailed = 'peer_auth_failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Uploaded              => __('accounting::enums/document-audit-action.uploaded'),
            self::VersionAdded          => __('accounting::enums/document-audit-action.version-added'),
            self::Attached              => __('accounting::enums/document-audit-action.attached'),
            self::Detached              => __('accounting::enums/document-audit-action.detached'),
            self::Viewed                => __('accounting::enums/document-audit-action.viewed'),
            self::Downloaded            => __('accounting::enums/document-audit-action.downloaded'),
            self::Archived              => __('accounting::enums/document-audit-action.archived'),
            self::Restored              => __('accounting::enums/document-audit-action.restored'),
            self::AccessDenied          => __('accounting::enums/document-audit-action.access-denied'),
            self::DriveExported         => __('accounting::enums/document-audit-action.drive-exported'),
            self::DriveSyncFailed       => __('accounting::enums/document-audit-action.drive-sync-failed'),
            self::TransferSent          => __('accounting::enums/document-audit-action.transfer-sent'),
            self::TransferDelivered     => __('accounting::enums/document-audit-action.transfer-delivered'),
            self::TransferCancelled     => __('accounting::enums/document-audit-action.transfer-cancelled'),
            self::PeerPaired            => __('accounting::enums/document-audit-action.peer-paired'),
            self::PeerRevoked           => __('accounting::enums/document-audit-action.peer-revoked'),
            self::TransmissionSent      => __('accounting::enums/document-audit-action.transmission-sent'),
            self::TransmissionDelivered => __('accounting::enums/document-audit-action.transmission-delivered'),
            self::TransmissionFailed    => __('accounting::enums/document-audit-action.transmission-failed'),
            self::TransmissionReceived  => __('accounting::enums/document-audit-action.transmission-received'),
            self::TransmissionAccepted  => __('accounting::enums/document-audit-action.transmission-accepted'),
            self::TransmissionRejected  => __('accounting::enums/document-audit-action.transmission-rejected'),
            self::PeerAuthFailed        => __('accounting::enums/document-audit-action.peer-auth-failed'),
        };
    }
}
