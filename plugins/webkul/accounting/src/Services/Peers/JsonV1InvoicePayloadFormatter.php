<?php

namespace Webkul\Accounting\Services\Peers;

use Webkul\Account\Models\Move;
use Webkul\Accounting\Contracts\InvoicePayloadFormatter;

/**
 * AureusERP-native JSON, version 1.
 *
 * Carries the Pakistani tax identity fields this codebase already models
 * (STRN, sales-tax-registered) because they are what a local counterparty
 * actually needs on an invoice. A UBL formatter can map the same shape onto
 * cac:PartyTaxScheme later without either side changing.
 */
class JsonV1InvoicePayloadFormatter implements InvoicePayloadFormatter
{
    public function formatIdentifier(): string
    {
        return 'aureus.invoice.v1';
    }

    public function format(Move $invoice): array
    {
        $invoice->loadMissing(['partner', 'currency', 'company', 'invoiceLines']);

        $company = $invoice->company;
        $partner = $invoice->partner;

        return [
            'format'     => $this->formatIdentifier(),
            'issued_at'  => now()->toIso8601String(),
            'seller'     => [
                'name'                     => $company?->name,
                'strn'                     => $company?->strn,
                'is_sales_tax_registered'  => (bool) ($company?->is_sales_tax_registered ?? false),
                'email'                    => $company?->email,
            ],
            'buyer' => [
                'name'                    => $partner?->name,
                'strn'                    => $partner?->strn,
                'is_sales_tax_registered' => (bool) ($partner?->is_sales_tax_registered ?? false),
                'email'                   => $partner?->email,
            ],
            'invoice' => [
                'number'    => $invoice->name,
                'reference' => $invoice->reference,
                'date'      => optional($invoice->invoice_date)->toDateString(),
                'due_date'  => optional($invoice->invoice_date_due)->toDateString(),
                'currency'  => $invoice->currency?->code ?? $invoice->currency?->name,
                'narration' => $invoice->narration,
                'lines'     => $invoice->invoiceLines->map(fn ($line) => [
                    'description' => $line->name,
                    'quantity'    => (float) $line->quantity,
                    'unit_price'  => (float) $line->price_unit,
                    'subtotal'    => (float) $line->price_subtotal,
                ])->values()->all(),
                'totals' => [
                    'untaxed' => (float) $invoice->amount_untaxed,
                    'tax'     => (float) $invoice->amount_tax,
                    'total'   => (float) $invoice->amount_total,
                ],
            ],
        ];
    }

    public function parse(array $payload): array
    {
        $invoice = $payload['invoice'] ?? [];

        $lines = collect($invoice['lines'] ?? [])
            ->map(fn ($line) => [
                'description' => (string) ($line['description'] ?? ''),
                'quantity'    => (float) ($line['quantity'] ?? 0),
                'unit_price'  => (float) ($line['unit_price'] ?? 0),
                'subtotal'    => (float) ($line['subtotal'] ?? 0),
            ])
            ->values()
            ->all();

        // Recomputed locally and returned alongside what the sender asserted,
        // so accept() can surface a mismatch instead of trusting either one.
        $recomputed = array_sum(array_map(
            fn ($line) => $line['subtotal'] !== 0.0 ? $line['subtotal'] : $line['quantity'] * $line['unit_price'],
            $lines,
        ));

        return [
            'format'             => (string) ($payload['format'] ?? 'unknown'),
            'seller_name'        => $payload['seller']['name'] ?? null,
            'seller_strn'        => $payload['seller']['strn'] ?? null,
            'buyer_name'         => $payload['buyer']['name'] ?? null,
            'number'             => $invoice['number'] ?? null,
            'reference'          => $invoice['reference'] ?? null,
            'date'               => $invoice['date'] ?? null,
            'due_date'           => $invoice['due_date'] ?? null,
            'currency'           => $invoice['currency'] ?? null,
            'narration'          => $invoice['narration'] ?? null,
            'lines'              => $lines,
            'claimed_untaxed'    => (float) ($invoice['totals']['untaxed'] ?? 0),
            'claimed_tax'        => (float) ($invoice['totals']['tax'] ?? 0),
            'claimed_total'      => (float) ($invoice['totals']['total'] ?? 0),
            'recomputed_untaxed' => round($recomputed, 2),
        ];
    }
}
