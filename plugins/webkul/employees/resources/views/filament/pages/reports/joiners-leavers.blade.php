@php
    $table = 'width: 100%; border-collapse: collapse; font-size: .875rem;';
    $th = 'text-align: left; padding: .5rem .75rem; font-weight: 600; border-bottom: 1px solid rgba(127,127,127,.25); white-space: nowrap;';
    $thNum = $th.' text-align: right;';
    $td = 'padding: .5rem .75rem; border-bottom: 1px solid rgba(127,127,127,.12);';
    $tdNum = $td.' text-align: right; font-variant-numeric: tabular-nums;';
    $tdTotal = 'padding: .5rem .75rem; border-top: 1px solid rgba(127,127,127,.35); font-weight: 600;';
    $tdTotalNum = $tdTotal.' text-align: right; font-variant-numeric: tabular-nums;';
    $date = fn (?string $value): string => $value ? \Carbon\CarbonImmutable::parse($value)->format('d M Y') : '—';
    $source = fn (?string $value): string => $value && $value !== \Webkul\Employee\Services\Reports\EmployeeMovements::SOURCE_RECORDED ? ' ('.$value.')' : '';
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
            :description="($report['department'] ? $report['department'].' (with sub-departments). ' : '').'Joining date, else the day the record was created; leaving date, else the departure date, else the day the employee was archived. Turnover = leavers / average of the headcount at the start and the end × 100.'"
        >
            <div class="hr-report-stats">
                @foreach ([
                    'Joiners' => $summary['joiners'],
                    'Leavers' => $summary['leavers'],
                    'Net change' => ($summary['net'] > 0 ? '+' : '').$summary['net'],
                    'Headcount start → end' => $summary['opening'].' → '.$summary['closing'],
                    'Turnover' => number_format($summary['turnover_percent'], 1).'%',
                ] as $label => $value)
                    <div class="hr-report-stat">
                        <div class="hr-report-stat-label">{{ $label }}</div>
                        <div class="hr-report-stat-value">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div class="hr-report-grid">
            <x-filament::section heading="By month">
                <div class="hr-report-scroll">
                    <table style="{{ $table }}">
                        <thead>
                            <tr>
                                <th style="{{ $th }}">Month</th>
                                <th style="{{ $thNum }}">Joiners</th>
                                <th style="{{ $thNum }}">Leavers</th>
                                <th style="{{ $thNum }}">Net change</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['months'] as $month)
                                <tr>
                                    <td style="{{ $td }}">{{ $month['label'] }}</td>
                                    <td style="{{ $tdNum }}">{{ $month['joiners'] }}</td>
                                    <td style="{{ $tdNum }}">{{ $month['leavers'] }}</td>
                                    <td style="{{ $tdNum }}">{{ ($month['net'] > 0 ? '+' : '').$month['net'] }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td style="{{ $tdTotal }}">Total</td>
                                <td style="{{ $tdTotalNum }}">{{ $summary['joiners'] }}</td>
                                <td style="{{ $tdTotalNum }}">{{ $summary['leavers'] }}</td>
                                <td style="{{ $tdTotalNum }}">{{ ($summary['net'] > 0 ? '+' : '').$summary['net'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section heading="Departure reasons">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Reason</th>
                            <th style="{{ $thNum }}">Leavers</th>
                            <th style="{{ $thNum }}">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['reasons'] as $reason)
                            <tr>
                                <td style="{{ $td }}">{{ $reason['reason'] }}</td>
                                <td style="{{ $tdNum }}">{{ $reason['count'] }}</td>
                                <td style="{{ $tdNum }}">{{ number_format($reason['percent'], 1) }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" style="{{ $td }} text-align: center; opacity: .7;">No one left in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        <x-filament::section :heading="'Joiners ('.count($report['joiners']).')'">
            <div class="hr-report-scroll">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Code</th>
                            <th style="{{ $th }}">Employee</th>
                            <th style="{{ $th }}">Department</th>
                            <th style="{{ $th }}">Job title</th>
                            <th style="{{ $th }}">Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['joiners'] as $person)
                            <tr>
                                <td style="{{ $td }} white-space: nowrap;">{{ $person['code'] ?: '—' }}</td>
                                <td style="{{ $td }} font-weight: 500;">{{ $person['name'] }}</td>
                                <td style="{{ $td }}">{{ $person['department'] }}</td>
                                <td style="{{ $td }}">{{ $person['job_title'] ?: '—' }}</td>
                                <td style="{{ $td }} white-space: nowrap;">{{ $date($person['joined']) }}<span style="opacity: .6;">{{ $source($person['joined_source']) }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="{{ $td }} text-align: center; opacity: .7;">No one joined in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section :heading="'Leavers ('.count($report['leavers']).')'">
            <div class="hr-report-scroll">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Code</th>
                            <th style="{{ $th }}">Employee</th>
                            <th style="{{ $th }}">Department</th>
                            <th style="{{ $th }}">Job title</th>
                            <th style="{{ $th }}">Joined</th>
                            <th style="{{ $th }}">Left</th>
                            <th style="{{ $th }}">Tenure</th>
                            <th style="{{ $th }}">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['leavers'] as $person)
                            <tr>
                                <td style="{{ $td }} white-space: nowrap;">{{ $person['code'] ?: '—' }}</td>
                                <td style="{{ $td }} font-weight: 500;">{{ $person['name'] }}</td>
                                <td style="{{ $td }}">{{ $person['department'] }}</td>
                                <td style="{{ $td }}">{{ $person['job_title'] ?: '—' }}</td>
                                <td style="{{ $td }} white-space: nowrap;">{{ $date($person['joined']) }}</td>
                                <td style="{{ $td }} white-space: nowrap;">{{ $date($person['left']) }}<span style="opacity: .6;">{{ $source($person['left_source']) }}</span></td>
                                <td style="{{ $td }} white-space: nowrap;">{{ $person['tenure'] }}</td>
                                <td style="{{ $td }}">
                                    {{ $person['reason'] ?? \Webkul\Employee\Services\Reports\JoinersLeaversReport::NO_REASON }}
                                    @if ($person['description'])
                                        <div style="font-size: .75rem; opacity: .7;">{{ \Illuminate\Support\Str::limit($person['description'], 120) }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" style="{{ $td }} text-align: center; opacity: .7;">No one left in this period.</td>
                            </tr>
                        @endforelse
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
