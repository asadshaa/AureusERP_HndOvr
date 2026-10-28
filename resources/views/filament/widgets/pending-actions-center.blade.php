<x-filament-widgets::widget>
    @php
        $pendingCount = $data['pending_count'] ?? 0;
        $items = $data['items'] ?? [];
    @endphp

    <div wire:poll.120s.visible>
        <x-filament::section icon="heroicon-o-bell-alert" :icon-color="$pendingCount > 0 ? 'warning' : 'success'">
            <x-slot name="heading">Pending actions</x-slot>

            <x-slot name="description">
                @if ($pendingCount > 0)
                    {{ $pendingCount }} {{ \Illuminate\Support\Str::plural('thing', $pendingCount) }} need{{ $pendingCount === 1 ? 's' : '' }} you today.
                @else
                    You are all caught up. Nothing is waiting for you right now.
                @endif
            </x-slot>

            <x-slot name="afterHeader">
                @if ($pendingCount > 0)
                    <x-filament::badge color="warning" icon="heroicon-m-clock">{{ $pendingCount }} pending</x-filament::badge>
                @else
                    <x-filament::badge color="success" icon="heroicon-m-check-circle">All clear</x-filament::badge>
                @endif
            </x-slot>

            @if ($pendingCount > 0)
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
            @else
                <div class="ah-empty">
                    <x-filament::icon icon="heroicon-o-check-circle" />
                    <span>No approvals or reviews are waiting for you. New requests from your team or company will appear here.</span>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
