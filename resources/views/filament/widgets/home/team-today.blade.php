<x-filament-widgets::widget>
    @php
        $cards = [
            'present'  => ['label' => 'In today', 'icon' => 'heroicon-o-check-circle', 'tone' => 'success', 'empty' => 'Nobody has checked in yet.'],
            'late'     => ['label' => 'Late', 'icon' => 'heroicon-o-clock', 'tone' => 'warning', 'empty' => 'Nobody was late.'],
            'absent'   => ['label' => 'Absent', 'icon' => 'heroicon-o-x-circle', 'tone' => 'danger', 'empty' => 'Nobody is missing.'],
            'on_leave' => ['label' => 'On leave', 'icon' => 'heroicon-o-sun', 'tone' => 'info', 'empty' => 'Nobody is on approved leave.'],
        ];
    @endphp

    <x-filament::section icon="heroicon-o-user-group" icon-color="primary">
        <x-slot name="heading">My team today</x-slot>
        <x-slot name="description">
            {{ $dateLabel }} · {{ $teamSize }} {{ \Illuminate\Support\Str::plural('person', $teamSize) }} in your team
            @if ($offDay)
                · {{ $offDay['type'] === 'holiday' ? 'Public holiday: '.$offDay['name'] : 'Weekend' }}
            @endif
        </x-slot>
        @if ($pageUrl)
            <x-slot name="afterHeader">
                <x-filament::button tag="a" :href="$pageUrl" size="sm" color="gray" icon="heroicon-m-arrow-top-right-on-square">
                    Today's attendance
                </x-filament::button>
            </x-slot>
        @endif

        <div class="ah-stack">
            @if ($groups !== null)
                <div class="ah-grid ah-grid-4">
                    @foreach ($cards as $key => $card)
                        @php($group = $groups[$key])
                        <{{ $urls[$key] ? 'a' : 'div' }} @if ($urls[$key]) href="{{ $urls[$key] }}" @endif class="ah-tile">
                            <div class="ah-between" style="align-items: center;">
                                <span class="ah-label">{{ $card['label'] }}</span>
                                <span @class(['ah-chip', 'ah-tone-'.$card['tone']])><x-filament::icon :icon="$card['icon']" /></span>
                            </div>
                            <div @class(['ah-value', 'ah-text-'.$card['tone'] => $group['count'] > 0])>{{ $group['count'] }}</div>
                            <p class="ah-names">
                                @if ($group['count'] > 0)
                                    {{ implode(', ', $group['names']) }}@if ($group['more'] > 0) <span class="ah-small">+{{ $group['more'] }} more</span>@endif
                                @else
                                    <span class="ah-small">{{ $card['empty'] }}</span>
                                @endif
                            </p>
                        </{{ $urls[$key] ? 'a' : 'div' }}>
                    @endforeach
                </div>
            @else
                <div class="ah-empty">
                    <x-filament::icon icon="heroicon-o-information-circle" />
                    <span>You can't see your team's attendance yet. Ask HR to give you attendance access for your team.</span>
                </div>
            @endif

            <div>
                <h3 class="ah-heading">
                    <span>Team time off this week <span class="ah-small">({{ $weekLabel }})</span></span>
                </h3>
                @if ($leaves !== [])
                    <ul class="ah-list">
                        @foreach ($leaves as $leave)
                            <li>
                                <span class="ah-grow">
                                    <span class="ah-strong ah-ellipsis" style="display: block; font-size: .875rem;">{{ $leave['employee'] }}</span>
                                    <span class="ah-small">{{ $leave['type'] }} · {{ $leave['dates'] }}</span>
                                </span>
                                <x-filament::badge :color="$leave['approved'] ? 'success' : 'warning'" size="sm">
                                    {{ $leave['approved'] ? 'Approved' : 'Waiting' }}
                                </x-filament::badge>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="ah-empty">
                        <x-filament::icon icon="heroicon-o-calendar" />
                        <span>No one in your team is off this week.</span>
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
