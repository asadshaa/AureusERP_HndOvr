<?php

namespace Webkul\Accounting\Services\Peers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\PeerStatus;
use Webkul\Accounting\Models\DocumentAudit;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Support\Peers\PeerEndpointGuard;
use Webkul\Security\Models\User;

/**
 * The trust handshake.
 *
 * Corner A calls invite() and reads out a one-time code. Corner B calls
 * redeem() with it. Both end up holding the same signing secret and a token
 * for the other direction, and neither ever transmits a recoverable
 * credential in the clear after that point.
 */
class PeerPairingService
{
    /**
     * Issue a one-time pairing code. Only the hash is kept, so the plaintext
     * shown to the operator here is the only copy that will ever exist.
     *
     * @return array{peer: Peer, code: string}
     */
    public function invite(User $actor, string $name, ?int $partnerId = null): array
    {
        $this->assertEnabled();
        $this->assertPermission($actor, AccountingPermissions::ManagePeers);

        $code = Str::upper(Str::random(8).'-'.Str::random(8));

        $peer = Peer::create([
            'company_id'         => $actor->default_company_id,
            'name'               => $name,
            'partner_id'         => $partnerId,
            // Filled in by the far side when it redeems the code.
            'endpoint_url'       => '',
            'status'             => PeerStatus::Pending,
            'pairing_code_hash'  => hash('sha256', $code),
            'pairing_expires_at' => now()->addMinutes((int) config('accounting_peers.pairing_code_ttl_minutes', 15)),
            'signing_secret'     => Str::random(64),
            'inbound_token_hash' => null,
        ]);

        return ['peer' => $peer, 'code' => $code];
    }

    /**
     * Called on corner A when corner B presents the code. Returns the
     * material B needs; A simultaneously records what it will accept from B.
     *
     * @return array{signing_secret: string, token_for_caller: string, peer: Peer}
     */
    public function redeem(string $code, string $callerEndpoint, string $callerName): array
    {
        $this->assertEnabled();

        PeerEndpointGuard::assertAllowed($callerEndpoint);

        $peer = Peer::query()
            ->where('pairing_code_hash', hash('sha256', $code))
            ->first();

        if (! $peer || ! $peer->hasLivePairingCode()) {
            // Deliberately identical message for unknown and expired: a
            // different one would let a caller probe which codes exist.
            throw new RuntimeException('That pairing code is not valid.');
        }

        return DB::transaction(function () use ($peer, $callerEndpoint, $callerName) {
            // Token THEY will present to US -- we keep only its hash.
            $inboundToken = Str::random(64);

            // Token WE will present to THEM -- kept encrypted, since we must
            // be able to read it back to send.
            $outboundToken = Str::random(64);

            $peer->forceFill([
                'endpoint_url'       => $callerEndpoint,
                'name'               => $peer->name ?: $callerName,
                'status'             => PeerStatus::Active,
                'inbound_token_hash' => hash('sha256', $inboundToken),
                'outbound_token'     => $outboundToken,
                'pairing_code_hash'  => null,
                'pairing_expires_at' => null,
                'paired_at'          => now(),
            ])->save();

            $this->audit($peer, DocumentAuditAction::PeerPaired, null);

            return [
                'signing_secret'   => $peer->signing_secret,
                // From B's point of view these swap roles.
                'token_for_caller' => $inboundToken,
                'token_for_us'     => $outboundToken,
                'peer'             => $peer,
            ];
        });
    }

    /**
     * Called on corner B: store what A handed back.
     */
    public function acceptPairing(
        User $actor,
        string $name,
        string $endpointUrl,
        string $signingSecret,
        string $outboundToken,
        string $inboundToken,
        ?int $partnerId = null,
    ): Peer {
        $this->assertEnabled();
        $this->assertPermission($actor, AccountingPermissions::ManagePeers);

        PeerEndpointGuard::assertAllowed($endpointUrl);

        $peer = Peer::create([
            'company_id'         => $actor->default_company_id,
            'name'               => $name,
            'partner_id'         => $partnerId,
            'endpoint_url'       => rtrim($endpointUrl, '/'),
            'status'             => PeerStatus::Active,
            'signing_secret'     => $signingSecret,
            'outbound_token'     => $outboundToken,
            'inbound_token_hash' => hash('sha256', $inboundToken),
            'paired_at'          => now(),
        ]);

        $this->audit($peer, DocumentAuditAction::PeerPaired, $actor);

        return $peer;
    }

    /**
     * Corner B, from the far side's raw /pair response.
     *
     * The far side names tokens from ITS caller's point of view:
     * `your_token` is what it expects US to present (our outbound), and
     * `our_token` is what IT will present to us (what we verify inbound).
     * Getting these backwards produces a 401 on every send -- found on the
     * first real two-instance run. Mapping lives here, once, so no caller
     * can re-introduce the swap.
     *
     * @param  array<string, mixed>  $response
     */
    public function acceptPairingResponse(
        User $actor,
        string $name,
        string $endpointUrl,
        array $response,
        ?int $partnerId = null,
    ): Peer {
        foreach (['signing_secret', 'your_token', 'our_token'] as $key) {
            if (empty($response[$key]) || ! is_string($response[$key])) {
                throw new RuntimeException("The peer's pairing response is missing '{$key}'.");
            }
        }

        return $this->acceptPairing(
            actor: $actor,
            name: $name,
            endpointUrl: $endpointUrl,
            signingSecret: $response['signing_secret'],
            outboundToken: $response['your_token'],
            inboundToken: $response['our_token'],
            partnerId: $partnerId,
        );
    }

    public function revoke(User $actor, Peer $peer): Peer
    {
        $this->assertPermission($actor, AccountingPermissions::ManagePeers);

        $peer->forceFill([
            'status'     => PeerStatus::Revoked,
            'revoked_at' => now(),
        ])->save();

        $this->audit($peer, DocumentAuditAction::PeerRevoked, $actor);

        return $peer;
    }

    private function assertEnabled(): void
    {
        if (! config('accounting_peers.enabled', true)) {
            throw new RuntimeException('Peer exchange is disabled on this instance.');
        }
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new RuntimeException('You do not have permission to manage peers.');
        }
    }

    private function audit(Peer $peer, DocumentAuditAction $action, ?User $actor): void
    {
        DocumentAudit::create([
            'company_id'  => $peer->company_id,
            'document_id' => null,
            'actor_id'    => $actor?->id,
            'action'      => $action,
            'metadata'    => ['peer_id' => $peer->id, 'peer_name' => $peer->name],
        ]);
    }
}
