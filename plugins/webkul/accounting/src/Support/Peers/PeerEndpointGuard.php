<?php

namespace Webkul\Accounting\Support\Peers;

use InvalidArgumentException;

/**
 * SSRF guard for operator-supplied peer endpoints.
 *
 * `endpoint_url` is typed in by a human and this server then POSTs to it.
 * Without this check, a hostile or careless value turns the instance into
 * an internal-network probe (169.254.169.254 metadata, 10.x admin panels,
 * localhost services). Loopback is allowed ONLY behind an explicit config
 * flag so two instances can be paired on one machine for testing.
 */
final class PeerEndpointGuard
{
    public static function assertAllowed(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new InvalidArgumentException('That is not a valid peer URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Peer URLs must be http or https.');
        }

        $allowLocal = (bool) config('accounting_peers.allow_local_endpoints', false);

        if (config('accounting_peers.require_https', true) && $scheme !== 'https' && ! $allowLocal) {
            throw new InvalidArgumentException('Peer URLs must use HTTPS.');
        }

        $host = $parts['host'];

        foreach (self::resolve($host) as $ip) {
            if (self::isPublic($ip)) {
                continue;
            }

            if ($allowLocal) {
                continue;
            }

            throw new InvalidArgumentException(
                "Peer URL resolves to a private or loopback address ({$ip}). "
                .'Set ACCOUNTING_PEERS_ALLOW_LOCAL=true only for local testing.'
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $ips = [];

        foreach ($records as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        $ips = array_values(array_filter($ips));

        if ($ips === []) {
            // Unresolvable now does not make it safe later; refuse rather
            // than fall through to an unchecked request.
            throw new InvalidArgumentException("Could not resolve peer host '{$host}'.");
        }

        return $ips;
    }

    private static function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
