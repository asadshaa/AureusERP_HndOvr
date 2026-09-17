<?php

namespace Webkul\Accounting\Services\Peers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Contracts\InvoicePayloadFormatter;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\InboundTransmissionStatus;
use Webkul\Accounting\Enums\OutboundTransmissionStatus;
use Webkul\Accounting\Enums\TransmissionChannel;
use Webkul\Accounting\Jobs\SendTransmissionJob;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Accounting\Models\DocumentAudit;
use Webkul\Accounting\Models\InboundTransmission;
use Webkul\Accounting\Models\OutboundTransmission;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Currency;

/**
 * Sending to, and receiving from, another AureusERP instance.
 *
 * The governing rule on the inbound side is that a peer supplies DATA, never
 * DECISIONS: it cannot choose a GL account, invent a partner, or assert a
 * total we have not recomputed ourselves.
 */
class DocumentExchangeService
{
    public function __construct(
        private readonly InvoicePayloadFormatter $formatter,
        private readonly DocumentPayloadBuilder $documentPayloads,
        private readonly DocumentService $documents,
        private readonly TransmissionMailer $mailer,
    ) {}

    // ---------------------------------------------------------------- send

    /**
     * Queue an invoice for a paired peer.
     *
     * The row is committed BEFORE the job is dispatched, so a transport
     * failure can never lose the intent -- the same ordering the Drive
     * integration uses.
     */
    public function sendInvoiceToPeer(User $actor, Move $invoice, Peer $peer, ?string $ipAddress = null): OutboundTransmission
    {
        $this->assertEnabled();
        $this->assertPermission($actor, AccountingPermissions::SendTransmissions);
        $this->assertSameCompany($actor, $invoice->company_id);

        if (! $peer->isActive()) {
            throw new RuntimeException("Peer '{$peer->name}' is not active.");
        }

        $payload = $this->formatter->format($invoice);

        $transmission = DB::transaction(function () use ($actor, $invoice, $peer, $payload, $ipAddress) {
            $transmission = OutboundTransmission::create([
                'company_id'         => $invoice->company_id,
                'peer_id'            => $peer->id,
                'creator_id'         => $actor->id,
                'channel'            => TransmissionChannel::Peer,
                'transmittable_type' => Move::class,
                'transmittable_id'   => $invoice->id,
                'status'             => OutboundTransmissionStatus::Queued,
                'payload_type'       => $this->formatter->formatIdentifier(),
                'payload_sha256'     => hash('sha256', json_encode($payload)),
                'idempotency_key'    => (string) Str::uuid(),
            ]);

            $this->audit($transmission->company_id, DocumentAuditAction::TransmissionSent, $actor, [
                'transmission_id' => $transmission->id,
                'peer_id'         => $peer->id,
                'invoice'         => $invoice->name,
            ], $ipAddress);

            return $transmission;
        });

        SendTransmissionJob::dispatch($transmission->id);

        return $transmission;
    }

    /**
     * Queue an invoice for someone with no AureusERP: they get an emailed PDF
     * plus an expiring claim link. Only the token hash is stored, so the
     * plaintext returned here is the only copy that will ever exist.
     *
     * @return array{transmission: OutboundTransmission, claim_token: string}
     */
    public function sendInvoiceToEmail(User $actor, Move $invoice, string $email, ?string $ipAddress = null): array
    {
        $this->assertEnabled();
        $this->assertPermission($actor, AccountingPermissions::SendTransmissions);
        $this->assertSameCompany($actor, $invoice->company_id);

        $payload = $this->formatter->format($invoice);
        $token = Str::random(64);

        $transmission = DB::transaction(function () use ($actor, $invoice, $email, $payload, $token, $ipAddress) {
            $transmission = OutboundTransmission::create([
                'company_id'         => $invoice->company_id,
                'peer_id'            => null,
                'creator_id'         => $actor->id,
                'channel'            => TransmissionChannel::External,
                'recipient_email'    => $email,
                'transmittable_type' => Move::class,
                'transmittable_id'   => $invoice->id,
                'status'             => OutboundTransmissionStatus::Queued,
                'payload_type'       => $this->formatter->formatIdentifier(),
                'payload_sha256'     => hash('sha256', json_encode($payload)),
                'idempotency_key'    => (string) Str::uuid(),
                'claim_token_hash'   => hash('sha256', $token),
                'expires_at'         => now()->addDays((int) config('accounting_peers.claim_link_ttl_days', 30)),
            ]);

            $this->audit($transmission->company_id, DocumentAuditAction::TransmissionSent, $actor, [
                'transmission_id' => $transmission->id,
                'recipient'       => $email,
                'invoice'         => $invoice->name,
            ], $ipAddress);

            return $transmission;
        });

        // After the commit, never inside it: a dead SMTP server must not roll
        // back a link that has already been created and is already usable.
        $this->mailer->sendInvoiceLink(
            $transmission,
            $invoice,
            route('accounting.claim.show', ['token' => $token]),
        );

        return ['transmission' => $transmission, 'claim_token' => $token];
    }

    /**
     * Queue a supporting document (a file) for a paired peer.
     *
     * Same ordering discipline as the invoice path: the row commits before
     * the job is dispatched, so the intent survives any transport failure.
     */
    public function sendDocumentToPeer(User $actor, Document $document, Peer $peer, ?string $ipAddress = null): OutboundTransmission
    {
        $this->assertEnabled();
        $this->assertPermission($actor, AccountingPermissions::SendTransmissions);
        $this->assertSameCompany($actor, $document->company_id);

        if (! $peer->isActive()) {
            throw new RuntimeException("Peer '{$peer->name}' is not active.");
        }

        // Built here rather than in the job so an oversized or unreadable
        // file fails in front of the user, not silently on a queue worker.
        $payload = $this->documentPayloads->build($document);

        $transmission = DB::transaction(function () use ($actor, $document, $peer, $payload, $ipAddress) {
            $transmission = OutboundTransmission::create([
                'company_id'         => $document->company_id,
                'peer_id'            => $peer->id,
                'creator_id'         => $actor->id,
                'channel'            => TransmissionChannel::Peer,
                'transmittable_type' => Document::class,
                'transmittable_id'   => $document->id,
                'status'             => OutboundTransmissionStatus::Queued,
                'payload_type'       => DocumentPayloadBuilder::FORMAT,
                'payload_sha256'     => hash('sha256', json_encode($payload)),
                'idempotency_key'    => (string) Str::uuid(),
            ]);

            $this->audit($transmission->company_id, DocumentAuditAction::TransmissionSent, $actor, [
                'transmission_id' => $transmission->id,
                'peer_id'         => $peer->id,
                'document_id'     => $document->id,
                'document'        => $document->title,
            ], $ipAddress, $document->id);

            return $transmission;
        });

        SendTransmissionJob::dispatch($transmission->id);

        return $transmission;
    }

    /**
     * Send a file to someone with no AureusERP: an expiring claim link.
     *
     * @return array{transmission: OutboundTransmission, claim_token: string}
     */
    public function sendDocumentToEmail(User $actor, Document $document, string $email, ?string $ipAddress = null): array
    {
        $this->assertEnabled();
        $this->assertPermission($actor, AccountingPermissions::SendTransmissions);
        $this->assertSameCompany($actor, $document->company_id);

        $payload = $this->documentPayloads->build($document);
        $token = Str::random(64);

        $transmission = DB::transaction(function () use ($actor, $document, $email, $payload, $token, $ipAddress) {
            $transmission = OutboundTransmission::create([
                'company_id'         => $document->company_id,
                'peer_id'            => null,
                'creator_id'         => $actor->id,
                'channel'            => TransmissionChannel::External,
                'recipient_email'    => $email,
                'transmittable_type' => Document::class,
                'transmittable_id'   => $document->id,
                'status'             => OutboundTransmissionStatus::Queued,
                'payload_type'       => DocumentPayloadBuilder::FORMAT,
                'payload_sha256'     => hash('sha256', json_encode($payload)),
                'idempotency_key'    => (string) Str::uuid(),
                'claim_token_hash'   => hash('sha256', $token),
                'expires_at'         => now()->addDays((int) config('accounting_peers.claim_link_ttl_days', 30)),
            ]);

            $this->audit($transmission->company_id, DocumentAuditAction::TransmissionSent, $actor, [
                'transmission_id' => $transmission->id,
                'recipient'       => $email,
                'document'        => $document->title,
            ], $ipAddress, $document->id);

            return $transmission;
        });

        $this->mailer->sendDocumentLink(
            $transmission,
            $document,
            route('accounting.claim.show', ['token' => $token]),
        );

        return ['transmission' => $transmission, 'claim_token' => $token];
    }

    // ------------------------------------------------------------- receive

    /**
     * Record what a verified peer sent us.
     *
     * Redelivery is success, not error: if this peer already sent us this
     * reference we return the existing row untouched, which is what makes
     * the sender's retry-after-a-lost-response safe rather than duplicating.
     *
     * @param  array<string, mixed>  $payload
     */
    public function receive(Peer $peer, string $remoteReference, array $payload, ?string $ipAddress = null): InboundTransmission
    {
        $existing = InboundTransmission::query()
            ->where('peer_id', $peer->id)
            ->where('remote_reference', $remoteReference)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($peer, $remoteReference, $payload, $ipAddress) {
            $format = (string) ($payload['format'] ?? 'unknown');

            // A file transmission carries its bytes inline. They are stored
            // immediately (so the transfer is complete and verifiable on
            // arrival) but the document stays UNATTACHED to any record until
            // a reviewer accepts -- receiving is not accepting.
            $documentId = null;
            $storedPayload = $payload;

            if ($format === DocumentPayloadBuilder::FORMAT) {
                $extracted = $this->documentPayloads->extract($payload);
                $tempPath = $extracted['file']->getRealPath();

                try {
                    $document = $this->documents->uploadFromPeer(
                        companyId: $peer->company_id,
                        documentType: DocumentType::tryFrom((string) $extracted['document_type']) ?? DocumentType::Other,
                        title: $extracted['title'],
                        description: $extracted['description'],
                        file: $extracted['file'],
                        ipAddress: $ipAddress,
                    );
                } finally {
                    // extract() writes the decoded bytes to a temp file so
                    // DocumentService can consume it as an ordinary upload.
                    // Nothing else owns that file, so nothing else deletes
                    // it -- confirmed missing entirely, meaning every
                    // received document left a full plaintext copy in
                    // %TEMP% indefinitely, on success AND on a validation
                    // failure (MIME rejected, size ceiling, storage error).
                    // finally, not "after success", is what closes both.
                    if ($tempPath && is_file($tempPath)) {
                        @unlink($tempPath);
                    }
                }

                $documentId = $document->id;

                // The bytes now live in document storage; a second copy in
                // this JSON column would double storage for no gain.
                unset($storedPayload['document']['contents_b64']);
            }

            $inbound = InboundTransmission::create([
                'company_id'       => $peer->company_id,
                'peer_id'          => $peer->id,
                'remote_reference' => $remoteReference,
                'payload_type'     => $format,
                'payload'          => $storedPayload,
                'document_id'      => $documentId,
                'status'           => InboundTransmissionStatus::Received,
            ]);

            $peer->forceFill(['last_seen_at' => now()])->save();

            $this->audit($peer->company_id, DocumentAuditAction::TransmissionReceived, null, [
                'inbound_id' => $inbound->id,
                'peer_id'    => $peer->id,
                'reference'  => $remoteReference,
            ], $ipAddress, $documentId);

            return $inbound;
        });
    }

    /**
     * Turn a reviewed inbound invoice into a DRAFT vendor bill.
     *
     * Every mapping below deliberately refuses to take direction from the
     * payload: the partner comes from the peer link, the currency must
     * already exist here, and totals are recomputed rather than trusted.
     */
    public function accept(User $actor, InboundTransmission $inbound, ?string $ipAddress = null): ?Move
    {
        $this->assertPermission($actor, AccountingPermissions::ReviewInboundTransmissions);
        $this->assertSameCompany($actor, $inbound->company_id);

        if ($inbound->isReviewed()) {
            throw new RuntimeException('This transmission has already been reviewed.');
        }

        // A file transmission has no invoice to book. Its document was
        // already stored on arrival, so accepting only records the human
        // decision -- and returns null, because no bill was created.
        if ($inbound->payload_type === DocumentPayloadBuilder::FORMAT) {
            $inbound->forceFill([
                'status'      => InboundTransmissionStatus::Accepted,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit($inbound->company_id, DocumentAuditAction::TransmissionAccepted, $actor, [
                'inbound_id'  => $inbound->id,
                'document_id' => $inbound->document_id,
            ], $ipAddress, $inbound->document_id);

            return null;
        }

        $peer = $inbound->peer;

        if (! $peer->partner_id) {
            throw new RuntimeException("Link peer '{$peer->name}' to a vendor before accepting its invoices.");
        }

        $parsed = $this->formatter->parse($inbound->payload);

        $currency = Currency::query()
            ->where('code', $parsed['currency'])
            ->orWhere('name', $parsed['currency'])
            ->first();

        if (! $currency) {
            throw new RuntimeException("Currency '{$parsed['currency']}' does not exist on this instance.");
        }

        // The GL account is resolved HERE, locally, and never read from the
        // payload -- a peer must not be able to steer which account its
        // invoice books to. Failing loudly beats silently booking to a
        // guessed account.
        $expenseAccountId = $this->resolveExpenseAccountId($inbound->company_id);

        if (! $expenseAccountId) {
            throw new RuntimeException(
                'No expense account is configured on this company, so this invoice cannot be booked.'
            );
        }

        // Resolved exactly as the native Create Bill page does (see
        // CreateBill::mount()). A bill with no journal is a state that flow
        // never produces: Move derives its number from the journal, so the
        // bill would sit silently unnumbered. Found on the first real
        // two-instance accept. Refuse rather than create a half-formed bill.
        $journalId = Journal::query()
            ->where('type', JournalType::PURCHASE)
            ->where('company_id', $inbound->company_id)
            ->value('id');

        if (! $journalId) {
            throw new RuntimeException(
                'No purchase journal is configured on this company, so this invoice cannot be booked.'
            );
        }

        return DB::transaction(function () use ($actor, $inbound, $peer, $parsed, $currency, $expenseAccountId, $journalId, $ipAddress) {
            $move = Move::create([
                'company_id'       => $inbound->company_id,
                'journal_id'       => $journalId,
                'partner_id'       => $peer->partner_id,
                'currency_id'      => $currency->id,
                'move_type'        => MoveType::IN_INVOICE,
                'state'            => MoveState::DRAFT,
                'invoice_date'     => $parsed['date'],
                'invoice_date_due' => $parsed['due_date'],
                // The sender's own number is a reference here, never our name:
                // our numbering sequence stays ours.
                'reference'        => $parsed['number'],
                'narration'        => $parsed['narration'],
                'creator_id'       => $actor->id,
            ]);

            foreach ($parsed['lines'] as $line) {
                MoveLine::create([
                    'move_id'        => $move->id,
                    'company_id'     => $inbound->company_id,
                    'currency_id'    => $currency->id,
                    'partner_id'     => $peer->partner_id,
                    'account_id'     => $expenseAccountId,
                    'name'           => $line['description'],
                    'quantity'       => $line['quantity'],
                    'price_unit'     => $line['unit_price'],
                    'price_subtotal' => $line['subtotal'],
                ]);
            }

            if ($inbound->document_id) {
                DocumentAttachment::create([
                    'company_id'      => $inbound->company_id,
                    'document_id'     => $inbound->document_id,
                    'attachable_type' => Move::class,
                    'attachable_id'   => $move->id,
                    'creator_id'      => $actor->id,
                ]);
            }

            $inbound->forceFill([
                'status'          => InboundTransmissionStatus::Accepted,
                'reviewed_by'     => $actor->id,
                'reviewed_at'     => now(),
                'created_move_id' => $move->id,
            ])->save();

            $this->audit($inbound->company_id, DocumentAuditAction::TransmissionAccepted, $actor, [
                'inbound_id'    => $inbound->id,
                'move_id'       => $move->id,
                'total_claimed' => $parsed['claimed_total'],
                'total_local'   => $parsed['recomputed_untaxed'],
            ], $ipAddress);

            return $move;
        });
    }

    public function reject(User $actor, InboundTransmission $inbound, string $reason, ?string $ipAddress = null): InboundTransmission
    {
        $this->assertPermission($actor, AccountingPermissions::ReviewInboundTransmissions);
        $this->assertSameCompany($actor, $inbound->company_id);

        if ($inbound->isReviewed()) {
            throw new RuntimeException('This transmission has already been reviewed.');
        }

        $inbound->forceFill([
            'status'           => InboundTransmissionStatus::Rejected,
            'reviewed_by'      => $actor->id,
            'reviewed_at'      => now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->audit($inbound->company_id, DocumentAuditAction::TransmissionRejected, $actor, [
            'inbound_id' => $inbound->id,
            'reason'     => $reason,
        ], $ipAddress);

        return $inbound;
    }

    /**
     * Local expense account for inbound bills.
     *
     * accounts_accounts has no company_id column -- accounts are scoped to
     * companies through a pivot instead -- so this prefers an account this
     * company is actually linked to, and falls back to an unlinked (global)
     * one. postable() excludes group headers, and deprecated accounts are
     * excluded because neither may be posted to.
     */
    private function resolveExpenseAccountId(int $companyId): ?int
    {
        return Account::query()
            ->postable()
            ->whereIn('account_type', [
                AccountType::EXPENSE,
                AccountType::EXPENSE_DIRECT_COST,
            ])
            ->where('deprecated', false)
            ->where(fn ($query) => $query
                ->whereHas('companies', fn ($c) => $c->where('companies.id', $companyId))
                ->orWhereDoesntHave('companies'))
            ->orderBy('id')
            ->value('id');
    }

    // -------------------------------------------------------------- guards

    private function assertEnabled(): void
    {
        if (! config('accounting_peers.enabled', true)) {
            throw new RuntimeException('Peer exchange is disabled on this instance.');
        }
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new RuntimeException('You do not have permission to do that.');
        }
    }

    /**
     * Company isolation is derived from the actor, never from request input.
     */
    private function assertSameCompany(User $actor, int $companyId): void
    {
        if ((int) $actor->default_company_id !== (int) $companyId) {
            throw new RuntimeException('That record belongs to another company.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(int $companyId, DocumentAuditAction $action, ?User $actor, array $metadata, ?string $ipAddress, ?int $documentId = null): void
    {
        DocumentAudit::create([
            'company_id'  => $companyId,
            // Set for file transfers, so the send shows up in that
            // document's own history tab rather than only in a peer log.
            'document_id' => $documentId,
            'actor_id'    => $actor?->id,
            'action'      => $action,
            'metadata'    => $metadata,
            'ip_address'  => $ipAddress,
        ]);
    }
}
