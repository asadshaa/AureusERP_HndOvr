<?php

return [
    /*
     | Master switch. When false, pairing and sending are refused outright --
     | the inbound endpoint still verifies and rejects, so a peer gets a clean
     | 403 rather than a silent black hole.
     */
    'enabled' => env('ACCOUNTING_PEERS_ENABLED', true),

    /*
     | How long a one-time pairing code stays usable. Short by design: the
     | code is the only thing standing between a stranger and a trust
     | relationship, and it is typically pasted within a minute or two.
     */
    'pairing_code_ttl_minutes' => env('ACCOUNTING_PEERS_PAIRING_TTL', 15),

    /*
     | Clock skew tolerated on X-Aureus-Timestamp. Too tight and unsynced
     | servers fail legitimately; too loose and the replay window widens.
     */
    'timestamp_skew_seconds' => env('ACCOUNTING_PEERS_SKEW', 300),

    /*
     | Nonce replay cache TTL. Deliberately 2x the skew window: a nonce only
     | needs remembering for as long as its timestamp could still pass.
     */
    'nonce_ttl_seconds' => env('ACCOUNTING_PEERS_NONCE_TTL', 600),

    /*
     | Largest inbound body accepted, checked BEFORE parsing. A 20 MB
     | attachment is ~27 MB once base64-encoded, so this leaves headroom.
     */
    'max_payload_bytes' => env('ACCOUNTING_PEERS_MAX_PAYLOAD', 32 * 1024 * 1024),

    /*
     | How long an external claim link stays live.
     */
    'claim_link_ttl_days' => env('ACCOUNTING_PEERS_CLAIM_TTL_DAYS', 30),

    /*
     | SSRF protection. endpoint_url is operator-supplied and we POST to it,
     | so by default we refuse non-HTTPS and any address that resolves into
     | private or loopback space -- otherwise a hostile peer URL turns this
     | server into an internal network scanner.
     |
     | allow_local exists ONLY so two instances can be paired on one machine
     | for local testing. It must stay false in production.
     */
    'allow_local_endpoints' => env('ACCOUNTING_PEERS_ALLOW_LOCAL', false),

    'require_https' => env('ACCOUNTING_PEERS_REQUIRE_HTTPS', true),

    /*
     | Outbound HTTP timeouts, seconds.
     */
    'connect_timeout' => env('ACCOUNTING_PEERS_CONNECT_TIMEOUT', 10),

    'request_timeout' => env('ACCOUNTING_PEERS_REQUEST_TIMEOUT', 120),
];
