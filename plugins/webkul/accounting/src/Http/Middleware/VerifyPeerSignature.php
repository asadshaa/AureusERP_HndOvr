<?php

namespace Webkul\Accounting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Models\DocumentAudit;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Support\Peers\PeerSignature;
use Webkul\Support\Models\Company;

/**
 * Authenticates a peer instance.
 *
 * Peers are not users, so this deliberately does not go through
 * `auth:sanctum`. Checks run cheapest-first and each failure is audited,
 * because a run of failures is exactly what a probe looks like.
 *
 * On success the peer is attached to the request as `peer`.
 */
class VerifyPeerSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('accounting_peers.enabled', true)) {
            return response()->json(['message' => 'Peer exchange is disabled.'], 403);
        }

        $raw = $request->getContent();

        // Size is checked before anything parses the body, so an oversized
        // payload cannot exhaust memory on its way to being rejected.
        $max = (int) config('accounting_peers.max_payload_bytes', 32 * 1024 * 1024);

        if (strlen($raw) > $max) {
            return $this->deny(null, 'Payload too large.', 413);
        }

        $token = $this->bearerToken($request);

        if (! $token) {
            return $this->deny(null, 'Missing peer credentials.');
        }

        $peer = Peer::query()
            ->where('inbound_token_hash', hash('sha256', $token))
            ->first();

        if (! $peer) {
            return $this->deny(null, 'Unknown peer.');
        }

        if (! $peer->isActive()) {
            return $this->deny($peer, 'Peer is not active.');
        }

        $timestamp = $request->header(PeerSignature::HEADER_TIMESTAMP);
        $nonce = $request->header(PeerSignature::HEADER_NONCE);
        $signature = $request->header(PeerSignature::HEADER_SIGNATURE);

        if (! $timestamp || ! $nonce || ! $signature) {
            return $this->deny($peer, 'Missing signature headers.');
        }

        $skew = (int) config('accounting_peers.timestamp_skew_seconds', 300);

        if (! is_numeric($timestamp) || abs(time() - (int) $timestamp) > $skew) {
            return $this->deny($peer, 'Timestamp outside the accepted window.');
        }

        // Replay: a nonce is only worth remembering for as long as its
        // timestamp could still pass the skew check above.
        $nonceKey = "peer:{$peer->id}:nonce:{$nonce}";

        if (! Cache::add($nonceKey, true, (int) config('accounting_peers.nonce_ttl_seconds', 600))) {
            return $this->deny($peer, 'Replayed request.');
        }

        $expected = PeerSignature::compute($raw, (string) $timestamp, (string) $nonce, $peer->signing_secret);

        if (! PeerSignature::matches($expected, $signature)) {
            return $this->deny($peer, 'Signature mismatch.');
        }

        $request->attributes->set('peer', $peer);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ')
            ? substr($header, 7)
            : null;
    }

    private function deny(?Peer $peer, string $reason, int $status = 401): Response
    {
        DocumentAudit::create([
            'company_id'  => $peer?->company_id ?? $this->fallbackCompanyId(),
            'document_id' => null,
            'actor_id'    => null,
            'action'      => DocumentAuditAction::PeerAuthFailed,
            'metadata'    => ['peer_id' => $peer?->id, 'reason' => $reason],
            'ip_address'  => request()->ip(),
        ]);

        // Deliberately uniform: distinguishing "unknown peer" from "bad
        // signature" would let a caller enumerate valid tokens.
        return response()->json(['message' => 'Unauthorized.'], $status);
    }

    /**
     * An unauthenticated caller has no company, but the audit row needs one.
     */
    private function fallbackCompanyId(): int
    {
        return (int) (Company::query()->value('id') ?? 0);
    }
}
