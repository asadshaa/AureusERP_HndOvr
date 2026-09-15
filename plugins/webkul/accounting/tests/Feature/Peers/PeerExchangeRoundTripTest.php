<?php

/**
 * The walking skeleton: one test that proves the whole path rather than its
 * parts. Two companies in one database stand in for two instances -- the
 * transport is genuinely exercised (real HMAC, real signature verification,
 * real controller) by posting to our own peer endpoint.
 *
 * Deliberately does NOT use TestBootstrapHelper: its
 * ensurePluginInstalled('accounts') crashes whole files because
 * AccountSeeder::run() does Company::first() with no null guard. Follows the
 * DocumentServiceTest pattern instead.
 */

use Illuminate\Support\Facades\Queue;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Enums\InboundTransmissionStatus;
use Webkul\Accounting\Enums\OutboundTransmissionStatus;
use Webkul\Accounting\Enums\PeerStatus;
use Webkul\Accounting\Jobs\SendTransmissionJob;
use Webkul\Accounting\Models\InboundTransmission;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\Peers\DocumentExchangeService;
use Webkul\Accounting\Services\Peers\JsonV1InvoicePayloadFormatter;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Support\Peers\PeerSignature;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    $this->currency = Currency::query()->firstOrFail();

    $this->sellerCompany = Company::factory()->create(['is_active' => true]);
    $this->buyerCompany = Company::factory()->create(['is_active' => true]);

    $this->seller = documentTestUser($this->sellerCompany, [
        AccountingPermissions::SendTransmissions,
        AccountingPermissions::ManagePeers,
    ]);

    $this->reviewer = documentTestUser($this->buyerCompany, [
        AccountingPermissions::ReviewInboundTransmissions,
    ]);

    // The buyer's view of the seller. partner_id is what makes accept()
    // legal -- without it the mapping rules refuse, by design.
    $this->vendor = Partner::factory()->create([
        'company_id' => $this->buyerCompany->id,
    ]);

    // accept() resolves a LOCAL expense account rather than taking one from
    // the payload, so the buyer needs one to exist at all.
    $this->expenseAccount = Account::query()->create([
        'name'         => 'Peer Purchases',
        'code'         => 'PEER-EXP',
        'account_type' => AccountType::EXPENSE,
        'deprecated'   => false,
        'is_group'     => false,
        'currency_id'  => $this->currency->id,
    ]);

    // accept() resolves the buyer's purchase journal exactly as Create Bill
    // does; without one it refuses (see the refusal test below).
    $this->purchaseJournal = Journal::query()->create([
        'company_id'              => $this->buyerCompany->id,
        'currency_id'             => $this->currency->id,
        'default_account_id'      => $this->expenseAccount->id,
        'creator_id'              => $this->reviewer->id,
        'type'                    => JournalType::PURCHASE,
        'name'                    => 'Vendor Bills',
        'code'                    => 'BILL',
        'invoice_reference_type'  => 'invoice',
        'invoice_reference_model' => 'aureus',
    ]);

    $this->sharedSecret = 'test-signing-secret-'.bin2hex(random_bytes(8));
    $this->inboundToken = 'test-inbound-token-'.bin2hex(random_bytes(8));

    // Peer row on the BUYER side: this is what authenticates the seller.
    $this->peerOnBuyer = Peer::create([
        'company_id'         => $this->buyerCompany->id,
        'name'               => 'Seller Instance',
        'endpoint_url'       => 'https://seller.example.test',
        'status'             => PeerStatus::Active,
        'partner_id'         => $this->vendor->id,
        'signing_secret'     => $this->sharedSecret,
        'inbound_token_hash' => hash('sha256', $this->inboundToken),
        'paired_at'          => now(),
    ]);
});

function makeSellerInvoice(object $ctx): Move
{
    $invoice = Move::factory()->create([
        'company_id'   => $ctx->sellerCompany->id,
        'currency_id'  => $ctx->currency->id,
        'move_type'    => MoveType::OUT_INVOICE,
        'state'        => MoveState::POSTED,
        'name'         => 'INV/2026/00777',
        'invoice_date' => '2026-09-14',
    ]);

    MoveLine::query()->create([
        'move_id'        => $invoice->id,
        'company_id'     => $ctx->sellerCompany->id,
        'currency_id'    => $ctx->currency->id,
        'name'           => 'Consulting services',
        'quantity'       => 2,
        'price_unit'     => 15000,
        'price_subtotal' => 30000,
    ]);

    return $invoice->fresh();
}

it('carries an invoice from one instance to a draft bill on the other', function () {
    Queue::fake();

    // --- Corner A: the seller queues a send -------------------------------
    $exchange = app(DocumentExchangeService::class);

    $invoice = makeSellerInvoice($this);

    $peerOnSeller = Peer::create([
        'company_id'     => $this->sellerCompany->id,
        'name'           => 'Buyer Instance',
        'endpoint_url'   => 'https://buyer.example.test',
        'status'         => PeerStatus::Active,
        'signing_secret' => $this->sharedSecret,
        'outbound_token' => $this->inboundToken,
        'paired_at'      => now(),
    ]);

    $transmission = $exchange->sendInvoiceToPeer($this->seller, $invoice, $peerOnSeller);

    expect($transmission->status)->toBe(OutboundTransmissionStatus::Queued)
        ->and($transmission->idempotency_key)->not->toBeEmpty();

    Queue::assertPushed(SendTransmissionJob::class);

    // --- The wire: a genuinely signed request -----------------------------
    $body = json_encode([
        'reference' => $transmission->idempotency_key,
        'payload'   => (new JsonV1InvoicePayloadFormatter)->format($invoice),
    ], JSON_UNESCAPED_SLASHES);

    $headers = PeerSignature::headers($body, $this->sharedSecret);

    $response = $this->call(
        'POST',
        '/api/v1/peer/transmissions',
        [],
        [],
        [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.str_replace('-', '_', strtoupper($k)) => $v])->all()
            + ['HTTP_AUTHORIZATION' => 'Bearer '.$this->inboundToken, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    $response->assertStatus(202);

    // --- Corner B: it is evidence, not yet a bill -------------------------
    $inbound = InboundTransmission::query()->firstOrFail();

    expect($inbound->status)->toBe(InboundTransmissionStatus::Received)
        ->and($inbound->peer_id)->toBe($this->peerOnBuyer->id)
        ->and($inbound->created_move_id)->toBeNull();

    // --- A human accepts, and only then does the ledger gain a draft ------
    $bill = $exchange->accept($this->reviewer, $inbound);

    expect($bill->move_type)->toBe(MoveType::IN_INVOICE)
        ->and($bill->state)->toBe(MoveState::DRAFT)
        ->and($bill->company_id)->toBe($this->buyerCompany->id)
        ->and($bill->partner_id)->toBe($this->vendor->id)
        // The sender's number is a reference; our own numbering stays ours.
        ->and($bill->reference)->toBe('INV/2026/00777')
        // Booked into the buyer's own purchase journal, never one named by
        // the payload -- and never left null, which would leave it
        // unnumbered.
        ->and($bill->journal_id)->toBe($this->purchaseJournal->id)
        ->and($bill->lines()->count())->toBe(1);

    expect($inbound->fresh()->status)->toBe(InboundTransmissionStatus::Accepted);
});

it('treats a redelivered transmission as success instead of duplicating the invoice', function () {
    $invoice = makeSellerInvoice($this);

    $body = json_encode([
        'reference' => 'fixed-reference-for-replay',
        'payload'   => (new JsonV1InvoicePayloadFormatter)->format($invoice),
    ], JSON_UNESCAPED_SLASHES);

    $send = function () use ($body) {
        // A fresh nonce each time: this is a legitimate retry after a lost
        // response, not a replay attack.
        $headers = PeerSignature::headers($body, $this->sharedSecret);

        return $this->call(
            'POST',
            '/api/v1/peer/transmissions',
            [], [], [],
            collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.str_replace('-', '_', strtoupper($k)) => $v])->all()
                + ['HTTP_AUTHORIZATION' => 'Bearer '.$this->inboundToken, 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
    };

    $send()->assertStatus(202);
    $second = $send();

    $second->assertStatus(200);
    expect($second->json('duplicate'))->toBeTrue()
        ->and(InboundTransmission::query()->count())->toBe(1);
});

it('rejects a tampered body, a stale timestamp and a replayed nonce', function () {
    $invoice = makeSellerInvoice($this);

    $body = json_encode([
        'reference' => 'tamper-test',
        'payload'   => (new JsonV1InvoicePayloadFormatter)->format($invoice),
    ], JSON_UNESCAPED_SLASHES);

    $post = function (string $sendBody, array $headers) {
        return $this->call(
            'POST',
            '/api/v1/peer/transmissions',
            [], [], [],
            collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.str_replace('-', '_', strtoupper($k)) => $v])->all()
                + ['HTTP_AUTHORIZATION' => 'Bearer '.$this->inboundToken, 'CONTENT_TYPE' => 'application/json'],
            $sendBody,
        );
    };

    // Signature computed over the original, but a different body sent: this
    // is the "proxy quietly changes the amount" case.
    $headers = PeerSignature::headers($body, $this->sharedSecret);
    $post(str_replace('30000', '3', $body), $headers)->assertStatus(401);

    // Outside the skew window.
    $stale = PeerSignature::headers($body, $this->sharedSecret, null, time() - 100000);
    $post($body, $stale)->assertStatus(401);

    // Same nonce twice.
    $reused = PeerSignature::headers($body, $this->sharedSecret);
    $post($body, $reused)->assertStatus(202);
    $post($body, $reused)->assertStatus(401);

    expect(InboundTransmission::query()->count())->toBe(1);
});

it('refuses to accept until the peer is linked to a vendor', function () {
    $invoice = makeSellerInvoice($this);

    // Unlink: the payload names a seller, but naming is not deciding.
    $this->peerOnBuyer->forceFill(['partner_id' => null])->save();

    $inbound = app(DocumentExchangeService::class)->receive(
        $this->peerOnBuyer->fresh(),
        'needs-vendor-link',
        (new JsonV1InvoicePayloadFormatter)->format($invoice),
    );

    expect(fn () => app(DocumentExchangeService::class)->accept($this->reviewer, $inbound))
        ->toThrow(RuntimeException::class, 'before accepting its invoices');

    expect($inbound->fresh()->status)->toBe(InboundTransmissionStatus::Received)
        ->and(Move::query()->where('move_type', MoveType::IN_INVOICE)->count())->toBe(0);
});

it('rejects an inbound invoice without writing anything to the ledger', function () {
    $invoice = makeSellerInvoice($this);

    $inbound = app(DocumentExchangeService::class)->receive(
        $this->peerOnBuyer,
        'to-be-rejected',
        (new JsonV1InvoicePayloadFormatter)->format($invoice),
    );

    app(DocumentExchangeService::class)->reject($this->reviewer, $inbound, 'Duplicate of BILL/2026/12');

    expect($inbound->fresh()->status)->toBe(InboundTransmissionStatus::Rejected)
        ->and($inbound->fresh()->rejection_reason)->toBe('Duplicate of BILL/2026/12')
        ->and(Move::query()->where('move_type', MoveType::IN_INVOICE)->count())->toBe(0);
});

it('refuses to accept when the company has no purchase journal, rather than creating an unnumbered bill', function () {
    $invoice = makeSellerInvoice($this);

    $inbound = app(DocumentExchangeService::class)->receive(
        $this->peerOnBuyer,
        'no-journal',
        (new JsonV1InvoicePayloadFormatter)->format($invoice),
    );

    $this->purchaseJournal->delete();

    expect(fn () => app(DocumentExchangeService::class)->accept($this->reviewer, $inbound))
        ->toThrow(RuntimeException::class, 'purchase journal');

    expect($inbound->fresh()->status)->toBe(InboundTransmissionStatus::Received)
        ->and(Move::query()->where('move_type', MoveType::IN_INVOICE)->count())->toBe(0);
});
