<?php

namespace Webkul\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Contracts\InvoicePayloadFormatter;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\OutboundTransmissionStatus;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAudit;
use Webkul\Accounting\Models\OutboundTransmission;
use Webkul\Accounting\Services\Peers\DocumentPayloadBuilder;
use Webkul\Accounting\Support\Peers\PeerSignature;

/**
 * Performs the signed POST to a peer.
 *
 * Backoff deliberately mirrors SyncDocumentToDriveJob, so the two outbound
 * integrations behave the same way under a flaky network.
 *
 * A 4xx is treated as terminal: a rejected signature or a revoked peer will
 * be rejected identically on every retry, so retrying only burns the queue
 * and delays the operator seeing a real problem. Only 5xx and transport
 * failures are retried.
 */
class SendTransmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $transmissionId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 300, 900, 3600];
    }

    public function handle(InvoicePayloadFormatter $formatter): void
    {
        $transmission = OutboundTransmission::query()->find($this->transmissionId);

        if (! $transmission || $transmission->status->isTerminal()) {
            return;
        }

        $peer = $transmission->peer;

        // Re-checked at send time, not just at queue time: a peer revoked
        // while this job sat in the queue must not still receive data.
        if (! $peer || ! $peer->isActive()) {
            $this->fail_($transmission, 'Peer is not active.');

            return;
        }

        // Two payload shapes travel this same pipe: an invoice (structured
        // accounting data) and a document (a file, bytes inline). Only the
        // encoding differs -- signing, retry and failure handling below are
        // identical, which is the point of keeping one job.
        if ($transmission->transmittable_type === Document::class) {
            $document = Document::query()->find($transmission->transmittable_id);

            if (! $document) {
                $this->fail_($transmission, 'The document no longer exists.');

                return;
            }

            try {
                $payload = app(DocumentPayloadBuilder::class)->build($document);
            } catch (\Throwable $e) {
                // Unreadable or oversized is permanent, not transient.
                $this->fail_($transmission, $e->getMessage());

                return;
            }
        } else {
            $invoice = Move::query()->find($transmission->transmittable_id);

            if (! $invoice) {
                $this->fail_($transmission, 'The invoice no longer exists.');

                return;
            }

            $payload = $formatter->format($invoice);
        }

        $body = json_encode([
            'reference' => $transmission->idempotency_key,
            'payload'   => $payload,
        ], JSON_UNESCAPED_SLASHES);

        $headers = PeerSignature::headers($body, $peer->signing_secret);

        $transmission->increment('attempts');

        try {
            $response = Http::withHeaders($headers + [
                'Authorization' => 'Bearer '.$peer->outbound_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])
                ->connectTimeout((int) config('accounting_peers.connect_timeout', 10))
                ->timeout((int) config('accounting_peers.request_timeout', 120))
                ->withBody($body, 'application/json')
                ->post(rtrim($peer->endpoint_url, '/').'/api/v1/peer/transmissions');
        } catch (\Throwable $e) {
            // Transport-level failure: let the queue retry with backoff.
            $transmission->forceFill(['last_error' => $e->getMessage()])->save();

            throw $e;
        }

        if ($response->successful()) {
            $transmission->forceFill([
                'status'       => OutboundTransmissionStatus::Delivered,
                'delivered_at' => now(),
                'last_error'   => null,
            ])->save();

            $peer->forceFill(['last_seen_at' => now()])->save();

            $this->audit($transmission, DocumentAuditAction::TransmissionDelivered, [
                'transmission_id' => $transmission->id,
                'peer_id'         => $peer->id,
            ]);

            return;
        }

        if ($response->clientError()) {
            $this->fail_($transmission, "Peer rejected the transmission ({$response->status()}): ".$response->body());

            return;
        }

        $transmission->forceFill([
            'last_error' => "Peer returned {$response->status()}.",
        ])->save();

        // Server-side failure: retryable.
        throw new \RuntimeException("Peer returned {$response->status()}.");
    }

    /**
     * Terminal failure path used for non-retryable conditions.
     */
    private function fail_(OutboundTransmission $transmission, string $error): void
    {
        $transmission->forceFill([
            'status'     => OutboundTransmissionStatus::Failed,
            'last_error' => $error,
        ])->save();

        $this->audit($transmission, DocumentAuditAction::TransmissionFailed, [
            'transmission_id' => $transmission->id,
            'error'           => $error,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(OutboundTransmission $transmission, DocumentAuditAction $action, array $metadata): void
    {
        DocumentAudit::create([
            'company_id'  => $transmission->company_id,
            'document_id' => null,
            'actor_id'    => $transmission->creator_id,
            'action'      => $action,
            'metadata'    => $metadata,
        ]);
    }
}
