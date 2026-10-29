<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-megaphone" icon-color="primary">
        <x-slot name="heading">Announcements</x-slot>
        <x-slot name="description">News from HR for you.</x-slot>

        @if ($allUrl)
            <x-slot name="afterHeader">
                <x-filament::button tag="a" :href="$allUrl" size="sm" color="gray" icon="heroicon-m-arrow-right" icon-position="after">View all</x-filament::button>
            </x-slot>
        @endif

        @if ($announcements->isEmpty())
            <div class="ah-empty">
                <x-filament::icon icon="heroicon-o-check-circle" />
                <span>You are all caught up.</span>
            </div>
        @else
            <ul class="ah-list ah-list-wrap">
                @foreach ($announcements as $announcement)
                    @php
                        $done = $announcement->requires_acknowledgement ? filled($announcement->my_acknowledged_at) : filled($announcement->my_read_at);
                        $date = ($announcement->publish_at ?? $announcement->created_at)?->timezone(\Webkul\Employee\Support\ChecklistKinds::timezone());
                    @endphp
                    <li wire:key="announcement-{{ $announcement->getKey() }}">
                        <span class="ah-row ah-grow" style="flex-wrap: nowrap; align-items: flex-start;">
                            <span @class(['ah-chip', $announcement->is_pinned ? 'ah-tone-warning' : 'ah-tone-primary'])>
                                <x-filament::icon :icon="$announcement->is_pinned ? 'heroicon-s-bookmark' : 'heroicon-o-megaphone'" />
                            </span>
                            <span class="ah-grow">
                                @if ($allUrl)
                                    <a href="{{ $allUrl }}?announcement={{ $announcement->getKey() }}" class="ah-strong" style="display: block; font-size: .875rem; text-decoration: none;">{{ $announcement->title }}</a>
                                @else
                                    <span class="ah-strong" style="display: block; font-size: .875rem;">{{ $announcement->title }}</span>
                                @endif
                                <span class="ah-small" style="display: block;">
                                    {{ $date?->format('D j M') }}@if ($announcement->is_pinned) · Pinned @endif @if ($announcement->requires_acknowledgement) · Please acknowledge @endif
                                </span>
                                @if (filled($excerpt = $announcement->excerpt(180)))
                                    <span class="ah-muted" style="display: block; margin-top: .25rem;">{{ $excerpt }}</span>
                                @endif
                            </span>
                        </span>
                        @if ($done)
                            <x-filament::badge color="success" icon="heroicon-m-check-circle" size="sm">{{ $announcement->requires_acknowledgement ? 'Acknowledged' : 'Read' }}</x-filament::badge>
                        @elseif ($announcement->requires_acknowledgement)
                            <x-filament::button size="sm" icon="heroicon-m-hand-thumb-up" wire:click="acknowledge({{ $announcement->getKey() }})">Acknowledge</x-filament::button>
                        @else
                            <x-filament::button size="sm" color="gray" icon="heroicon-m-check" wire:click="markRead({{ $announcement->getKey() }})">Mark as read</x-filament::button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
