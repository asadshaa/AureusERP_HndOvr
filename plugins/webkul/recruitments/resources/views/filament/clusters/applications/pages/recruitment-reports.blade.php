@php
    $fmt = fn ($value, string $suffix = '') => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').$suffix;
    $table = 'width: 100%; border-collapse: collapse; font-size: .875rem;';
    $th = 'text-align: left; padding: .5rem .75rem; font-weight: 600; border-bottom: 1px solid rgba(127,127,127,.25); white-space: nowrap;';
    $thNum = $th.' text-align: right;';
    $td = 'padding: .5rem .75rem; border-bottom: 1px solid rgba(127,127,127,.12);';
    $tdNum = $td.' text-align: right; font-variant-numeric: tabular-nums;';
    $kpis = [
        ['Applications', $summary['applications'], null],
        ['In progress', $summary['in_progress'], null],
        ['Hired', $summary['hired'], $summary['hire_rate'] !== null ? $fmt($summary['hire_rate'], '% hire rate') : null],
        ['Refused', $summary['refused'], null],
        ['Days to hire', $fmt($summary['avg_days_to_hire']), $summary['median_days_to_hire'] !== null ? 'median '.$fmt($summary['median_days_to_hire']) : null],
        ['Avg CV score', $fmt($summary['avg_cv_score']), $summary['flagged'] ? $summary['flagged'].' not qualified (screening)' : null],
        ['Open jobs', $summary['open_jobs'], 'published now'],
    ];
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));">
        @foreach ($kpis as [$label, $value, $hint])
            <x-filament::section>
                <div style="font-size: .8rem; opacity: .7;">{{ $label }}</div>
                <div style="font-size: 1.75rem; font-weight: 700; line-height: 1.2;">{{ $value }}</div>
                @if ($hint)
                    <div style="font-size: .75rem; opacity: .7;">{{ $hint }}</div>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Where applications come from" description="By the job ad they came through (LinkedIn, Rozee.pk, Company website…), else the UTM source. Hire rate = hired ÷ applications.">
        <div style="overflow-x: auto;">
            <table style="{{ $table }}">
                <thead>
                    <tr>
                        <th style="{{ $th }}">Source</th>
                        <th style="{{ $thNum }}">Applications</th>
                        <th style="{{ $thNum }}">In progress</th>
                        <th style="{{ $thNum }}">Hired</th>
                        <th style="{{ $thNum }}">Refused</th>
                        <th style="{{ $thNum }}">Hire rate</th>
                        <th style="{{ $thNum }}">Days to hire</th>
                        <th style="{{ $thNum }}">Avg CV score</th>
                        <th style="{{ $thNum }}">Not qualified</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sources as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['label'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['applications'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['in_progress'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['hired'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['refused'] }}</td>
                            <td style="{{ $tdNum }}">{{ $fmt($row['hire_rate'], '%') }}</td>
                            <td style="{{ $tdNum }}">{{ $fmt($row['avg_days_to_hire']) }}</td>
                            <td style="{{ $tdNum }}">{{ $fmt($row['avg_cv_score']) }}</td>
                            <td style="{{ $tdNum }}">{{ $row['flagged'] }}</td>
                        </tr>
                    @empty
                        <tr><td style="{{ $td }}" colspan="9">No applications in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Jobs" description="Days open counts from publishing, for jobs that are published now.">
        <div style="overflow-x: auto;">
            <table style="{{ $table }}">
                <thead>
                    <tr>
                        <th style="{{ $th }}">Job</th>
                        <th style="{{ $thNum }}">Days open</th>
                        <th style="{{ $thNum }}">Applications</th>
                        <th style="{{ $thNum }}">In progress</th>
                        <th style="{{ $thNum }}">Hired</th>
                        <th style="{{ $thNum }}">Refused</th>
                        <th style="{{ $thNum }}">Hire rate</th>
                        <th style="{{ $thNum }}">Days to hire</th>
                        <th style="{{ $thNum }}">Avg CV score</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['label'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['days_open'] ?? '—' }}</td>
                            <td style="{{ $tdNum }}">{{ $row['applications'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['in_progress'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['hired'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['refused'] }}</td>
                            <td style="{{ $tdNum }}">{{ $fmt($row['hire_rate'], '%') }}</td>
                            <td style="{{ $tdNum }}">{{ $fmt($row['avg_days_to_hire']) }}</td>
                            <td style="{{ $tdNum }}">{{ $fmt($row['avg_cv_score']) }}</td>
                        </tr>
                    @empty
                        <tr><td style="{{ $td }}" colspan="9">No applications in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr));">
        <x-filament::section heading="Pipeline now" description="Applications still in progress, by their current stage.">
            <table style="{{ $table }}">
                <thead>
                    <tr>
                        <th style="{{ $th }}">Stage</th>
                        <th style="{{ $thNum }}">Applications</th>
                        <th style="{{ $thNum }}">Avg days in stage</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($funnel as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['label'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['applications'] }}</td>
                            <td style="{{ $tdNum }}">{{ $fmt($row['avg_days_in_stage']) }}</td>
                        </tr>
                    @empty
                        <tr><td style="{{ $td }}" colspan="3">Nothing in progress.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Month by month" description="Applications received, and hires by their hire date.">
            <table style="{{ $table }}">
                <thead>
                    <tr>
                        <th style="{{ $th }}">Month</th>
                        <th style="{{ $thNum }}">Applications</th>
                        <th style="{{ $thNum }}">Hires</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($monthly as $row)
                        <tr>
                            <td style="{{ $td }}">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $row['month'].'-01')->format('M Y') }}</td>
                            <td style="{{ $tdNum }}">{{ $row['applications'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['hires'] }}</td>
                        </tr>
                    @empty
                        <tr><td style="{{ $td }}" colspan="3">No activity in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
