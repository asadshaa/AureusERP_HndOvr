<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-cake" icon-color="primary">
        <x-slot name="heading">This week</x-slot>
        <x-slot name="description">Birthdays and work anniversaries.</x-slot>

        <ul class="ah-list ah-list-wrap">
            @foreach ($rows as $row)
                @php
                    $key = $row['kind'].':'.$row['employee_id'];
                    $canWish = $row['user_id'] !== null && $row['user_id'] !== $userId;
                @endphp
                <li wire:key="celebration-{{ $key }}">
                    <span class="ah-row ah-grow" style="flex-wrap: nowrap;">
                        <span @class(['ah-chip', $row['kind'] === 'birthday' ? 'ah-tone-warning' : 'ah-tone-success'])>
                            <x-filament::icon :icon="$row['kind'] === 'birthday' ? 'heroicon-o-cake' : 'heroicon-o-trophy'" />
                        </span>
                        <span class="ah-grow">
                            <span class="ah-strong ah-ellipsis" style="display: block; font-size: .875rem;">{{ $row['name'] }}</span>
                            <span class="ah-small" style="display: block;">{{ $row['label'] }}@if ($row['department']) · {{ $row['department'] }}@endif</span>
                        </span>
                    </span>
                    <span class="ah-row" style="flex-wrap: nowrap;">
                        @if ($row['is_today'])
                            <x-filament::badge color="success" size="sm">Today</x-filament::badge>
                        @else
                            <span class="ah-small">{{ $row['day_label'] }}</span>
                        @endif
                        @if ($canWish)
                            @if (in_array($key, $wished, true))
                                <x-filament::badge color="gray" size="sm" icon="heroicon-m-check">Sent</x-filament::badge>
                            @else
                                <x-filament::button size="xs" color="gray" icon="heroicon-m-heart" wire:click="sendWishes({{ $row['employee_id'] }}, '{{ $row['kind'] }}')">Send wishes</x-filament::button>
                            @endif
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
