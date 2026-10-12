<x-filament-widgets::widget>
    @php
        $user = $data['user'] ?? ['name' => 'User', 'role_label' => 'Team Member'];
        $pendingCount = $data['pending_count'] ?? 0;
        $items = $data['items'] ?? [];
    @endphp

    <div class="space-y-4" wire:poll.30s>
        {{-- Header Section matching Filament Native ERP Theme --}}
        <x-filament::section
            icon="heroicon-o-sparkles"
            icon-color="primary"
        >
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <span class="text-base font-semibold text-gray-950 dark:text-white">
                        Welcome back, {{ $user['name'] }}
                    </span>
                    <x-filament::badge color="primary" size="sm">
                        {{ $user['role_label'] }}
                    </x-filament::badge>
                </div>
            </x-slot>

            <x-slot name="description">
                @if ($pendingCount > 0)
                    You have <span class="font-medium text-amber-600 dark:text-amber-400">{{ $pendingCount }} pending item{{ $pendingCount > 1 ? 's' : '' }}</span> requiring your attention today.
                @else
                    You are all caught up! No operational backlogs require your action right now.
                @endif
            </x-slot>

            <x-slot name="afterHeader">
                @if ($pendingCount > 0)
                    <x-filament::badge color="warning" size="md" icon="heroicon-m-clock">
                        {{ $pendingCount }} Action{{ $pendingCount > 1 ? 's' : '' }} Pending
                    </x-filament::badge>
                @else
                    <x-filament::badge color="success" size="md" icon="heroicon-m-check-circle">
                        All Clear
                    </x-filament::badge>
                @endif
            </x-slot>
        </x-filament::section>

        {{-- Action Cards Grid matching Filament Native Card Styling --}}
        @if ($pendingCount > 0)
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem;">
                @foreach ($items as $item)
                    @php
                        $badgeColor = match($item['urgency']) {
                            'danger'  => 'danger',
                            'warning' => 'warning',
                            default   => 'info',
                        };

                        $iconColor = match($item['urgency']) {
                            'danger'  => 'danger',
                            'warning' => 'warning',
                            default   => 'primary',
                        };

                        $cleanLabel = rtrim($item['action_label'], ' →');
                    @endphp

                    <x-filament::section
                        compact
                        :icon="$item['icon']"
                        :icon-color="$iconColor"
                        class="h-full flex flex-col justify-between transition-all duration-150 hover:shadow-md"
                    >
                        <x-slot name="heading">
                            <a href="{{ $item['url'] }}" class="text-sm font-semibold text-gray-950 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 transition-colors">
                                {{ $item['title'] }}
                            </a>
                        </x-slot>

                        <x-slot name="afterHeader">
                            <x-filament::badge :color="$badgeColor" size="xs">
                                {{ $item['urgency'] === 'danger' ? 'Urgent' : 'Pending' }}
                            </x-filament::badge>
                        </x-slot>

                        <p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed min-h-[3rem]">
                            {{ $item['description'] }}
                        </p>

                        <x-slot name="footer">
                            <div class="flex items-center justify-end">
                                <x-filament::button
                                    tag="a"
                                    :href="$item['url']"
                                    size="xs"
                                    color="gray"
                                    outlined
                                    icon="heroicon-m-arrow-right"
                                    icon-position="after"
                                >
                                    {{ $cleanLabel }}
                                </x-filament::button>
                            </div>
                        </x-slot>
                    </x-filament::section>
                @endforeach
            </div>
        @else
            {{-- All Clear State --}}
            <x-filament::section compact>
                <div class="flex items-center gap-3 text-sm text-emerald-700 dark:text-emerald-400">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 flex-shrink-0" />
                    <div>
                        <span class="font-semibold">No Pending Approvals:</span> Everything assigned to your role has been reviewed and cleared.
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-widgets::widget>
