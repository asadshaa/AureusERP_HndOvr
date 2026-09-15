<?php

/**
 * The handshake, end to end, through the REAL /pair endpoint -- and then a
 * signed send from the redeeming side to prove the tokens were stored the
 * right way round.
 *
 * This test exists because the first real two-instance run 401'd on every
 * send: PeerResource mapped the far side's `our_token`/`your_token` the
 * wrong way round. Nothing else in the suite could catch that, because the
 * round-trip tests build both peer rows by hand with matching tokens. Here
 * the redeemer's row comes from the actual pairing response, mapped by the
 * same code the UI now uses.
 *
 * Two companies in one database stand in for two instances, as in the other
 * peer tests. No TestBootstrapHelper (its AccountSeeder null-company bug
 * crashes whole files).
 */

use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\PeerStatus;
use Webkul\Accounting\Models\InboundTransmission;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\Peers\JsonV1InvoicePayloadFormatter;
use Webkul\Accounting\Services\Peers\PeerPairingService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Support\Peers\PeerSignature;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    // The guard refuses loopback/private hosts by default; the test peers
    // live at made-up public-looking hostnames that never resolve, so we
    // allow local and use IPs to keep DNS out of it.
    config()->set('accounting_peers.allow_local_endpoints', true);
    config()->set('accounting_peers.require_https', false);

    $this->inviter = Company::factory()->create(['is_active' => true, 'name' => 'Inviting Co']);
    $this->redeemer = Company::factory()->create(['is_active' => true, 'name' => 'Redeeming Co']);

    $this->inviterAdmin = documentTestUser($this->inviter, [AccountingPermissions::ManagePeers]);
    $this->redeemerAdmin = documentTestUser($this->redeemer, [
        AccountingPermissions::ManagePeers,
        AccountingPermissions::SendTransmissions,
    ]);

    $this->vendorOnInviter = Partner::factory()->create(['company_id' => $this->inviter->id]);
});

it('pairs through the real /pair endpoint and the redeemer can then send a signed request that is accepted', function () {
    $pairing = app(PeerPairingService::class);

    // --- Inviter issues a code (what "Invite a peer" does) -----------------
    $invite = $pairing->invite($this->inviterAdmin, 'Redeeming Co', $this->vendorOnInviter->id);

    // --- Redeemer hits the inviter's /pair over HTTP -----------------------
    $response = $this->postJson('/api/v1/peer/pair', [
        'code'         => $invite['code'],
        'endpoint_url' => 'http://127.0.0.1:8000',
        'name'         => 'Redeeming Co',
    ]);

    $response->assertOk()->assertJsonStructure(['signing_secret', 'your_token', 'our_token', 'peer_name']);

    // --- Redeemer stores it, via the SAME mapping the UI uses --------------
    $redeemerPeer = $pairing->acceptPairingResponse(
        actor: $this->redeemerAdmin,
        name: 'Inviting Co',
        endpointUrl: 'http://127.0.0.1:8001',
        response: $response->json(),
    );

    $inviterPeer = Peer::query()->where('company_id', $this->inviter->id)->active()->firstOrFail();

    expect($redeemerPeer->status)->toBe(PeerStatus::Active)
        ->and($inviterPeer->status)->toBe(PeerStatus::Active)
        // The whole point: what the redeemer will PRESENT must be what the
        // inviter EXPECTS. With the swap this pair of assertions fails.
        ->and(hash('sha256', $redeemerPeer->outbound_token))->toBe($inviterPeer->inbound_token_hash)
        ->and(hash('sha256', $inviterPeer->outbound_token))->toBe($redeemerPeer->inbound_token_hash)
        ->and($redeemerPeer->signing_secret)->toBe($inviterPeer->signing_secret);

    // --- And prove it on the wire: a signed send from the redeemer ---------
    $invoice = Move::factory()->create([
        'company_id'  => $this->redeemer->id,
        'currency_id' => Currency::query()->firstOrFail()->id,
        'move_type'   => MoveType::OUT_INVOICE,
        'state'       => MoveState::POSTED,
        'name'        => 'INV/2026/HANDSHAKE',
    ]);

    $body = json_encode([
        'reference' => 'handshake-send-1',
        'payload'   => (new JsonV1InvoicePayloadFormatter)->format($invoice),
    ], JSON_UNESCAPED_SLASHES);

    $headers = PeerSignature::headers($body, $redeemerPeer->signing_secret);

    $send = $this->call(
        'POST',
        '/api/v1/peer/transmissions',
        [], [], [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.str_replace('-', '_', strtoupper($k)) => $v])->all()
            + ['HTTP_AUTHORIZATION' => 'Bearer '.$redeemerPeer->outbound_token, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $send->assertStatus(202);

    $inbound = InboundTransmission::query()->firstOrFail();

    expect($inbound->company_id)->toBe($this->inviter->id)
        ->and($inbound->peer_id)->toBe($inviterPeer->id);
});

it('refuses a pairing response that is missing a credential instead of storing a half-paired peer', function () {
    expect(fn () => app(PeerPairingService::class)->acceptPairingResponse(
        actor: $this->redeemerAdmin,
        name: 'Inviting Co',
        endpointUrl: 'http://127.0.0.1:8001',
        response: ['signing_secret' => 'x', 'your_token' => 'y'], // no our_token
    ))->toThrow(RuntimeException::class, "missing 'our_token'");

    expect(Peer::query()->where('company_id', $this->redeemer->id)->count())->toBe(0);
});

it('rejects a used or unknown pairing code with one neutral message', function () {
    $invite = app(PeerPairingService::class)->invite($this->inviterAdmin, 'Redeeming Co');

    $first = $this->postJson('/api/v1/peer/pair', [
        'code' => $invite['code'], 'endpoint_url' => 'http://127.0.0.1:8000', 'name' => 'Redeeming Co',
    ]);
    $first->assertOk();

    // Same code again: consumed.
    $reuse = $this->postJson('/api/v1/peer/pair', [
        'code' => $invite['code'], 'endpoint_url' => 'http://127.0.0.1:8000', 'name' => 'Redeeming Co',
    ]);

    // Never issued.
    $unknown = $this->postJson('/api/v1/peer/pair', [
        'code' => 'NOPE-NOPE', 'endpoint_url' => 'http://127.0.0.1:8000', 'name' => 'Redeeming Co',
    ]);

    $reuse->assertStatus(422);
    $unknown->assertStatus(422);

    // Identical wording, so a caller cannot tell "exists but used" from
    // "never existed".
    expect($reuse->json('message'))->toBe($unknown->json('message'));
});
