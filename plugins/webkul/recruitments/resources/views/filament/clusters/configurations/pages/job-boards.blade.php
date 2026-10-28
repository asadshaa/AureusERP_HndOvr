@php
    $muted = 'font-size: .8rem; opacity: .7;';
    $badge = 'display: inline-block; padding: .1rem .5rem; border-radius: 9999px; font-size: .75rem; font-weight: 600;';
    $feedBoards = array_values(array_filter($boards, fn (array $board): bool => $board['automatic']));
    $manualBoards = array_values(array_filter($boards, fn (array $board): bool => ! $board['automatic']));
    $groups = [
        ['Automatic (XML feed)', 'Register the feed URL with the board once. Every job whose Job ads include that board is then listed by the board automatically while it is live on the company website, and taken off when it closes. Each board decides whether to accept the feed.', $feedBoards],
        ['Manual (Copy post)', 'Create the free employer account once. For each job, add the board on the Job ads tab, use Copy post (the apply link is tracked) and mark the ad as posted.', $manualBoards],
    ];
@endphp

<x-filament-panels::page>
    @if (! collect($boards)->contains(fn (array $board): bool => filled($board['feed_url'])))
        <x-filament::section>
            <p>Set up the <a href="{{ $websiteUrl }}" style="color: rgb(var(--primary-500, 59 130 246)); font-weight: 600;">Careers website</a> first: the feed URLs are addresses on the company website.</p>
        </x-filament::section>
    @elseif (! ($boards[0]['website_active'] ?? false))
        <x-filament::section>
            <p>The <a href="{{ $websiteUrl }}" style="color: rgb(var(--primary-500, 59 130 246)); font-weight: 600;">Careers website</a> connection is switched off: no job is on the website, so every feed is empty.</p>
        </x-filament::section>
    @endif

    @foreach ($groups as [$heading, $description, $items])
        <x-filament::section :heading="$heading" :description="$description">
            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(19rem, 1fr));">
                @foreach ($items as $board)
                    <div wire:key="job-board-{{ $board['key'] }}" style="border: 1px solid rgba(127,127,127,.25); border-radius: .75rem; padding: 1rem; display: flex; flex-direction: column; gap: .6rem;">
                        <div style="display: flex; align-items: center; gap: .5rem; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: .5rem;">
                                @if ($board['icon'])
                                    <x-filament::icon :icon="$board['icon']" style="width: 1.25rem; height: 1.25rem; opacity: .8;" />
                                @endif
                                <strong>{{ $board['label'] }}</strong>
                            </div>
                            <span style="{{ $badge }} {{ $board['is_registered'] ? 'background: rgba(34,197,94,.15); color: rgb(21,128,61);' : 'background: rgba(127,127,127,.15);' }}">
                                {{ $board['is_registered'] ? 'Registered'.($board['registered_at'] ? ' '.\Illuminate\Support\Carbon::parse($board['registered_at'])->format('d M Y') : '') : 'Not registered' }}
                            </span>
                        </div>

                        @if ($board['description'])
                            <div style="{{ $muted }}">{{ $board['description'] }}</div>
                        @endif

                        @if ($board['automatic'])
                            <div>
                                <div style="{{ $muted }}">Feed URL</div>
                                @if ($board['feed_url'])
                                    <div x-data="{ copied: false }" style="display: flex; gap: .5rem; align-items: center;">
                                        <code style="font-size: .8rem; word-break: break-all;">{{ $board['feed_url'] }}</code>
                                        <x-filament::button size="xs" color="gray" icon="heroicon-o-clipboard-document"
                                            x-on:click="navigator.clipboard.writeText(@js($board['feed_url'])); copied = true; setTimeout(() => copied = false, 1500)">
                                            <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                        </x-filament::button>
                                    </div>
                                @else
                                    <div style="{{ $muted }}">Available once the careers website is set up.</div>
                                @endif
                            </div>
                        @endif

                        <div style="display: flex; gap: 1.5rem;">
                            <div>
                                <div style="font-size: 1.25rem; font-weight: 700;">{{ $board['jobs_live'] }}</div>
                                <div style="{{ $muted }}">{{ $board['automatic'] ? 'jobs in the feed now' : 'jobs posted now' }}</div>
                            </div>
                            <div>
                                <div style="font-size: 1.25rem; font-weight: 700;">{{ $board['applications_this_month'] }}</div>
                                <div style="{{ $muted }}">applications this month</div>
                            </div>
                        </div>

                        @if ($board['how_to'] !== [])
                            <details>
                                <summary style="cursor: pointer; font-size: .85rem; font-weight: 600;">How to</summary>
                                <ol style="margin: .4rem 0 0 1.1rem; list-style: decimal; font-size: .85rem; display: grid; gap: .2rem;">
                                    @foreach ($board['how_to'] as $step)
                                        <li>{{ $step }}</li>
                                    @endforeach
                                </ol>
                            </details>
                        @endif

                        @if ($board['notes'])
                            <div style="{{ $muted }} white-space: pre-line;">{{ $board['notes'] }}</div>
                        @endif

                        <div style="display: flex; gap: .5rem; align-items: center; margin-top: auto;">
                            {{ ($this->editRegistrationAction)(['board' => $board['key']]) }}
                            @if ($board['board_url'])
                                <x-filament::link :href="$board['board_url']" target="_blank" rel="noopener noreferrer" size="sm" icon="heroicon-o-arrow-top-right-on-square">
                                    Open board
                                </x-filament::link>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
