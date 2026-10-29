@php
    $table = 'width: 100%; border-collapse: collapse; font-size: .875rem;';
    $th = 'text-align: left; padding: .5rem .75rem; font-weight: 600; border-bottom: 1px solid rgba(127,127,127,.25); white-space: nowrap;';
    $thNum = $th.' text-align: right;';
    $td = 'padding: .5rem .75rem; border-bottom: 1px solid rgba(127,127,127,.12);';
    $tdNum = $td.' text-align: right; font-variant-numeric: tabular-nums;';
    $tdTotal = 'padding: .5rem .75rem; border-top: 1px solid rgba(127,127,127,.35); font-weight: 600;';
    $tdTotalNum = $tdTotal.' text-align: right; font-variant-numeric: tabular-nums;';
    $chartOptions = [
        'maintainAspectRatio' => false,
        'interaction'         => ['mode' => 'index', 'intersect' => false],
        'plugins'             => ['legend' => ['display' => true, 'position' => 'bottom']],
        'scales'              => [
            'y'  => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'title' => ['display' => true, 'text' => 'Headcount']],
            // Joiners and leavers stay in the lower part of the chart, under the headcount line.
            'y1' => ['beginAtZero' => true, 'suggestedMax' => $report ? max(3, 3 * max([0, ...array_column($report['months'], 'joiners'), ...array_column($report['months'], 'leavers')])) : 3, 'position' => 'right', 'ticks' => ['precision' => 0], 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Joiners / leavers']],
        ],
    ];
@endphp

<x-filament-panels::page>
    @include('employees::filament.pages.reports.partials.styles')

    {{ $this->form }}

    @if ($error)
        <x-filament::section>
            <p style="color: rgb(220, 38, 38);">{{ $error }}</p>
        </x-filament::section>
    @elseif ($report)
        @php($summary = $report['summary'])

        <x-filament::section
            :heading="$report['company']['name'].' — '.$report['period_label']"
            :description="($report['department'] ? $report['department'].' (with sub-departments). ' : '').'Headcount = employees on the books at the close of each month (of today for the current month), from joining and leaving dates. Turnover = leavers / average headcount × 100.'"
        >
            <div class="hr-report-stats">
                @foreach ([
                    'Headcount now' => $summary['closing'],
                    'Change in period' => ($summary['change'] > 0 ? '+' : '').$summary['change'],
                    'Joiners' => $summary['joiners'],
                    'Leavers' => $summary['leavers'],
                    'Average headcount' => rtrim(rtrim(number_format($summary['average_headcount'], 1), '0'), '.'),
                    'Turnover in period' => number_format($summary['turnover_percent'], 1).'%',
                ] as $label => $value)
                    <div class="hr-report-stat">
                        <div class="hr-report-stat-label">{{ $label }}</div>
                        <div class="hr-report-stat-value">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            <div wire:key="headcount-chart-{{ md5(json_encode($report['chart'])) }}" style="margin-top: 1.5rem;">
                <div
                    x-load
                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                    wire:ignore
                    x-data="chart({
                        cachedData: @js($report['chart']),
                        options: @js($chartOptions),
                        type: 'bar',
                    })"
                    class="fi-wi-chart-canvas-ctn fi-wi-chart-canvas-ctn-no-aspect-ratio"
                    style="height: 20rem;"
                >
                    <canvas x-ref="canvas" style="width: 100%; max-height: 20rem;" aria-label="Monthly headcount, joiners and leavers" role="img"></canvas>
                    <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                    <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                    <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                    <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="By month">
            <div class="hr-report-scroll">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Month</th>
                            <th style="{{ $thNum }}">Opening</th>
                            <th style="{{ $thNum }}">Joiners</th>
                            <th style="{{ $thNum }}">Leavers</th>
                            <th style="{{ $thNum }}">Closing</th>
                            <th style="{{ $thNum }}">Turnover</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse($report['months']) as $month)
                            <tr>
                                <td style="{{ $td }}">{{ $month['label'] }}@if ($month['as_of'] !== \Carbon\CarbonImmutable::parse($month['month'].'-01')->endOfMonth()->toDateString())<span style="opacity: .6;"> (to {{ \Carbon\CarbonImmutable::parse($month['as_of'])->format('d M') }})</span>@endif</td>
                                <td style="{{ $tdNum }}">{{ $month['opening'] }}</td>
                                <td style="{{ $tdNum }}">{{ $month['joiners'] }}</td>
                                <td style="{{ $tdNum }}">{{ $month['leavers'] }}</td>
                                <td style="{{ $tdNum }} font-weight: 600;">{{ $month['closing'] }}</td>
                                <td style="{{ $tdNum }}">{{ number_format($month['turnover_percent'], 1) }}%</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td style="{{ $tdTotal }}">Period</td>
                            <td style="{{ $tdTotalNum }}">{{ $summary['opening'] }}</td>
                            <td style="{{ $tdTotalNum }}">{{ $summary['joiners'] }}</td>
                            <td style="{{ $tdTotalNum }}">{{ $summary['leavers'] }}</td>
                            <td style="{{ $tdTotalNum }}">{{ $summary['closing'] }}</td>
                            <td style="{{ $tdTotalNum }}">{{ number_format($summary['turnover_percent'], 1) }}%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="By department" description="Closing headcount of each month, by the employees' current department.">
            <div class="hr-report-scroll">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Department</th>
                            @foreach ($report['months'] as $month)
                                <th style="{{ $thNum }}">{{ \Carbon\CarbonImmutable::parse($month['month'].'-01')->format('M y') }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['departments'] as $department)
                            <tr>
                                <td style="{{ $td }} white-space: nowrap;">{{ $department['name'] }}</td>
                                @foreach ($report['months'] as $month)
                                    <td style="{{ $tdNum }}">{{ $department['closing'][$month['month']] }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($report['months']) + 1 }}" style="{{ $td }} text-align: center; opacity: .7;">No employees in this period.</td>
                            </tr>
                        @endforelse
                        <tr>
                            <td style="{{ $tdTotal }}">Total</td>
                            @foreach ($report['months'] as $month)
                                <td style="{{ $tdTotalNum }}">{{ $month['closing'] }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <p style="opacity: .7;">Choose a company you belong to.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
