<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-sun" icon-color="primary">
        <x-slot name="heading">My day</x-slot>
        <x-slot name="description">Your attendance, grace time and requests at a glance.</x-slot>

        <div class="ah-grid ah-grid-4">
            {{-- Check in / out --}}
            <div class="ah-tile">
                <div class="ah-row">
                    <span @class(['ah-chip ah-chip-lg', 'ah-tone-'.($attendance['tone'] ?? 'gray')])>
                        <x-filament::icon icon="heroicon-o-finger-print" />
                    </span>
                    <div class="ah-grow">
                        <div class="ah-label">Today</div>
                        <div class="ah-strong">{{ $attendance['label'] ?? 'Attendance' }}</div>
                    </div>
                </div>
                <p class="ah-muted" style="margin: 0;">
                    {{ $attendance['detail'] ?? 'Check in and out from My Attendance.' }}
                </p>
                @if ($urls['attendance'] ?? null)
                    <x-filament::button
                        tag="a"
                        :href="$urls['attendance']"
                        size="lg"
                        icon="heroicon-m-finger-print"
                        :color="($attendance['state'] ?? '') === 'checked_in' ? 'warning' : 'primary'"
                        style="width: 100%; margin-top: auto;"
                    >
                        {{ $attendance['button'] ?? 'Open My Attendance' }}
                    </x-filament::button>
                @else
                    <p class="ah-small" style="margin: 0;">Location check-in is not enabled for you. Ask HR if you think it should be.</p>
                @endif
            </div>

            {{-- Grace balance --}}
            <div class="ah-tile">
                <div class="ah-row">
                    <span @class(['ah-chip ah-chip-lg', 'ah-tone-'.($grace['tone'] ?? 'gray')])>
                        <x-filament::icon icon="heroicon-o-clock" />
                    </span>
                    <div class="ah-grow">
                        <div class="ah-label">Grace balance{{ $grace ? ' · '.$grace['month'] : '' }}</div>
                        <div @class(['ah-value ah-value-sm', 'ah-text-'.($grace['tone'] ?? 'info')])>{{ $grace['balance'] ?? '—' }}</div>
                    </div>
                </div>
                @if ($grace)
                    <div @class(['ah-bar', 'ah-bar-'.$grace['tone']]) role="progressbar" aria-valuenow="{{ $grace['percentage'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Grace time left">
                        <span style="width: {{ $grace['percentage'] }}%;"></span>
                    </div>
                    <p class="ah-small" style="margin: 0;">Used {{ $grace['used'] }} of {{ $grace['allowance'] }} (late arrivals, leaving early, gaps).</p>
                @else
                    <p class="ah-small" style="margin: 0;">No grace allowance this month.</p>
                @endif
            </div>

            {{-- Pending requests --}}
            <div class="ah-tile">
                <div class="ah-row">
                    <span class="ah-chip ah-chip-lg ah-tone-warning">
                        <x-filament::icon icon="heroicon-o-inbox-stack" />
                    </span>
                    <div class="ah-grow">
                        <div class="ah-label">Waiting for approval</div>
                        <div class="ah-value ah-value-sm">{{ collect($pending)->sum('count') }}</div>
                    </div>
                </div>
                <ul class="ah-list">
                    @foreach ($pending as $row)
                        <li>
                            @if ($row['url'])
                                <a class="ah-link" href="{{ $row['url'] }}">{{ $row['label'] }}</a>
                            @else
                                <span class="ah-muted">{{ $row['label'] }}</span>
                            @endif
                            <x-filament::badge :color="$row['count'] > 0 ? 'warning' : 'gray'" size="sm">{{ $row['count'] }}</x-filament::badge>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Upcoming holidays --}}
            <div class="ah-tile">
                <div class="ah-row">
                    <span class="ah-chip ah-chip-lg ah-tone-info">
                        <x-filament::icon icon="heroicon-o-calendar" />
                    </span>
                    <div class="ah-grow">
                        <div class="ah-label">Upcoming holidays</div>
                        <div class="ah-strong">{{ count($holidays) > 0 ? $holidays[0]['days'] : 'None soon' }}</div>
                    </div>
                </div>
                @if ($holidays !== [])
                    <ul class="ah-list">
                        @foreach ($holidays as $holiday)
                            <li>
                                <span class="ah-grow">
                                    <span class="ah-strong ah-ellipsis" style="display: block; font-size: .8125rem;">{{ $holiday['name'] }}</span>
                                    <span class="ah-small">{{ $holiday['date'] }}</span>
                                </span>
                                <span class="ah-small">{{ $holiday['days'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="ah-small" style="margin: 0;">No public holidays in the next 90 days.</p>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
