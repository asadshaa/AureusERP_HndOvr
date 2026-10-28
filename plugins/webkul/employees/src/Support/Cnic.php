<?php

namespace Webkul\Employee\Support;

/**
 * Pakistani CNIC: 13 digits, written 12345-1234567-1. Input with or without
 * dashes/spaces is accepted and stored formatted.
 */
final class Cnic
{
    public const PATTERN = '/^\d{5}-\d{7}-\d$/';

    /** The formatted CNIC, or null when the input is not 13 digits (blank stays null). */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (! preg_match('/^[\d\s-]+$/', trim($value))) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) !== 13) {
            return null;
        }

        return substr($digits, 0, 5).'-'.substr($digits, 5, 7).'-'.substr($digits, 12, 1);
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /** Digits only, for searching ("3520212345671" finds "35202-1234567-1"). */
    public static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}
