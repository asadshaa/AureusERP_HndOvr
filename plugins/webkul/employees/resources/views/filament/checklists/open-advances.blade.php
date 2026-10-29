<div style="display: flex; flex-direction: column; gap: .75rem;">
    @if ($requests->isEmpty())
        <p style="margin: 0; font-size: .875rem; opacity: .75;">No pending or approved loan or salary advance requests.</p>
    @else
        <ul style="list-style: none; margin: 0; padding: 0;">
            @foreach ($requests as $request)
                <li style="display: flex; justify-content: space-between; gap: .75rem; padding: .5rem 0; border-top: 1px solid rgba(127, 127, 127, .2);">
                    <span>
                        <strong>{{ $request->requestType?->name ?? 'Request' }}</strong>
                        <span style="display: block; font-size: .75rem; opacity: .7;">{{ $request->reference ?? $request->title }} · {{ $request->status === 'approved' ? 'Approved' : 'Waiting for approval' }}</span>
                    </span>
                    <span style="font-variant-numeric: tabular-nums; white-space: nowrap;">{{ $request->amount !== null ? number_format((float) $request->amount, 2) : '—' }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($requestsUrl)
        <a href="{{ $requestsUrl }}" style="font-size: .875rem; font-weight: 600; color: var(--primary-600);">Open employee requests</a>
    @endif
</div>
