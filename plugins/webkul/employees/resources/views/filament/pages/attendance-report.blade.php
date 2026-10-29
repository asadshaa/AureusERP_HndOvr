@php
    $hours = fn (int $seconds): string => intdiv($seconds, 3600).':'.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT);
    $num = fn ($value): string => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $table = 'width: 100%; border-collapse: collapse; font-size: .875rem;';
    $th = 'text-align: left; padding: .5rem .75rem; font-weight: 600; border-bottom: 1px solid rgba(127,127,127,.25); white-space: nowrap;';
    $thNum = $th.' text-align: right;';
    $td = 'padding: .5rem .75rem; border-bottom: 1px solid rgba(127,127,127,.12);';
    $tdNum = $td.' text-align: right; font-variant-numeric: tabular-nums;';
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($error)
        <x-filament::section>
            <p style="color: rgb(220, 38, 38);">{{ $error }}</p>
        </x-filament::section>
    @elseif ($report)
        <x-filament::section
            :heading="$report['company']['name'].' — '.$report['month_label']"
            description="Worked = time inside each employee's scheduled window plus up to the allowed time outside it, net of approved overtime. Required = working days (weekends, public holidays and approved leave excluded) × the schedule's daily minutes. Days still to come are not counted."
        >
            @if (! empty($report['finalised']))
                <p style="margin-bottom: .75rem; font-weight: 600; color: rgb(217, 119, 6);">
                    Finalised on {{ $report['finalised']['at']->format('d M Y h:i A') }}{{ $report['finalised']['by'] ? ' by '.$report['finalised']['by'] : '' }} — this is the frozen snapshot; attendance, leave and overtime of the month are locked.
                </p>
            @endif
            <div style="overflow-x: auto;">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Code</th>
                            <th style="{{ $th }}">Employee</th>
                            <th style="{{ $th }}">Department</th>
                            <th style="{{ $th }}">Designation</th>
                            <th style="{{ $thNum }}">Working days</th>
                            <th style="{{ $thNum }}">Present</th>
                            <th style="{{ $thNum }}">Absent</th>
                            <th style="{{ $thNum }}">Leave</th>
                            <th style="{{ $thNum }}">OD</th>
                            <th style="{{ $thNum }}">Late</th>
                            <th style="{{ $thNum }}">Missing check-out</th>
                            <th style="{{ $thNum }}">Total Hrs Worked</th>
                            <th style="{{ $thNum }}">Total Hrs Req</th>
                            <th style="{{ $thNum }}">Delta</th>
                            <th style="{{ $thNum }}">Penalty</th>
                            <th style="{{ $thNum }}">Extra</th>
                            <th style="{{ $thNum }}">Late (min)</th>
                            <th style="{{ $thNum }}">Left early (min)</th>
                            <th style="{{ $thNum }}">Away (min)</th>
                            <th style="{{ $thNum }}">Earned extra (min)</th>
                            <th style="{{ $thNum }}">Grace allowance (min)</th>
                            <th style="{{ $thNum }}">Grace balance (min)</th>
                            <th style="{{ $thNum }}">OT approved (h)</th>
                            <th style="{{ $thNum }}">OT pending (h)</th>
                            <th style="{{ $thNum }}">Weekend / holiday (h)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['employees'] as $row)
                            @php($s = $row['summary'])
                            <tr>
                                <td style="{{ $td }}">{{ $row['employee']['code'] ?: '—' }}</td>
                                <td style="{{ $td }} font-weight: 500;">{{ $row['employee']['name'] }}</td>
                                <td style="{{ $td }}">{{ $row['employee']['department'] ?: '—' }}</td>
                                <td style="{{ $td }}">{{ $row['employee']['designation'] ?: '—' }}</td>
                                <td style="{{ $tdNum }}">{{ $s['working_days'] }}</td>
                                <td style="{{ $tdNum }}">{{ $s['present_days'] }}</td>
                                <td style="{{ $tdNum }}">{{ $s['absent_days'] }}</td>
                                <td style="{{ $tdNum }}">{{ $num($s['leave_days']) }}</td>
                                <td style="{{ $tdNum }}">{{ $num($s['od_days']) }}</td>
                                <td style="{{ $tdNum }}">{{ $s['late_days'] }}</td>
                                <td style="{{ $tdNum }}">{{ $s['missing_check_outs'] }}</td>
                                <td style="{{ $tdNum }}">{{ number_format($s['worked_hours'], 2) }}</td>
                                <td style="{{ $tdNum }}">{{ number_format($s['required_hours'], 2) }}</td>
                                <td style="{{ $tdNum }}">{{ number_format($s['delta_hours'], 2) }}</td>
                                <td style="{{ $tdNum }} {{ $s['penalty_percent'] > 0 ? 'color: rgb(220, 38, 38); font-weight: 600;' : '' }}">{{ number_format($s['penalty_percent'], 2) }}%</td>
                                <td style="{{ $tdNum }}">{{ $hours($s['extra_minutes'] * 60) }}</td>
                                <td style="{{ $tdNum }}">{{ $s['late_minutes'] }}</td>
                                <td style="{{ $tdNum }}">{{ $s['left_early_minutes'] }}</td>
                                <td style="{{ $tdNum }}">{{ $s['gap_minutes'] }}</td>
                                <td style="{{ $tdNum }}">{{ $s['time_bank_extra_minutes'] }}</td>
                                <td style="{{ $tdNum }}">{{ $s['grace_allowance_minutes'] + $s['grace_carry_in_minutes'] }}</td>
                                <td style="{{ $tdNum }} {{ $s['grace_balance_minutes'] < 0 ? 'color: rgb(220, 38, 38); font-weight: 600;' : '' }}">{{ $s['grace_balance_minutes'] }}</td>
                                <td style="{{ $tdNum }}">{{ number_format((float) ($s['overtime_approved_hours'] ?? 0), 2) }}</td>
                                <td style="{{ $tdNum }}">{{ number_format((float) ($s['overtime_pending_hours'] ?? 0), 2) }}</td>
                                <td style="{{ $tdNum }}">{{ number_format((float) ($s['weekend_holiday_hours'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="25" style="{{ $td }} text-align: center; opacity: .7;">No employees match these filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if (count($report['employees']) === 1)
            @include('employees::filament.components.grace-ledger', ['ledgerRows' => $report['employees'][0]['rows'], 'ledgerSummary' => $report['employees'][0]['summary'], 'ledgerTitle' => 'Grace balance — '.$report['employees'][0]['employee']['name']])
        @endif
    @else
        <x-filament::section>
            <p style="opacity: .7;">Choose a company you belong to.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
