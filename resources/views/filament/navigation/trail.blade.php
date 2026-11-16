@php($trail = \App\Support\PageTrail::for($scopes ?? []))
@if ($trail)
    <div class="aureus-trail" x-data="{ fallback: @js($trail['back']), go() { if ((window.aureusStack || []).length > 1) { history.back(); } else if (window.Livewire && Livewire.navigate) { Livewire.navigate(this.fallback); } else { location.href = this.fallback; } } }">
        <a href="{{ $trail['back'] }}" class="aureus-back" x-on:click.prevent="go()" title="Go back to the previous page">
            <span aria-hidden="true">←</span> Back
        </a>
        @if ($trail['crumbs'] !== [])
            <nav class="aureus-crumbs" aria-label="You are here">
                @foreach ($trail['crumbs'] as $crumb)
                    @if (! $loop->first)<span class="aureus-sep" aria-hidden="true">›</span>@endif
                    @if ($crumb['url'] && ! $loop->last)
                        <a href="{{ $crumb['url'] }}" wire:navigate>{{ $crumb['label'] }}</a>
                    @else
                        <span @if ($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</span>
                    @endif
                @endforeach
            </nav>
        @endif
    </div>

    <style>
        .aureus-trail { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; margin-bottom: .75rem; font-size: .875rem; }
        .aureus-back { display: inline-flex; align-items: center; gap: .375rem; padding: .25rem .75rem; border-radius: 9999px; border: 1px solid rgba(127,127,127,.35); color: inherit; text-decoration: none; font-weight: 500; }
        .aureus-back:hover { border-color: var(--primary-500); color: var(--primary-600); }
        .dark .aureus-back:hover { color: var(--primary-400); }
        .aureus-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; opacity: .85; }
        .aureus-crumbs a { color: var(--primary-600); }
        .dark .aureus-crumbs a { color: var(--primary-400); }
        .aureus-crumbs a:hover { text-decoration: underline; }
        .aureus-sep { opacity: .5; }
    </style>

@endif
