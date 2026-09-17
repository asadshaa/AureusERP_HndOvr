<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice?->name }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f5f4; color: #1c1917; margin: 0; padding: 2rem 1rem; }
        .card { background: #fff; border-radius: 12px; padding: 2rem; max-width: 46rem; margin: 0 auto;
                box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); }
        h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
        .muted { color: #78716c; font-size: .9rem; }
        table { width: 100%; border-collapse: collapse; margin: 1.5rem 0; }
        th, td { text-align: left; padding: .6rem .5rem; border-bottom: 1px solid #e7e5e4; font-size: .92rem; }
        th { font-weight: 600; color: #57534e; }
        td.num, th.num { text-align: right; }
        .totals { margin-left: auto; width: 16rem; }
        .totals td { border: none; padding: .3rem .5rem; }
        .grand { font-weight: 700; border-top: 2px solid #1c1917 !important; }
        .actions { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 1.5rem; }
        a.btn { display: inline-block; padding: .6rem 1.1rem; border-radius: 8px; text-decoration: none;
                font-size: .9rem; font-weight: 600; }
        .primary { background: #1c1917; color: #fff; }
        .secondary { background: #e7e5e4; color: #1c1917; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Invoice {{ $payload['invoice']['number'] ?? '' }}</h1>
        <p class="muted">
            From {{ $payload['seller']['name'] ?? 'Unknown sender' }}
            @if (! empty($payload['seller']['strn'])) &middot; STRN {{ $payload['seller']['strn'] }} @endif
        </p>
        <p class="muted">
            Issued {{ $payload['invoice']['date'] ?? '—' }}
            @if (! empty($payload['invoice']['due_date'])) &middot; Due {{ $payload['invoice']['due_date'] }} @endif
        </p>

        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit price</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($payload['invoice']['lines'] ?? [] as $line)
                    <tr>
                        <td>{{ $line['description'] }}</td>
                        <td class="num">{{ number_format((float) $line['quantity'], 2) }}</td>
                        <td class="num">{{ number_format((float) $line['unit_price'], 2) }}</td>
                        <td class="num">{{ number_format((float) $line['subtotal'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No line detail provided.</td></tr>
                @endforelse
            </tbody>
        </table>

        <table class="totals">
            <tr><td>Subtotal</td><td class="num">{{ number_format((float) ($payload['invoice']['totals']['untaxed'] ?? 0), 2) }}</td></tr>
            <tr><td>Tax</td><td class="num">{{ number_format((float) ($payload['invoice']['totals']['tax'] ?? 0), 2) }}</td></tr>
            <tr class="grand">
                <td>Total ({{ $payload['invoice']['currency'] ?? '' }})</td>
                <td class="num">{{ number_format((float) ($payload['invoice']['totals']['total'] ?? 0), 2) }}</td>
            </tr>
        </table>

        <div class="actions">
            <a class="primary btn" href="{{ route('accounting.claim.payload', ['token' => request()->route('token')]) }}">
                Download structured invoice (JSON)
            </a>
        </div>

        <p class="muted" style="margin-top:1.5rem">
            This link expires {{ optional($transmission->expires_at)->toDayDateTimeString() }}.
        </p>
    </div>
</body>
</html>
