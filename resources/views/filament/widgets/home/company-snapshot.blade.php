<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-building-office-2" icon-color="primary">
        <x-slot name="heading">Your companies</x-slot>
        <x-slot name="description">Today at a glance. Click a company to switch to it.</x-slot>

        @if ($companies !== [])
            <div class="ah-grid ah-grid-3">
                @foreach ($companies as $company)
                    <form method="POST" action="{{ route('security.switch-company') }}" style="margin: 0;">
                        @csrf
                        <input type="hidden" name="company_id" value="{{ $company['id'] }}">
                        <button type="submit" @class(['ah-button-reset ah-tile', 'ah-tile-active' => $company['is_active']]) style="height: 100%;" aria-label="Switch to {{ $company['name'] }}">
                            <span class="ah-between" style="align-items: center;">
                                <span class="ah-row" style="flex-wrap: nowrap; min-width: 0;">
                                    <span class="ah-chip ah-tone-primary"><x-filament::icon icon="heroicon-o-building-office" /></span>
                                    <span class="ah-strong ah-ellipsis">{{ $company['name'] }}</span>
                                </span>
                                @if ($company['is_active'])
                                    <x-filament::badge color="primary" size="sm">Current</x-filament::badge>
                                @else
                                    <span class="ah-link">Switch</span>
                                @endif
                            </span>

                            <span class="ah-grid ah-grid-2" style="gap: .75rem; margin-top: .25rem;">
                                <span>
                                    <span class="ah-label" style="display: block;">Headcount</span>
                                    <span class="ah-value ah-value-sm">{{ $company['headcount'] }}</span>
                                </span>
                                <span>
                                    <span class="ah-label" style="display: block;">In today</span>
                                    <span class="ah-value ah-value-sm">
                                        {{ $company['present_percentage'] !== null ? $company['present_percentage'].'%' : '—' }}
                                    </span>
                                    <span class="ah-small" style="display: block;">{{ $company['present_today'] }} checked in</span>
                                </span>
                                <span>
                                    <span class="ah-label" style="display: block;">Open jobs</span>
                                    <span class="ah-value ah-value-sm">{{ $company['open_jobs'] }}</span>
                                </span>
                                <span>
                                    <span class="ah-label" style="display: block;">Applicants</span>
                                    <span class="ah-value ah-value-sm">{{ $company['applicants_this_month'] }}</span>
                                    <span class="ah-small" style="display: block;">this month</span>
                                </span>
                            </span>

                            @if ($company['present_percentage'] !== null)
                                <span @class(['ah-bar', 'ah-bar-success' => $company['present_percentage'] >= 75, 'ah-bar-warning' => $company['present_percentage'] < 75 && $company['present_percentage'] >= 40, 'ah-bar-danger' => $company['present_percentage'] < 40]) style="display: block;">
                                    <span style="width: {{ $company['present_percentage'] }}%;"></span>
                                </span>
                            @endif
                        </button>
                    </form>
                @endforeach
            </div>
        @else
            <div class="ah-empty">
                <x-filament::icon icon="heroicon-o-building-office-2" />
                <span>You are not a member of any company yet. Ask an administrator to add you.</span>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
