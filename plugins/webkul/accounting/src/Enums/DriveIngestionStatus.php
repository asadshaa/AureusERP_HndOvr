<?php

namespace Webkul\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 1 (discovery + dedup + review record) state machine only -- no
 * case here means "recognized, resolved into an invoice/bill/etc.";
 * turning a Registered ingestion into anything beyond a plain Document
 * is a later phase's job.
 *
 *   Discovered --[download() starts]--> Downloading --[bytes verified]--> Downloaded
 *                                             |
 *                                             +--[checksum mismatch / error]--> Failed
 *
 *   Downloaded --[register() succeeds]--> Registered
 *
 *   Discovered --[same drive_file_id already has a DocumentDriveSync row,
 *                 i.e. Aureus itself uploaded this file]--> RecognizedInternalOrigin
 *
 *   Discovered --[already ingested, same checksum, re-discovered]--> DuplicateSkipped
 */
enum DriveIngestionStatus: string implements HasColor, HasLabel
{
    case Discovered = 'discovered';

    case Downloading = 'downloading';

    case Downloaded = 'downloaded';

    case Registered = 'registered';

    case Failed = 'failed';

    case DuplicateSkipped = 'duplicate_skipped';

    // Bidirectional-loop-prevention terminal state: this Drive file is one
    // Aureus itself exported (a DocumentDriveSync row already references
    // its drive_file_id), so it must never be re-ingested as if it were a
    // new external file.
    case RecognizedInternalOrigin = 'recognized_internal_origin';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Discovered               => __('accounting::enums/drive-ingestion-status.discovered'),
            self::Downloading              => __('accounting::enums/drive-ingestion-status.downloading'),
            self::Downloaded               => __('accounting::enums/drive-ingestion-status.downloaded'),
            self::Registered               => __('accounting::enums/drive-ingestion-status.registered'),
            self::Failed                   => __('accounting::enums/drive-ingestion-status.failed'),
            self::DuplicateSkipped         => __('accounting::enums/drive-ingestion-status.duplicate-skipped'),
            self::RecognizedInternalOrigin => __('accounting::enums/drive-ingestion-status.recognized-internal-origin'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Discovered               => 'gray',
            self::Downloading              => 'info',
            self::Downloaded               => 'info',
            self::Registered               => 'success',
            self::Failed                   => 'danger',
            self::DuplicateSkipped         => 'gray',
            self::RecognizedInternalOrigin => 'warning',
        };
    }
}
