<x-filament-panels::page>
    @if ($focused = $this->focusedAnnouncement())
        <x-filament::section :icon="$focused->is_pinned ? 'heroicon-s-bookmark' : 'heroicon-o-megaphone'" icon-color="primary">
            <x-slot name="heading">{{ $focused->title }}</x-slot>
            <x-slot name="description">
                {{ ($focused->publish_at ?? $focused->created_at)?->timezone(\Webkul\Employee\Support\ChecklistKinds::timezone())->format('l, d M Y') }}
                @if ($focused->expires_at)
                    · until {{ $focused->expires_at->timezone(\Webkul\Employee\Support\ChecklistKinds::timezone())->format('d M Y H:i') }}
                @endif
            </x-slot>
            <x-slot name="afterHeader">
                <x-filament::button color="gray" size="sm" icon="heroicon-m-x-mark" wire:click="closeFocused">Close</x-filament::button>
            </x-slot>

            <div class="fi-prose">{!! $focused->safeBody() !!}</div>

            <div style="display: flex; gap: .5rem; margin-top: 1rem; flex-wrap: wrap;">
                @if ($focused->requires_acknowledgement)
                    @if ($focused->my_acknowledged_at)
                        <x-filament::badge color="success" icon="heroicon-m-check-circle">Acknowledged</x-filament::badge>
                    @elseif ($focused->isLive())
                        <x-filament::button icon="heroicon-m-hand-thumb-up" wire:click="acknowledge({{ $focused->getKey() }})">I have read and understood this</x-filament::button>
                    @endif
                @elseif ($focused->my_read_at)
                    <x-filament::badge color="success" icon="heroicon-m-check-circle">Read</x-filament::badge>
                @elseif ($focused->isLive())
                    <x-filament::button icon="heroicon-m-check" wire:click="markRead({{ $focused->getKey() }})">Mark as read</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
