<?php

namespace Webkul\Employee\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Value of AttendanceRecord.verification_status (null for legacy/manual/import rows). */
enum AttendanceVerificationStatus: string implements HasColor, HasLabel
{
    case Verified = 'verified';

    case Remote = 'remote';

    case NeedsReview = 'needs_review';

    case Reviewed = 'reviewed';

    case ReviewRejected = 'review_rejected';

    case Overridden = 'overridden';

    public function getLabel(): string
    {
        return match ($this) {
            self::Verified       => 'Location confirmed',
            self::Remote         => 'Working remotely',
            self::NeedsReview    => 'Needs checking',
            self::Reviewed       => 'Checked and accepted',
            self::ReviewRejected => 'Checked and rejected',
            self::Overridden     => 'Corrected by HR',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Verified, self::Reviewed => 'success',
            self::Remote                   => 'info',
            self::NeedsReview              => 'warning',
            self::ReviewRejected           => 'danger',
            self::Overridden               => 'gray',
        };
    }
}
