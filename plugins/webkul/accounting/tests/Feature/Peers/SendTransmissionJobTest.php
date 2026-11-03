<?php

/**
 * SendTransmissionJob at send time: the peer endpoint is re-checked by
 * PeerEndpointGuard before every POST (DNS may have moved it into private
 * space since pairing), redirects are never followed and a 3xx answer is
 * terminal, and a send whose retries ran out is closed as failed (failed()
 * hook) instead of staying "queued" forever.
 *
 * Also: an email-channel send whose claim-link email could not go out is
 * reported to the sender (emailed = false) and recorded on the transmission
 * and in the audit trail.
 */

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Contracts\InvoicePayloadFormatter;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\OutboundTransmissionStatus;
use Webkul\Accounting\Enums\PeerStatus;
use Webkul\Accounting\Enums\TransmissionChannel;
use Webkul\Accounting\Jobs\SendTransmissionJob;
use Webkul\Accounting\Models\DocumentAudit;
use Webkul\Accounting\Models\OutboundTransmission;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\Peers\DocumentExchangeService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Support\Peers\UnresolvablePeerHostException;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    config()->set('accounting_peers.allow_local_endpoints', false);
    config()->set('accounting_peers.require_https', true);

    $this->company = Company::factory()->create(['is_active' => true]);
    $this->sender = documentTestUser($this->company, [AccountingPermissions::SendTransmissions]);

    $this->invoice = Move::factory()->create([
        'company_id'   => $this->company->id,
        'currency_id'  => Currency::query()->firstOrFail()->id,
        'move_type'    => MoveType::OUT_INVOICE,
        'state'        => MoveState::POSTED,
        'name'         => 'INV/2026/00901',
        'invoice_date' => '2026-11-02',
    ]);
});

/** A queued transmission of the invoice to a peer at $endpoint. */
function queuedTransmissionTo(object $test, string $endpoint): OutboundTransmission
{
    $peer = Peer::create([
        'company_id'     => $test->company->id,
        'name'           => 'Buyer Instance',
        'endpoint_url'   => $endpoint,
        'status'         => PeerStatus::Active,
        'signing_secret' => 'secret-'.Str::random(16),
        'outbound_token' => 'token-'.Str::random(16),
        'paired_at'      => now(),
    ]);

    return OutboundTransmission::create([
        'company_id'         => $test->company->id,
        'peer_id'            => $peer->id,
        'creator_id'         => $test->sender->id,
        'channel'            => TransmissionChannel::Peer,
        'transmittable_type' => Move::class,
        'transmittable_id'   => $test->invoice->id,
        'status'             => OutboundTransmissionStatus::Queued,
        'payload_type'       => 'aureus.invoice.v1',
        'payload_sha256'     => hash('sha256', 'x'),
        'idempotency_key'    => (string) Str::uuid(),
    ]);
}

function runSendJob(OutboundTransmission $transmission): void
{
    (new SendTransmissionJob($transmission->id))->handle(app(InvoicePayloadFormatter::class));
}

it('refuses at send time a peer endpoint that is now private, without sending anything', function () {
    Http::fake();
    $transmission = queuedTransmissionTo($this, 'https://10.20.30.40');

    runSendJob($transmission);

    $transmission->refresh();
    expect($transmission->status)->toBe(OutboundTransmissionStatus::Failed)
        ->and($transmission->last_error)->toContain('Peer endpoint refused')->toContain('10.20.30.40')
        ->and(DocumentAudit::query()->where('action', DocumentAuditAction::TransmissionFailed)->where('metadata->transmission_id', $transmission->id)->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('retries an unresolvable peer host instead of contacting it unchecked', function () {
    Http::fake();
    $transmission = queuedTransmissionTo($this, 'https://peer-host-that-does-not-exist.invalid');

    expect(fn () => runSendJob($transmission))->toThrow(UnresolvablePeerHostException::class);

    $transmission->refresh();
    expect($transmission->status)->toBe(OutboundTransmissionStatus::Queued)
        ->and($transmission->last_error)->toContain('Could not resolve');
    Http::assertNothingSent();
});

it('never follows a redirect from the peer and fails the transmission on a 3xx', function () {
    Http::fake([
        '93.184.216.34/*'   => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        '169.254.169.254/*' => Http::response('instance secrets', 200),
    ]);
    $transmission = queuedTransmissionTo($this, 'https://93.184.216.34');

    runSendJob($transmission);

    $transmission->refresh();
    expect($transmission->status)->toBe(OutboundTransmissionStatus::Failed)
        ->and($transmission->last_error)->toContain('redirect (302)')->toContain('169.254.169.254');
    Http::assertSentCount(1);
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '169.254.169.254'));
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://93.184.216.34/api/v1/peer/transmissions');
});

it('delivers to a public endpoint', function () {
    Http::fake(['93.184.216.34/*' => Http::response(['ok' => true], 202)]);
    $transmission = queuedTransmissionTo($this, 'https://93.184.216.34');

    runSendJob($transmission);

    expect($transmission->refresh()->status)->toBe(OutboundTransmissionStatus::Delivered)
        ->and($transmission->attempts)->toBe(1);
});

it('marks the transmission failed once retries are exhausted instead of leaving it queued', function () {
    Http::fake(['93.184.216.34/*' => Http::response('down', 503)]);
    $transmission = queuedTransmissionTo($this, 'https://93.184.216.34');
    $job = new SendTransmissionJob($transmission->id);

    // A 5xx is retryable: the job throws for the queue to retry, still queued.
    expect(fn () => $job->handle(app(InvoicePayloadFormatter::class)))->toThrow(RuntimeException::class, 'Peer returned 503.');
    expect($transmission->refresh()->status)->toBe(OutboundTransmissionStatus::Queued);

    // The queue gives up: failed() closes it out, once.
    $job->failed(new RuntimeException('Peer returned 503.'));
    $job->failed(new RuntimeException('Peer returned 503.'));

    $transmission->refresh();
    expect($transmission->status)->toBe(OutboundTransmissionStatus::Failed)
        ->and($transmission->last_error)->toBe('Delivery gave up after 1 attempt(s): Peer returned 503.')
        ->and(DocumentAudit::query()->where('action', DocumentAuditAction::TransmissionFailed)->where('metadata->transmission_id', $transmission->id)->count())->toBe(1);
});

it('tells the sender and records it when the claim-link email could not be sent', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP connection refused'));

    $result = app(DocumentExchangeService::class)->sendInvoiceToEmail($this->sender, $this->invoice, 'buyer@example.com');

    expect($result['emailed'])->toBeFalse()
        ->and($result['claim_token'])->not->toBeEmpty()
        ->and($result['transmission']->fresh()->last_error)->toBe('Claim link email not sent: SMTP connection refused');

    $audit = DocumentAudit::query()->where('action', DocumentAuditAction::ClaimLinkEmailFailed)->sole();
    expect($audit->metadata['transmission_id'])->toBe($result['transmission']->id)
        ->and($audit->metadata['recipient'])->toBe('buyer@example.com')
        ->and($audit->metadata['error'])->toBe('SMTP connection refused')
        ->and($audit->actor_id)->toBe($this->sender->id);
});

it('reports a sent claim-link email as emailed, with no failure recorded', function () {
    Mail::fake();

    $result = app(DocumentExchangeService::class)->sendInvoiceToEmail($this->sender, $this->invoice, 'buyer@example.com');

    expect($result['emailed'])->toBeTrue()
        ->and($result['transmission']->fresh()->last_error)->toBeNull()
        ->and(DocumentAudit::query()->where('action', DocumentAuditAction::ClaimLinkEmailFailed)->exists())->toBeFalse();
});
