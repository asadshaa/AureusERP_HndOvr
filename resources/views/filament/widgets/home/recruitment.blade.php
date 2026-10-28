<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-briefcase" icon-color="primary">
        <x-slot name="heading">Hiring</x-slot>
        <x-slot name="description">New applications this week, interviews and open jobs.</x-slot>
        @if ($urls['intake'] ?? null)
            <x-slot name="afterHeader">
                <x-filament::button tag="a" :href="$urls['intake']" size="sm" color="gray" icon="heroicon-m-globe-alt">
                    Website intake
                </x-filament::button>
            </x-slot>
        @endif

        <div class="ah-stack">
            <div class="ah-grid ah-grid-4">
                <{{ ($urls['applicants'] ?? null) ? 'a' : 'div' }} @if ($urls['applicants'] ?? null) href="{{ $urls['applicants'] }}" @endif class="ah-tile">
                    <div class="ah-between" style="align-items: center;">
                        <span class="ah-label">New this week</span>
                        <span class="ah-chip ah-tone-primary"><x-filament::icon icon="heroicon-o-document-plus" /></span>
                    </div>
                    <div class="ah-value">{{ $newCount }}</div>
                    <span class="ah-small">applications since Monday</span>
                </{{ ($urls['applicants'] ?? null) ? 'a' : 'div' }}>

                <{{ ($urls['interviews'] ?? null) ? 'a' : 'div' }} @if ($urls['interviews'] ?? null) href="{{ $urls['interviews'] }}" @endif class="ah-tile">
                    <div class="ah-between" style="align-items: center;">
                        <span class="ah-label">Interviews today</span>
                        <span class="ah-chip ah-tone-warning"><x-filament::icon icon="heroicon-o-calendar-days" /></span>
                    </div>
                    <div @class(['ah-value', 'ah-text-warning' => $counts['today'] > 0])>{{ $counts['today'] }}</div>
                    <span class="ah-small">scheduled for today</span>
                </{{ ($urls['interviews'] ?? null) ? 'a' : 'div' }}>

                <{{ ($urls['interviews'] ?? null) ? 'a' : 'div' }} @if ($urls['interviews'] ?? null) href="{{ $urls['interviews'] }}" @endif class="ah-tile">
                    <div class="ah-between" style="align-items: center;">
                        <span class="ah-label">This week</span>
                        <span class="ah-chip ah-tone-info"><x-filament::icon icon="heroicon-o-calendar" /></span>
                    </div>
                    <div class="ah-value">{{ $counts['week'] }}</div>
                    <span class="ah-small">interviews Monday to Sunday</span>
                </{{ ($urls['interviews'] ?? null) ? 'a' : 'div' }}>

                <{{ ($urls['jobs'] ?? null) ? 'a' : 'div' }} @if ($urls['jobs'] ?? null) href="{{ $urls['jobs'] }}" @endif class="ah-tile">
                    <div class="ah-between" style="align-items: center;">
                        <span class="ah-label">Jobs open</span>
                        <span class="ah-chip ah-tone-success"><x-filament::icon icon="heroicon-o-megaphone" /></span>
                    </div>
                    <div class="ah-value">{{ $openJobs }}</div>
                    <span class="ah-small">published on the careers site</span>
                </{{ ($urls['jobs'] ?? null) ? 'a' : 'div' }}>
            </div>

            <div class="ah-grid ah-grid-2">
                <div>
                    <h3 class="ah-heading">
                        <span>Best new applicants</span>
                        @if ($urls['applicants'] ?? null)
                            <a class="ah-link" href="{{ $urls['applicants'] }}">All applicants</a>
                        @endif
                    </h3>
                    @if ($newApplicants !== [])
                        <ul class="ah-list">
                            @foreach ($newApplicants as $applicant)
                                <li>
                                    <span class="ah-grow">
                                        @if ($applicant['url'])
                                            <a class="ah-strong ah-ellipsis" style="display: block; font-size: .875rem; text-decoration: none;" href="{{ $applicant['url'] }}">{{ $applicant['name'] }}</a>
                                        @else
                                            <span class="ah-strong ah-ellipsis" style="display: block; font-size: .875rem;">{{ $applicant['name'] }}</span>
                                        @endif
                                        <span class="ah-small">{{ $applicant['job'] }} · {{ $applicant['when'] }}</span>
                                    </span>
                                    <span class="ah-row" style="gap: .375rem; flex-wrap: nowrap;">
                                        @if ($applicant['flagged'])
                                            <x-filament::badge color="danger" size="sm">Not qualified</x-filament::badge>
                                        @endif
                                        <x-filament::badge :color="$applicant['color']" size="sm" tooltip="CV score">
                                            {{ $applicant['score'] !== null ? $applicant['score'] : 'Not scored' }}
                                        </x-filament::badge>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="ah-empty">
                            <x-filament::icon icon="heroicon-o-inbox" />
                            <span>No new applications this week. Share a job ad to get more.</span>
                        </div>
                    @endif
                </div>

                <div>
                    <h3 class="ah-heading">
                        <span>Today's interviews</span>
                        @if ($urls['interviews'] ?? null)
                            <a class="ah-link" href="{{ $urls['interviews'] }}">All interviews</a>
                        @endif
                    </h3>
                    @if ($interviewsToday !== [])
                        <ul class="ah-list">
                            @foreach ($interviewsToday as $interview)
                                <li>
                                    <span class="ah-row ah-grow" style="flex-wrap: nowrap;">
                                        <x-filament::badge color="gray" size="sm">{{ $interview['time'] }}</x-filament::badge>
                                        <span class="ah-grow">
                                            <span class="ah-strong ah-ellipsis" style="display: block; font-size: .875rem;">{{ $interview['name'] }}</span>
                                            <span class="ah-small">{{ $interview['job'] }}</span>
                                        </span>
                                    </span>
                                    @if ($interview['mode'])
                                        <span class="ah-small">{{ $interview['mode'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="ah-empty">
                            <x-filament::icon icon="heroicon-o-calendar" />
                            <span>No interviews today. Schedule one from an applicant's page.</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
