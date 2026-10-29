@php
    $table = 'width: 100%; border-collapse: collapse; font-size: .875rem;';
    $th = 'text-align: left; padding: .5rem .75rem; font-weight: 600; border-bottom: 1px solid rgba(127,127,127,.25); white-space: nowrap;';
    $thNum = $th.' text-align: right;';
    $thGroup = 'text-align: center; padding: .5rem .75rem; font-weight: 600; border-bottom: 1px solid rgba(127,127,127,.25); border-left: 1px solid rgba(127,127,127,.2); white-space: nowrap;';
    $td = 'padding: .5rem .75rem; border-bottom: 1px solid rgba(127,127,127,.12);';
    $tdNum = $td.' text-align: right; font-variant-numeric: tabular-nums;';
    $tdTotal = 'padding: .5rem .75rem; border-top: 1px solid rgba(127,127,127,.35); font-weight: 600;';
    $tdTotalNum = $tdTotal.' text-align: right; font-variant-numeric: tabular-nums;';
    $num = fn ($value): string => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $figures = ['allocated' => 'Allocated', 'taken' => 'Taken', 'pending' => 'Pending', 'remaining' => 'Remaining'];
@endphp

<x-filament-panels::page>
    @include('employees::filament.pages.reports.partials.styles')

    {{ $this->form }}

    @if ($error)
        <x-filament::section>
            <p style="color: rgb(220, 38, 38);">{{ $error }}</p>
        </x-filament::section>
    @elseif ($report)
        <x-filament::section
            :heading="$report['company']['name'].' — '.$report['year']"
            :description="($report['department'] ? $report['department'].' (with sub-departments). ' : '').'Allocated = approved allocations of the year; taken = approved leave starting in the year; pending = leave waiting for a decision; remaining = allocated - taken - pending. Refused and cancelled leave never count, and Half day is not leave. Everyone employed at some point in the year is listed.'"
        >
            @if ($report['without_allocation'] > 0)
                <p style="margin-bottom: .75rem; color: rgb(217, 119, 6); font-weight: 500;">
                    {{ $report['without_allocation'] }} {{ \Illuminate\Support\Str::plural('employee', $report['without_allocation']) }} {{ $report['without_allocation'] === 1 ? 'has' : 'have' }} no approved allocation for {{ $report['year'] }}.
                </p>
            @endif

            <div class="hr-report-scroll">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}" rowspan="2">Code</th>
                            <th style="{{ $th }}" rowspan="2">Employee</th>
                            <th style="{{ $th }}" rowspan="2">Department</th>
                            @foreach ($report['types'] as $type)
                                <th style="{{ $thGroup }}" colspan="4">
                                    {{ $type['name'] }}
                                    @if ($type['id'] === null)
                                        <span style="font-weight: 400; opacity: .7;">(no such leave type)</span>
                                    @elseif (! $type['tracked'])
                                        <span style="font-weight: 400; opacity: .7;">(no balance)</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach ($report['types'] as $type)
                                @foreach ($figures as $label)
                                    <th style="{{ $thNum }} {{ $loop->first ? 'border-left: 1px solid rgba(127,127,127,.2);' : '' }}">{{ $label }}</th>
                                @endforeach
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['rows'] as $row)
                            <tr>
                                <td style="{{ $td }} white-space: nowrap;">{{ $row['code'] ?: '—' }}</td>
                                <td style="{{ $td }} font-weight: 500; white-space: nowrap;">
                                    {{ $row['name'] }}
                                    @if ($row['left'])
                                        <span style="font-weight: 400; font-size: .75rem; opacity: .7;">left {{ \Carbon\CarbonImmutable::parse($row['left'])->format('d M Y') }}</span>
                                    @endif
                                </td>
                                <td style="{{ $td }} white-space: nowrap;">{{ $row['department'] }}</td>
                                @foreach ($report['types'] as $type)
                                    @php($balance = $row['balances'][$type['key']])
                                    @foreach ($figures as $figure => $label)
                                        @php($value = $balance[$figure] ?? null)
                                        <td style="{{ $tdNum }} {{ $loop->first ? 'border-left: 1px solid rgba(127,127,127,.2);' : '' }} {{ $figure === 'remaining' && $value !== null && $value < 0 ? 'color: rgb(220, 38, 38); font-weight: 600;' : '' }} {{ $figure === 'remaining' ? 'font-weight: 600;' : '' }}">{{ $num($value) }}</td>
                                    @endforeach
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 3 + count($report['types']) * 4 }}" style="{{ $td }} text-align: center; opacity: .7;">No employees match these filters.</td>
                            </tr>
                        @endforelse
                        @if ($report['rows'] !== [])
                            <tr>
                                <td style="{{ $tdTotal }}" colspan="3">Total ({{ count($report['rows']) }})</td>
                                @foreach ($report['types'] as $type)
                                    @foreach ($figures as $figure => $label)
                                        <td style="{{ $tdTotalNum }} {{ $loop->first ? 'border-left: 1px solid rgba(127,127,127,.2);' : '' }}">{{ $num($report['totals'][$type['key']][$figure] ?? null) }}</td>
                                    @endforeach
                                @endforeach
                            </tr>
                        @endif
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
