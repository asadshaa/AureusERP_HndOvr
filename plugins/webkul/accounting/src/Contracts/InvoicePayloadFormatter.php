<?php

namespace Webkul\Accounting\Contracts;

use Webkul\Account\Models\Move;

/**
 * The seam that keeps UBL a later drop-in rather than a rewrite.
 *
 * Everything else in the exchange subsystem -- pairing, signing, retry,
 * the review queue -- is format-agnostic and talks only to this interface.
 * Adding UBL 2.1 means writing a second implementation and binding it; no
 * transport, trust or UI code changes.
 */
interface InvoicePayloadFormatter
{
    /**
     * Identifier embedded in the payload so a receiver can tell which
     * formatter produced it.
     */
    public function formatIdentifier(): string;

    /**
     * @return array<string, mixed>
     */
    public function format(Move $invoice): array;

    /**
     * Pull a normalised invoice out of a received payload.
     *
     * Returns a plain array rather than a Move: the receiving side must
     * never be handed something that looks ready to save, because nothing
     * from a peer may reach the ledger without the mapping rules in
     * DocumentExchangeService::accept() being applied first.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function parse(array $payload): array;
}
