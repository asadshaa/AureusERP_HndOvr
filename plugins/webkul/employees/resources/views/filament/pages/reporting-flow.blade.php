@php
    $muted = 'font-size: .875rem; opacity: .75;';
@endphp

<div style="display: flex; flex-direction: column; gap: 1.25rem;">
    @forelse ($groups as $group)
        <div>
            <div style="font-weight: 700; font-size: 1rem;">{{ $group['purpose_label'] }}</div>

            @foreach ($group['flows'] as $flow)
                <div style="margin-top: .5rem;">
                    <div style="font-weight: 600;">{{ $flow['label'] }}</div>
                    <ol style="list-style: none; margin: .25rem 0 0; padding: 0; line-height: 1.6;">
                        @forelse ($flow['steps'] as $index => $step)
                            <li>{{ $index + 1 }}. {{ $step['name'] }} &rarr; {{ $step['who'] }}</li>
                        @empty
                            <li style="{{ $muted }}">No steps set up.</li>
                        @endforelse
                    </ol>
                </div>
            @endforeach
        </div>
    @empty
        <p style="{{ $muted }}">No approval flows are set up for this company yet.</p>
    @endforelse
</div>
