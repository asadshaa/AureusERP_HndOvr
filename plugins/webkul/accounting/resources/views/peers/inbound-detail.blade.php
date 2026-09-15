@php
    $payload = $record->payload ?? [];
    $isFile = ($record->payload_type ?? '') === \Webkul\Accounting\Services\Peers\DocumentPayloadBuilder::FORMAT;
    $file = $payload['document'] ?? [];
    $invoice = $payload['invoice'] ?? [];
    $lines = $invoice['lines'] ?? [];

    // Recomputed here rather than trusted: the sender's total is shown
    // beside ours so a discrepancy is visible before anyone accepts.
    $recomputed = collect($lines)->sum(fn ($l) => (float) ($l['subtotal'] ?? 0));
    $claimed = (float) ($invoice['totals']['untaxed'] ?? 0);
    $mismatch = abs($recomputed - $claimed) > 0.01;
@endphp

<div class="space-y-4 text-sm">
@if ($isFile)
    <div class="grid grid-cols-2 gap-3">
        <div><span class="font-semibold">From:</span> {{ $record->peer->name }}</div>
        <div><span class="font-semibold">Received:</span> {{ $record->created_at?->toDayDateTimeString() }}</div>
        <div><span class="font-semibold">File:</span> {{ $file['filename'] ?? '—' }}</div>
        <div><span class="font-semibold">Type:</span> {{ $file['mime_type'] ?? '—' }}</div>
        <div><span class="font-semibold">Size:</span>
            {{ isset($file['size_bytes']) ? number_format($file['size_bytes'] / 1024, 1).' KB' : '—' }}</div>
        <div><span class="font-semibold">Title:</span> {{ $file['title'] ?? '—' }}</div>
    </div>

    @if ($record->document_id)
        <div class="rounded-md bg-success-50 p-3 text-success-700 dark:bg-success-950 dark:text-success-300">
            Stored and checksum-verified on arrival. Use <strong>Download file</strong> to retrieve it.
        </div>
    @else
        <div class="rounded-md bg-danger-50 p-3 text-danger-700 dark:bg-danger-950 dark:text-danger-300">
            No stored file is linked to this transmission.
        </div>
    @endif

    @if ($record->rejection_reason)
        <div><span class="font-semibold">Rejection reason:</span> {{ $record->rejection_reason }}</div>
    @endif
@else
    <div class="grid grid-cols-2 gap-3">
        <div><span class="font-semibold">From:</span> {{ $payload['seller']['name'] ?? '—' }}</div>
        <div><span class="font-semibold">Their STRN:</span> {{ $payload['seller']['strn'] ?? '—' }}</div>
        <div><span class="font-semibold">Invoice no:</span> {{ $invoice['number'] ?? '—' }}</div>
        <div><span class="font-semibold">Date:</span> {{ $invoice['date'] ?? '—' }}</div>
        <div><span class="font-semibold">Currency:</span> {{ $invoice['currency'] ?? '—' }}</div>
        <div><span class="font-semibold">Received:</span> {{ $record->created_at?->toDayDateTimeString() }}</div>
    </div>

    @if ($mismatch)
        <div class="rounded-md bg-danger-50 p-3 text-danger-700 dark:bg-danger-950 dark:text-danger-300">
            <strong>Total mismatch.</strong>
            They state {{ number_format($claimed, 2) }} but the line items add up to
            {{ number_format($recomputed, 2) }}. Check before accepting.
        </div>
    @endif

    @unless ($record->peer->partner_id)
        <div class="rounded-md bg-warning-50 p-3 text-warning-700 dark:bg-warning-950 dark:text-warning-300">
            This peer is not linked to a vendor yet, so accepting is blocked.
            Link one under Configuration &rsaquo; Peer Instances.
        </div>
    @endunless

    <table class="w-full">
        <thead>
            <tr class="border-b text-left">
                <th class="py-1">Description</th>
                <th class="py-1 text-right">Qty</th>
                <th class="py-1 text-right">Unit</th>
                <th class="py-1 text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr class="border-b">
                    <td class="py-1">{{ $line['description'] ?? '' }}</td>
                    <td class="py-1 text-right">{{ number_format((float) ($line['quantity'] ?? 0), 2) }}</td>
                    <td class="py-1 text-right">{{ number_format((float) ($line['unit_price'] ?? 0), 2) }}</td>
                    <td class="py-1 text-right">{{ number_format((float) ($line['subtotal'] ?? 0), 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-2 text-gray-500">No line detail provided.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($record->rejection_reason)
        <div><span class="font-semibold">Rejection reason:</span> {{ $record->rejection_reason }}</div>
    @endif
@endif
</div>
