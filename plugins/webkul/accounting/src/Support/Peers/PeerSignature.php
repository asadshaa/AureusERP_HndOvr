<?php

namespace Webkul\Accounting\Support\Peers;

use Illuminate\Support\Str;

/**
 * Canonical request signing shared by both ends of a peer link.
 *
 * The signature covers the RAW body, not a re-encoded array: re-encoding
 * would let a proxy reorder keys or alter whitespace and still verify,
 * which for an invoice means an amount could be changed undetected.
 */
final class PeerSignature
{
    public const HEADER_SIGNATURE = 'X-Aureus-Signature';

    public const HEADER_TIMESTAMP = 'X-Aureus-Timestamp';

    public const HEADER_NONCE = 'X-Aureus-Nonce';

    /**
     * Timestamp and nonce are inside the signed string, so neither can be
     * swapped without invalidating the signature.
     */
    public static function compute(string $rawBody, string $timestamp, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', implode("\n", [$timestamp, $nonce, $rawBody]), $secret);
    }

    public static function matches(string $expected, string $provided): bool
    {
        return hash_equals($expected, $provided);
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $rawBody, string $secret, ?string $nonce = null, ?int $timestamp = null): array
    {
        $nonce = $nonce ?? (string) Str::uuid();
        $timestamp = (string) ($timestamp ?? time());

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE     => $nonce,
            self::HEADER_SIGNATURE => self::compute($rawBody, $timestamp, $nonce, $secret),
        ];
    }
}
