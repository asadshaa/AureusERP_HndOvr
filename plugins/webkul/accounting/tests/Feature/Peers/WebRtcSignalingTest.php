<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\WebRtcSessionStatus;
use Webkul\Accounting\Models\DocumentAudit;
use Webkul\Accounting\Models\WebRtcSession;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Peers\WebRtcSignalingService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->currency = Currency::query()->first() ?? Currency::factory()->create();

    $this->company = Company::factory()->create(['is_active' => true]);

    $this->sender = documentTestUser($this->company, [
        AccountingPermissions::SendTransmissions,
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::ViewDocuments,
    ]);

    $this->signaling = app(WebRtcSignalingService::class);

    $this->makeInvoice = fn (?Company $company = null) => Move::factory()->create([
        'company_id'   => ($company ?? $this->company)->id,
        'currency_id'  => $this->currency->id,
        'name'         => 'INV/2026/0001',
        'amount_total' => 150.00,
    ]);
});

/*
|--------------------------------------------------------------------------
| Handshake
|--------------------------------------------------------------------------
*/

test('opens a session and records it in the existing audit trail', function () {
    $move = ($this->makeInvoice)();

    $session = $this->signaling->createSession($this->sender, $move, ipAddress: '10.0.0.9');

    expect($session)->toBeInstanceOf(WebRtcSession::class)
        ->and($session->status)->toBe(WebRtcSessionStatus::Waiting)
        ->and($session->company_id)->toBe($this->company->id)
        ->and($session->creator_id)->toBe($this->sender->id);

    // Reuses the committed feature's audit action rather than adding a
    // parallel webrtc_* case, with the transport in metadata.
    $audit = DocumentAudit::query()
        ->where('company_id', $this->company->id)
        ->where('action', DocumentAuditAction::TransmissionSent)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($this->sender->id)
        ->and($audit->ip_address)->toBe('10.0.0.9')
        ->and($audit->metadata['transport'])->toBe('webrtc')
        ->and($audit->metadata['session_id'])->toBe($session->id);
});

test('issues codes with enough entropy to guard the anonymous endpoints', function () {
    $move = ($this->makeInvoice)();

    $codes = collect(range(1, 25))
        ->map(fn () => $this->signaling->createSession($this->sender, $move)->code);

    // AUR- plus 10 Crockford base32 symbols, grouped: 50 bits.
    $codes->each(function (string $code) {
        expect($code)->toMatch('/^AUR-[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/')
            ->and(strlen($code))->toBeLessThanOrEqual(16);
    });

    expect($codes->unique())->toHaveCount(25);
});

test('accepts a code however the recipient types it', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());

    $stripped = str_replace('-', '', $session->code);

    expect($this->signaling->getActiveSession(strtolower($session->code))?->id)->toBe($session->id)
        ->and($this->signaling->getActiveSession($stripped)?->id)->toBe($session->id)
        ->and($this->signaling->getActiveSession(' '.$session->code.' ')?->id)->toBe($session->id);
});

test('exchanges SDP and ICE candidates between the two sides', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());

    $offer = "v=0\r\no=- 12345 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n";
    $this->signaling->setOffer($this->sender, $session->code, $offer);

    expect($session->refresh()->offer_sdp)->toBe($offer);

    $answer = "v=0\r\no=- 67890 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n";
    $this->signaling->submitAnswer($session->code, $answer);

    expect($session->refresh()->answer_sdp)->toBe($answer)
        ->and($session->status)->toBe(WebRtcSessionStatus::Connected);

    $senderCandidate = ['candidate' => 'candidate:1 1 UDP 2122260223 192.168.1.100 54321 typ host', 'sdpMid' => '0'];
    $receiverCandidate = ['candidate' => 'candidate:2 1 UDP 2122260223 192.168.1.101 54322 typ host', 'sdpMid' => '0'];

    $this->signaling->addCandidate($session->code, 'sender', $senderCandidate);
    $this->signaling->addCandidate($session->code, 'receiver', $receiverCandidate);

    // Each side is served only the OTHER side's candidates.
    expect($this->signaling->poll($session->code, 'receiver', 0)['candidates'])
        ->toHaveCount(1)
        ->and($this->signaling->poll($session->code, 'receiver', 0)['candidates'][0]['candidate'])
        ->toBe($senderCandidate['candidate'])
        ->and($this->signaling->poll($session->code, 'sender', 0)['candidates'][0]['candidate'])
        ->toBe($receiverCandidate['candidate']);
});

test('completes a transfer once, and audits the delivery', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());

    expect($this->signaling->complete($session->code))->not->toBeNull()
        ->and($session->refresh()->status)->toBe(WebRtcSessionStatus::Completed)
        ->and($session->completed_at)->not->toBeNull();

    expect(DocumentAudit::query()
        ->where('action', DocumentAuditAction::TransmissionDelivered)
        ->where('company_id', $this->company->id)
        ->exists())->toBeTrue();

    // Terminal is terminal: a completed session cannot be completed again.
    expect($this->signaling->complete($session->code))->toBeNull();
});

test('returns null instead of fatalling on an unknown code', function () {
    // The prototype declared complete(): WebRtcSession and returned null,
    // turning any unknown code into an uncaught TypeError -- a 500 on an
    // unauthenticated route.
    expect($this->signaling->complete('AUR-ZZZZZ-ZZZZZ'))->toBeNull();

    $this->postJson('/api/v1/webrtc/sessions/AUR-ZZZZZ-ZZZZZ/complete', [])
        ->assertOk()
        ->assertJson(['success' => false, 'status' => 'expired']);
});

/*
|--------------------------------------------------------------------------
| Authorization and isolation
|--------------------------------------------------------------------------
*/

test('refuses to open a session for another company record', function () {
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $foreignMove = ($this->makeInvoice)($otherCompany);

    expect(fn () => $this->signaling->createSession($this->sender, $foreignMove))
        ->toThrow(RuntimeException::class, 'another company');

    expect(DocumentAudit::query()
        ->where('action', DocumentAuditAction::AccessDenied)
        ->whereJsonContains('metadata->reason', 'cross_company_transmittable')
        ->exists())->toBeTrue();
});

test('refuses to open a session without the send permission', function () {
    $outsider = documentTestUser($this->company, [AccountingPermissions::ViewDocuments]);

    expect(fn () => $this->signaling->createSession($outsider, ($this->makeInvoice)()))
        ->toThrow(RuntimeException::class, 'permission');
});

test('hides another company invoice behind the same 404 as a missing one', function () {
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $foreignMove = ($this->makeInvoice)($otherCompany);

    $foreign = $this->actingAs($this->sender)
        ->postJson('/api/v1/webrtc/sessions', ['type' => 'invoice', 'id' => $foreignMove->id]);

    $missing = $this->actingAs($this->sender)
        ->postJson('/api/v1/webrtc/sessions', ['type' => 'invoice', 'id' => 99999999]);

    expect($foreign->status())->toBe($missing->status());
});

test('only the sender may publish the offer', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());
    $other = documentTestUser($this->company, [AccountingPermissions::SendTransmissions]);

    expect(fn () => $this->signaling->setOffer($other, $session->code, 'sdp'))
        ->toThrow(RuntimeException::class, 'Only the sender');
});

/*
|--------------------------------------------------------------------------
| The bytes endpoint -- the hole the prototype left open
|--------------------------------------------------------------------------
*/

test('does not serve file bytes to another company user holding the code', function () {
    $document = app(DocumentService::class)->upload(
        $this->sender,
        $this->company->id,
        DocumentType::cases()[0],
        'Quarterly report',
        null,
        fakeUploadedFileWithRealContent('report.pdf', 'application/pdf'),
    );

    $session = $this->signaling->createSession($this->sender, $document);

    // The sender's own browser may read its own bytes.
    $this->actingAs($this->sender)
        ->get("/api/v1/webrtc/sessions/{$session->code}/binary")
        ->assertOk();

    // Anyone else holding the code may not -- this is exactly what the
    // prototype allowed, and what its client used as a "server fallback".
    $intruder = documentTestUser(Company::factory()->create(['is_active' => true]), [
        AccountingPermissions::DownloadDocuments,
    ]);

    $this->actingAs($intruder)
        ->get("/api/v1/webrtc/sessions/{$session->code}/binary")
        ->assertForbidden();

    expect(DocumentAudit::query()
        ->where('action', DocumentAuditAction::AccessDenied)
        ->whereJsonContains('metadata->reason', 'not_session_creator')
        ->exists())->toBeTrue();
});

test('refuses the bytes endpoint to an anonymous caller', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());

    // Asked as JSON: the app names no 'login' route, so a browser-shaped
    // request makes Authenticate fail building its redirect rather than
    // answering cleanly. Either way no bytes are served.
    $this->getJson("/api/v1/webrtc/sessions/{$session->code}/binary")
        ->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Anonymous surface
|--------------------------------------------------------------------------
*/

test('does not leak the invoice payload to an unauthenticated caller', function () {
    $move = ($this->makeInvoice)();
    $session = $this->signaling->createSession($this->sender, $move);

    $body = $this->getJson("/api/v1/webrtc/sessions/{$session->code}")
        ->assertOk()
        ->json();

    // Enough to decide whether to accept the file, and nothing more. The
    // prototype returned the whole formatted invoice here, so guessing a
    // code leaked it with no login at all.
    expect($body['metadata'])->toHaveKeys(['type', 'title', 'filename', 'mime_type'])
        ->and($body['metadata'])->not->toHaveKey('invoice_payload')
        ->and($body['metadata'])->not->toHaveKey('total_amount')
        ->and(json_encode($body))->not->toContain('150');
});

test('lets only the first recipient answer, so a guesser cannot hijack the stream', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());

    $this->postJson("/api/v1/webrtc/sessions/{$session->code}/answer", ['answer_sdp' => 'real_recipient'])
        ->assertOk()
        ->assertJson(['success' => true]);

    // Without this guard an attacker races the real recipient, posts their
    // own answer, and the sender streams the file to them instead.
    $this->postJson("/api/v1/webrtc/sessions/{$session->code}/answer", ['answer_sdp' => 'attacker'])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($session->refresh()->answer_sdp)->toContain('real_recipient');
});

test('treats an expired session as gone', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());

    $session->forceFill(['expires_at' => now()->subMinute()])->save();

    expect($this->signaling->getActiveSession($session->code))->toBeNull();

    $this->getJson("/api/v1/webrtc/sessions/{$session->code}")->assertNotFound();

    $this->actingAs($this->sender)
        ->get("/api/v1/webrtc/sessions/{$session->code}/binary")
        ->assertNotFound();
});

test('stops serving a completed session for the rest of its TTL', function () {
    $document = app(DocumentService::class)->upload(
        $this->sender,
        $this->company->id,
        DocumentType::cases()[0],
        'Already delivered',
        null,
        fakeUploadedFileWithRealContent('done.pdf', 'application/pdf'),
    );

    $session = $this->signaling->createSession($this->sender, $document);
    $this->signaling->complete($session->code);

    // Still inside expires_at, but terminal. The prototype checked expiry
    // only, so a finished transfer stayed fully replayable.
    expect($session->refresh()->expires_at->isFuture())->toBeTrue()
        ->and($this->signaling->getActiveSession($session->code))->toBeNull();

    $this->getJson("/api/v1/webrtc/sessions/{$session->code}")->assertNotFound();

    $this->actingAs($this->sender)
        ->get("/api/v1/webrtc/sessions/{$session->code}/binary")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Pages and surface area
|--------------------------------------------------------------------------
*/

test('serves the sender console only to the sender', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());
    $other = documentTestUser($this->company, [AccountingPermissions::SendTransmissions]);

    $this->actingAs($this->sender)->get("/p2p/send/{$session->code}")->assertOk();

    // Same 404 as a missing session: holding a code tells you nothing about
    // whose transfer it is.
    $this->actingAs($other)->get("/p2p/send/{$session->code}")->assertNotFound();
});

test('keeps the recipient page open to someone with no account here', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());

    $this->get("/p2p/receive/{$session->code}")->assertOk();
});

test('exposes no ingest endpoint', function () {
    $session = $this->signaling->createSession($this->sender, ($this->makeInvoice)());

    // Removed rather than secured: an inbound accounting record must be
    // attributable to an authenticated peer, and a WebRTC counterparty is
    // by construction just whoever held the code. See the spec.
    $this->actingAs($this->sender)
        ->postJson("/api/v1/webrtc/sessions/{$session->code}/ingest", ['payload' => ['format' => 'aureus.invoice.v1']])
        ->assertNotFound();
});

test('runs the whole handshake over HTTP', function () {
    $move = ($this->makeInvoice)();

    $init = $this->actingAs($this->sender)
        ->postJson('/api/v1/webrtc/sessions', ['type' => 'invoice', 'id' => $move->id])
        ->assertOk()
        ->assertJson(['success' => true]);

    $code = $init->json('code');

    $this->actingAs($this->sender)
        ->postJson("/api/v1/webrtc/sessions/{$code}/offer", ['offer_sdp' => 'offer'])
        ->assertOk();

    // The recipient half needs no account, only the code.
    $this->getJson("/api/v1/webrtc/sessions/{$code}")->assertOk()->assertJson(['status' => 'waiting']);

    $this->postJson("/api/v1/webrtc/sessions/{$code}/answer", ['answer_sdp' => 'answer'])->assertOk();

    $this->postJson("/api/v1/webrtc/sessions/{$code}/candidate", [
        'role'      => 'receiver',
        'candidate' => ['candidate' => 'candidate:1 1 UDP 1 10.0.0.1 1 typ host', 'sdpMid' => '0'],
    ])->assertOk();

    expect($this->getJson("/api/v1/webrtc/sessions/{$code}/poll?role=sender&offset=0")->json('candidates'))
        ->toHaveCount(1);

    $this->postJson("/api/v1/webrtc/sessions/{$code}/complete", [])
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'completed']);
});
