<?php

namespace Webkul\Employee\Support;

use Illuminate\Support\Str;

/**
 * The things a manager can approve, so HR can give a person a different
 * manager for each (leave, attendance, claims, ...). A purpose without its own
 * manager follows the person's line manager.
 *
 * Request types are the approval workflow keys (support_approval_workflows
 * .request_type): leave_request, claim_travel_entertainment, loan_request ...
 */
final class ReportingPurposes
{
    public const Leave = 'leave';

    public const Attendance = 'attendance';

    public const Claims = 'claims';

    public const Advance = 'advance';

    public const Loan = 'loan';

    public const Overtime = 'overtime';

    public const Appraisal = 'appraisal';

    public const Recruitment = 'recruitment';

    /** @return array<string, string> purpose => label */
    public static function labels(): array
    {
        return [
            self::Leave       => 'Leave',
            self::Attendance  => 'Attendance',
            self::Claims      => 'Claims',
            self::Advance     => 'Advances',
            self::Loan        => 'Loans and salary advances',
            self::Overtime    => 'Overtime',
            self::Appraisal   => 'Appraisals',
            self::Recruitment => 'Recruitment',
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::labels());
    }

    public static function isValid(?string $purpose): bool
    {
        return $purpose !== null && array_key_exists($purpose, self::labels());
    }

    public static function label(?string $purpose): string
    {
        return $purpose === null ? 'Line manager (everything else)' : (self::labels()[$purpose] ?? Str::headline($purpose));
    }

    /** The purpose an approval workflow belongs to, or null when it simply follows the line manager. */
    public static function forRequestType(?string $requestType): ?string
    {
        if ($requestType === null || $requestType === '') {
            return null;
        }

        return match (true) {
            $requestType === 'leave_request'                                           => self::Leave,
            $requestType === 'attendance_time_change'                                  => self::Attendance,
            str_starts_with($requestType, 'claim_')                                    => self::Claims,
            in_array($requestType, ['business_advance', 'advance_settlement'], true)   => self::Advance,
            in_array($requestType, ['loan_request', 'salary_advance'], true)           => self::Loan,
            $requestType === 'overtime'                                                => self::Overtime,
            in_array($requestType, ['recruitment_job_opening', 'job_offer'], true)     => self::Recruitment,
            default                                                                    => null,
        };
    }

    /** A plain label for an approval workflow key. */
    public static function requestTypeLabel(string $requestType): string
    {
        return match (true) {
            $requestType === 'leave_request'              => 'Leave request',
            $requestType === 'attendance_time_change'     => 'Attendance correction',
            $requestType === 'business_advance'           => 'Business advance',
            $requestType === 'advance_settlement'         => 'Advance settlement',
            $requestType === 'loan_request'               => 'Loan',
            $requestType === 'salary_advance'             => 'Salary advance',
            $requestType === 'hr_letter'                  => 'HR letter',
            $requestType === 'overtime'                   => 'Overtime',
            $requestType === 'recruitment_job_opening'    => 'Job opening',
            $requestType === 'job_offer'                  => 'Job offer',
            $requestType === 'employee_sensitive_change'  => 'Sensitive data change',
            str_starts_with($requestType, 'claim_')       => 'Claim: '.Str::headline(substr($requestType, 6)),
            default                                       => Str::headline($requestType),
        };
    }
}
