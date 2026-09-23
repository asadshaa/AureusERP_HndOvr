<?php

namespace Webkul\Accounting\Services\Drive;

use Google\Service\Exception as GoogleServiceException;
use Throwable;

class DriveErrorFormatter
{
    // Drive API / Network Codes
    public const DRIVE_DOWNLOAD_TEMPORARY = 'DRIVE_DOWNLOAD_TEMPORARY'; // retryable: true

    public const DRIVE_RATE_LIMITED = 'DRIVE_RATE_LIMITED';       // retryable: true

    public const DRIVE_AUTH_FAILED = 'DRIVE_AUTH_FAILED';        // retryable: false

    public const DRIVE_PERMISSION_DENIED = 'DRIVE_PERMISSION_DENIED';  // retryable: false

    public const DRIVE_QUOTA_EXCEEDED = 'DRIVE_QUOTA_EXCEEDED';     // retryable: false

    public const DRIVE_FILE_NOT_FOUND = 'DRIVE_FILE_NOT_FOUND';     // retryable: false

    public const DRIVE_INVALID_RESPONSE = 'DRIVE_INVALID_RESPONSE';   // retryable: false

    // File / Extraction Codes
    public const CHECKSUM_MISMATCH = 'CHECKSUM_MISMATCH';        // retryable: false

    public const MALFORMED_PDF = 'MALFORMED_PDF';            // retryable: false

    public const SCANNED_PDF_NO_TEXT = 'SCANNED_PDF_NO_TEXT';      // retryable: false

    public const MATH_MISMATCH = 'MATH_MISMATCH';            // retryable: false

    // Master Data Resolution Codes
    public const PARTNER_NOT_FOUND = 'PARTNER_NOT_FOUND';        // retryable: false

    public const PARTNER_AMBIGUOUS = 'PARTNER_AMBIGUOUS';        // retryable: false

    public const PARTNER_ROLE_AMBIGUOUS = 'PARTNER_ROLE_AMBIGUOUS';   // retryable: false

    public const FS_TAG_NOT_FOUND = 'FS_TAG_NOT_FOUND';         // retryable: false

    public const FS_TAG_INACTIVE = 'FS_TAG_INACTIVE';          // retryable: false

    public const GL_ACCOUNT_INVALID = 'GL_ACCOUNT_INVALID';       // retryable: false

    public const TAX_NOT_FOUND = 'TAX_NOT_FOUND';            // retryable: false

    public const TAX_AMBIGUOUS = 'TAX_AMBIGUOUS';            // retryable: false

    public const CURRENCY_DATE_REQUIRED = 'CURRENCY_DATE_REQUIRED';   // retryable: false

    public const POSTING_UNBALANCED = 'POSTING_UNBALANCED';       // retryable: false

    /**
     * Inspects actual Throwable/HTTP code to classify error accurately into structured diagnostic data.
     *
     * @return array{
     *     error_code: string,
     *     stage: string,
     *     message: string,
     *     action: string,
     *     retryable: bool,
     *     technical_details: ?string
     * }
     */
    public static function format(
        string $stage,
        Throwable|string $error,
        ?string $overrideCode = null,
        ?string $action = null,
        ?bool $retryable = null
    ): array {
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $technicalDetails = $error instanceof Throwable ? get_class($error).': '.$error->getMessage() : null;

        $detectedCode = $overrideCode ?? self::classifyException($error);
        $isRetryable = $retryable ?? self::isCodeRetryable($detectedCode);
        $recommendedAction = $action ?? self::defaultActionForCode($detectedCode);

        return [
            'error_code'        => $detectedCode,
            'stage'             => $stage,
            'message'           => $message,
            'action'            => $recommendedAction,
            'retryable'         => $isRetryable,
            'technical_details' => $technicalDetails,
        ];
    }

    public static function classifyException(Throwable|string $error): string
    {
        if (is_string($error)) {
            return self::classifyMessage($error);
        }

        if ($error instanceof GoogleServiceException) {
            $code = $error->getCode();
            $msg = strtolower($error->getMessage());

            if ($code === 401) {
                return self::DRIVE_AUTH_FAILED;
            }

            if ($code === 403) {
                if (str_contains($msg, 'ratelimit') || str_contains($msg, 'userratelimitexceeded')) {
                    return self::DRIVE_RATE_LIMITED;
                }

                if (str_contains($msg, 'quota') || str_contains($msg, 'storagequotaexceeded')) {
                    return self::DRIVE_QUOTA_EXCEEDED;
                }

                return self::DRIVE_PERMISSION_DENIED;
            }

            if ($code === 404) {
                return self::DRIVE_FILE_NOT_FOUND;
            }

            if ($code === 429) {
                return self::DRIVE_RATE_LIMITED;
            }

            if ($code >= 500 && $code <= 599) {
                return self::DRIVE_DOWNLOAD_TEMPORARY;
            }
        }

        return self::classifyMessage($error->getMessage());
    }

    private static function classifyMessage(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'checksum') && str_contains($lower, 'mismatch')) {
            return self::CHECKSUM_MISMATCH;
        }

        if (str_contains($lower, 'rate limit') || str_contains($lower, '429')) {
            return self::DRIVE_RATE_LIMITED;
        }

        if (str_contains($lower, 'unauthorized') || str_contains($lower, 'auth') || str_contains($lower, '401')) {
            return self::DRIVE_AUTH_FAILED;
        }

        if (str_contains($lower, 'permission') || str_contains($lower, 'access denied') || str_contains($lower, '403')) {
            return self::DRIVE_PERMISSION_DENIED;
        }

        if (str_contains($lower, 'not found') || str_contains($lower, '404')) {
            return self::DRIVE_FILE_NOT_FOUND;
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'curl error 28') || str_contains($lower, 'connection refused')) {
            return self::DRIVE_DOWNLOAD_TEMPORARY;
        }

        if (str_contains($lower, 'partner role') || str_contains($lower, 'role is ambiguous')) {
            return self::PARTNER_ROLE_AMBIGUOUS;
        }

        if (str_contains($lower, 'multiple partners') || str_contains($lower, 'partner is ambiguous')) {
            return self::PARTNER_AMBIGUOUS;
        }

        if (str_contains($lower, 'partner') && str_contains($lower, 'not match')) {
            return self::PARTNER_NOT_FOUND;
        }

        if (str_contains($lower, 'fs tag') && (str_contains($lower, 'inactive') || str_contains($lower, 'deprecated'))) {
            return self::FS_TAG_INACTIVE;
        }

        if (str_contains($lower, 'fs tag') && (str_contains($lower, 'not found') || str_contains($lower, 'unresolved'))) {
            return self::FS_TAG_NOT_FOUND;
        }

        if (str_contains($lower, 'tax') && str_contains($lower, 'multiple')) {
            return self::TAX_AMBIGUOUS;
        }

        if (str_contains($lower, 'tax') && str_contains($lower, 'not found')) {
            return self::TAX_NOT_FOUND;
        }

        if (str_contains($lower, 'math') || str_contains($lower, 'subtotal') || str_contains($lower, 'does not balance')) {
            return self::MATH_MISMATCH;
        }

        if (str_contains($lower, 'pdf') && (str_contains($lower, 'corrupt') || str_contains($lower, 'malformed'))) {
            return self::MALFORMED_PDF;
        }

        return self::DRIVE_DOWNLOAD_TEMPORARY;
    }

    public static function isCodeRetryable(string $code): bool
    {
        return match ($code) {
            self::DRIVE_DOWNLOAD_TEMPORARY,
            self::DRIVE_RATE_LIMITED => true,
            default                  => false,
        };
    }

    public static function defaultActionForCode(string $code): string
    {
        return match ($code) {
            self::DRIVE_DOWNLOAD_TEMPORARY => 'The operation failed due to a temporary network or Google Drive glitch. It will be retried automatically by the scheduler.',
            self::DRIVE_RATE_LIMITED       => 'Google Drive API rate limit reached. The request will automatically retry after a backoff period.',
            self::DRIVE_AUTH_FAILED        => 'Google Drive OAuth credentials are invalid or expired. Re-authorize the connection via artisan accounting:drive:authorize.',
            self::DRIVE_PERMISSION_DENIED  => 'The authorized Google account does not have access permissions for this file or folder in Google Drive.',
            self::DRIVE_QUOTA_EXCEEDED     => 'Google Drive storage quota has been exceeded. Free up space or upgrade storage.',
            self::DRIVE_FILE_NOT_FOUND     => 'The requested file was not found in Google Drive (it may have been deleted or moved).',
            self::CHECKSUM_MISMATCH        => 'The downloaded file was corrupted during transit. Ensure the file is valid on Google Drive.',
            self::MALFORMED_PDF            => 'The PDF stream could not be parsed. Inspect the PDF file or upload a standardized format.',
            self::SCANNED_PDF_NO_TEXT      => 'The PDF is a scanned image without selectable text. Manually review and enter invoice details.',
            self::MATH_MISMATCH            => 'Extracted line items do not mathematically balance with the subtotal and grand total. Resolve manually in Review.',
            self::PARTNER_NOT_FOUND        => 'No matching partner found in this company. Create or alias the customer/vendor in Aureus Partners.',
            self::PARTNER_AMBIGUOUS        => 'Multiple partners match the candidate name. Explicitly select the correct partner in the Review UI.',
            self::PARTNER_ROLE_AMBIGUOUS   => 'Partner has both or neither customer and supplier roles for a credit note. Manually confirm the transaction role.',
            self::FS_TAG_NOT_FOUND         => 'FS Tag code extracted from document does not exist in Chart of Accounts. Create the FS Tag in Aureus.',
            self::FS_TAG_INACTIVE          => 'The resolved FS Tag is marked inactive. Activate the FS Tag or re-assign to an active one.',
            self::GL_ACCOUNT_INVALID       => 'The associated GL account is non-postable or does not belong to this company. Check Account setup.',
            self::TAX_NOT_FOUND            => 'No active tax record matches the candidate tax rate and purpose for this company.',
            self::TAX_AMBIGUOUS            => 'Multiple active taxes share the same rate. Select the appropriate tax in the Review UI.',
            self::CURRENCY_DATE_REQUIRED   => 'Foreign currency invoice requires a valid historical invoice date to determine the exchange rate.',
            self::POSTING_UNBALANCED       => 'The generated move failed accounting balance validation. Review line items and taxes.',
            default                        => 'Review diagnostic details and resolve manually in Aureus ERP.',
        };
    }
}
