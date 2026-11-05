<x-filament-widgets::widget>
    @php
        $pendingCount = $data['pending_count'] ?? 0;
        $items = $data['items'] ?? [];
        $groups = array_filter([
            'For you' => $today['mine'] ?? [],
            ($today['team_label'] ?? 'For your team') => $today['team'] ?? [],
        ]);
        $waiting = collect($groups)->flatten(1)->filter(fn (array $row): bool => ($row['count'] ?? 0) > 0)->sum('count') + $pendingCount;
        $nothing = $groups === [] && $pendingCount === 0;
    @endphp

    <div wire:poll.120s.visible>
        <x-filament::section icon="heroicon-o-sun" :icon-color="$waiting > 0 ? 'warning' : 'success'">
            <x-slot name="heading">Today</x-slot>

            <x-slot name="description">
                {{ $dateLabel }} ·
                @if ($nothing)
                    Nothing waiting for you today.
                @else
                    Start here: your work for today, one click each.
                @endif
            </x-slot>

            @if ($nothing)
                <div class="ah-empty">
                    <x-filament::icon icon="heroicon-o-check-circle" />
                    <span>Nothing waiting for you today. New requests and approvals will appear here.</span>
                </div>
            @else
                <div class="ah-stack">
                    @foreach ($groups as $groupLabel => $rows)
                        <div>
                            <div class="ah-label" style="margin-bottom: .5rem;">{{ $groupLabel }}</div>
                            <ul class="ah-list ah-list-wrap">
                                @foreach ($rows as $row)
                                    <li data-today="{{ $row['key'] }}">
                                        <a href="{{ $row['url'] }}" class="ah-row ah-grow" style="flex-wrap: nowrap; text-decoration: none;">
                                            <span @class(['ah-chip', 'ah-tone-'.$row['tone']])>
                                                <x-filament::icon :icon="$row['icon']" />
                                            </span>
                                            <span class="ah-grow">
                                                <span class="ah-strong" style="display: block; font-size: .875rem;">
                                                    @if ($row['count'] !== null)
                                                        <span class="ah-today-count">{{ $row['count'] }}</span>
                                                    @endif
                                                    {{ $row['label'] }}
                                                </span>
                                                @if ($row['hint'])
                                                    <span class="ah-small" style="display: block;">{{ $row['hint'] }}</span>
                                                @endif
                                            </span>
                                        </a>
                                        <x-filament::button tag="a" :href="$row['url']" size="sm" :color="$row['key'] === 'check_in' && $row['tone'] === 'warning' ? 'primary' : 'gray'" icon="heroicon-m-arrow-right" icon-position="after">
                                            {{ $row['action'] }}
                                        </x-filament::button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach

                    @if ($pendingCount > 0)
                        <div>
                            <div class="ah-label" style="margin-bottom: .5rem;">Pending actions</div>
                            <ul class="ah-list ah-list-wrap">
                                @foreach ($items as $item)
                                    @php
                                        $tone = match ($item['urgency']) {
                                            'danger'  => 'danger',
                                            'warning' => 'warning',
                                            default   => 'info',
                                        };
                                    @endphp
                                    <li>
                                        <span class="ah-row ah-grow" style="flex-wrap: nowrap; align-items: flex-start;">
                                            <span @class(['ah-chip', 'ah-tone-'.$tone])>
                                                <x-filament::icon :icon="$item['icon']" />
                                            </span>
                                            <span class="ah-grow">
                                                <a href="{{ $item['url'] }}" class="ah-strong" style="display: block; font-size: .875rem; text-decoration: none;">{{ $item['title'] }}</a>
                                                <span class="ah-small" style="display: block;">{{ $item['description'] }}</span>
                                            </span>
                                        </span>
                                        <x-filament::button tag="a" :href="$item['url']" size="sm" color="gray" icon="heroicon-m-arrow-right" icon-position="after">
                                            {{ rtrim($item['action_label'], ' →') }}
                                        </x-filament::button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
