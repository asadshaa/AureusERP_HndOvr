<?php

namespace Webkul\Employee\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AttendanceVerificationResult: string implements HasColor, HasLabel
{
    case Verified = 'verified';

    case NeedsReview = 'needs_review';

    case OutsideGeofence = 'outside_geofence';

    case LowAccuracy = 'low_accuracy';

    case InvalidCoordinates = 'invalid_coordinates';

    case StaleLocation = 'stale_location';

    case PermissionDenied = 'permission_denied';

    case LocationUnavailable = 'location_unavailable';

    case LocationTimeout = 'location_timeout';

    case InsecureContext = 'insecure_context';

    case NoLocationConfigured = 'no_location_configured';

    case EmployeeNotEligible = 'employee_not_eligible';

    case AlreadyCheckedIn = 'already_checked_in';

    case NotCheckedIn = 'not_checked_in';

    case AlreadyCheckedOut = 'already_checked_out';

    case OpenShiftExists = 'open_shift_exists';

    case OnLeaveOrHoliday = 'on_leave_or_holiday';

    case RemoteExempt = 'remote_exempt';

    case Overridden = 'overridden';

    case ReviewApproved = 'review_approved';

    case ReviewRejected = 'review_rejected';

    case TooEarlyForCheckOut = 'too_early_for_checkout';

    /** Employee-safe wording: never exposes distance, coordinates or thresholds. */
    public function getLabel(): string
    {
        return __('employees::attendance.results.'.$this->value);
    }

    public function isSuccess(): bool
    {
        return in_array($this, [self::Verified, self::NeedsReview, self::RemoteExempt], true);
    }

    public function getColor(): string
    {
        return match (true) {
            $this === self::Verified, $this === self::RemoteExempt, $this === self::ReviewApproved   => 'success',
            $this === self::NeedsReview                                                              => 'warning',
            $this === self::Overridden                                                               => 'gray',
            default                                                                                  => 'danger',
        };
    }
}
