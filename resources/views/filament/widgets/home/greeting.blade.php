<x-filament-widgets::widget>
    <div class="ah-hero">
        <div class="ah-between">
            <div class="ah-grow">
                <h2 class="ah-hero-title">{{ $greeting }}, {{ $firstName }}</h2>
                <p class="ah-hero-sub">
                    {{ $dateLabel }}@if ($companyName) · <span class="ah-strong">{{ $companyName }}</span>@endif
                </p>
            </div>

            @if ($roles !== [])
                <div class="ah-row">
                    @foreach ($roles as $role)
                        <x-filament::badge color="primary">{{ $role }}</x-filament::badge>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($membershipCount > 1)
            <div class="ah-hint">
                <x-filament::icon icon="heroicon-m-building-office-2" style="width: 1rem; height: 1rem;" />
                You work in {{ $membershipCount }} companies. Switch company with the button at the top right; everything below follows it.
            </div>
        @endif

        @if ($actions !== [])
            <nav class="ah-actions" aria-label="Quick actions">
                @foreach ($actions as $action)
                    <a href="{{ $action['url'] }}" @class(['ah-action', 'ah-action-primary' => $action['color'] === 'primary'])>
                        <span class="ah-chip ah-tone-primary">
                            <x-filament::icon :icon="$action['icon']" />
                        </span>
                        <span class="ah-grow">
                            <span class="ah-action-label">{{ $action['label'] }}</span>
                            @if ($action['hint'])
                                <span class="ah-action-hint">{{ $action['hint'] }}</span>
                            @endif
                        </span>
                    </a>
                @endforeach
            </nav>
        @endif
    </div>
</x-filament-widgets::widget>
