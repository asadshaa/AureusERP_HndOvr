@php
    $groups = $this->getGroups();
    $muted = 'font-size: .875rem; opacity: .8;';
    $label = 'font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .7; margin-top: .75rem;';
@endphp

<x-filament-panels::page>
    <x-filament::section heading="Contents" icon="heroicon-o-list-bullet">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr)); gap: 1rem;">
            @foreach ($groups as $group)
                <div>
                    <div style="font-weight: 600; margin-bottom: .25rem;">{{ $group['title'] }}</div>
                    <ul style="list-style: none; margin: 0; padding: 0; font-size: .875rem; line-height: 1.75;">
                        @foreach ($group['topics'] as $topic)
                            <li>
                                <x-filament::link href="#{{ $topic['id'] }}" size="sm">{{ $topic['title'] }}</x-filament::link>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    @foreach ($groups as $group)
        <h2 style="font-size: 1.125rem; font-weight: 700; margin-top: .5rem;">{{ $group['title'] }}</h2>

        @foreach ($group['topics'] as $topic)
            <div id="{{ $topic['id'] }}" style="scroll-margin-top: 5rem;">
                <x-filament::section :heading="$topic['title']" :description="$topic['purpose']">
                    <div style="{{ $label }} margin-top: 0;">How to</div>
                    <ol style="list-style: decimal; padding-left: 1.25rem; margin: .25rem 0 0; line-height: 1.6;">
                        @foreach ($topic['steps'] as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>

                    @if (filled($topic['approvals'] ?? null))
                        <div style="{{ $label }}">Who approves</div>
                        <p style="margin: .25rem 0 0;">{{ $topic['approvals'] }}</p>
                    @endif

                    @if (filled($topic['note'] ?? null))
                        <div style="{{ $label }}">Good to know</div>
                        <p style="margin: .25rem 0 0; {{ $muted }}">{{ $topic['note'] }}</p>
                    @endif
                </x-filament::section>
            </div>
        @endforeach
    @endforeach
</x-filament-panels::page>
