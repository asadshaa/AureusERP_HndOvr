<?php

namespace Webkul\Accounting\Http\Controllers;

use Illuminate\Routing\Controller;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\OutboundTransmissionStatus;
use Webkul\Accounting\Enums\TransmissionChannel;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\OutboundTransmission;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Peers\JsonV1InvoicePayloadFormatter;

/**
 * The public claim page a non-ERP recipient lands on.
 *
 * Unauthenticated by design: the token in the URL is the only credential,
 * which is why every failure mode below renders the SAME neutral page.
 * Telling a caller whether a token was wrong, expired or cancelled would
 * turn this into an enumeration oracle.
 *
 * Serves two kinds of transmission -- an invoice (rendered, plus a
 * structured download) and a file (downloaded directly).
 */
class ClaimController extends Controller
{
    public function show(string $token)
    {
        $transmission = $this->resolve($token);

        if (! $transmission) {
            return response()->view('accounting::peers.claim-unavailable', [], 404);
        }

        // First successful open is the delivery signal for this channel.
        if ($transmission->status === OutboundTransmissionStatus::Queued) {
            $transmission->forceFill([
                'status'       => OutboundTransmissionStatus::Delivered,
                'delivered_at' => now(),
            ])->save();
        }

        if ($this->isFile($transmission)) {
            $document = Document::query()->find($transmission->transmittable_id);

            if (! $document) {
                return response()->view('accounting::peers.claim-unavailable', [], 404);
            }

            return response()->view('accounting::peers.claim-file', [
                'transmission' => $transmission,
                'document'     => $document,
                'version'      => $document->currentVersion,
            ]);
        }

        $invoice = Move::query()->find($transmission->transmittable_id);

        return response()->view('accounting::peers.claim', [
            'transmission' => $transmission,
            'invoice'      => $invoice,
            'payload'      => $invoice ? (new JsonV1InvoicePayloadFormatter)->format($invoice) : [],
        ]);
    }

    /**
     * The actual bytes, for a file transmission.
     */
    public function file(string $token, DocumentService $documents)
    {
        $transmission = $this->resolve($token);

        if (! $transmission || ! $this->isFile($transmission)) {
            abort(404);
        }

        $document = Document::query()->find($transmission->transmittable_id);

        if (! $document) {
            abort(404);
        }

        // The system-caller read: verifies existence and checksum exactly as
        // an authenticated download would, without inventing a user to
        // attribute it to.
        $result = $documents->readCurrentVersionForSync($document);

        return response()->streamDownload(
            fn () => print ($result['contents']),
            $result['version']->original_filename,
            ['Content-Type' => $result['version']->mime_type ?: 'application/octet-stream'],
        );
    }

    /**
     * Structured download, so the recipient's own accountant can import it.
     */
    public function payload(string $token)
    {
        $transmission = $this->resolve($token);

        if (! $transmission || $this->isFile($transmission)) {
            abort(404);
        }

        $invoice = Move::query()->find($transmission->transmittable_id);

        if (! $invoice) {
            abort(404);
        }

        $payload = (new JsonV1InvoicePayloadFormatter)->format($invoice);
        $name = 'invoice-'.preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $invoice->name).'.json';

        return response()->streamDownload(
            fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            $name,
            ['Content-Type' => 'application/json'],
        );
    }

    private function isFile(OutboundTransmission $transmission): bool
    {
        return $transmission->transmittable_type === Document::class;
    }

    /**
     * Lookup by hash. A plaintext token is never stored, so this is the only
     * way back from the URL to a record.
     */
    private function resolve(string $token): ?OutboundTransmission
    {
        $transmission = OutboundTransmission::query()
            ->where('claim_token_hash', hash('sha256', $token))
            ->where('channel', TransmissionChannel::External)
            ->first();

        if (! $transmission || ! $transmission->isClaimable()) {
            return null;
        }

        return $transmission;
    }
}
