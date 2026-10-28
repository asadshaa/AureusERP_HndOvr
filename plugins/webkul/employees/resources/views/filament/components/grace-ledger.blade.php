{{--
    Grace balance card + per-day ledger. Fed by MonthlyAttendanceReport rows and summary,
    so the employee page and HR's report / Excel show the same numbers.
    Expects: $ledgerRows (report rows), $ledgerSummary (report summary), $ledgerTitle.
--}}
@php
    $minutes = function (int $value): string {
        $sign = $value < 0 ? '−' : '';
        $value = abs($value);

        return $sign.intdiv($value, 60).':'.str_pad((string) ($value % 60), 2, '0', STR_PAD_LEFT);
    };
    $allowance = (int) $ledgerSummary['grace_allowance_minutes'] + (int) $ledgerSummary['grace_carry_in_minutes'];
    $earned = (int) $ledgerSummary['time_bank_extra_minutes'];
    $used = (int) $ledgerSummary['late_minutes'] + (int) $ledgerSummary['left_early_minutes'] + (int) $ledgerSummary['gap_minutes'];
    $balance = (int) $ledgerSummary['grace_balance_minutes'];
    $lines = collect($ledgerRows)->filter(fn (array $row): bool => $row['working'] || ($row['ledger_seconds'] ?? 0) !== 0);
    $cell = 'padding: .4rem .6rem; border-bottom: 1px solid rgba(127,127,127,.12);';
    $num = $cell.' text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;';
    $head = 'padding: .4rem .6rem; font-weight: 600; border-bottom: 1px solid rgba(127,127,127,.25); text-align: left; white-space: nowrap;';
@endphp

<x-filament::section :heading="$ledgerTitle" description="Grace balance = monthly allowance + extra earned before 10:00 / after 18:00 (capped per day) − late − left early − time away without approval. A negative balance is the month's shortfall (Delta).">
    <div data-testid="grace-balance-card" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .75rem; margin-bottom: 1rem;">
        <div>
            <div style="font-size: .75rem; opacity: .7;">Allowance this month</div>
            <div style="font-size: 1.25rem; font-weight: 700;">{{ $minutes($allowance) }}</div>
        </div>
        <div>
            <div style="font-size: .75rem; opacity: .7;">Earned extra</div>
            <div style="font-size: 1.25rem; font-weight: 700; color: rgb(5, 150, 105);">+{{ $minutes($earned) }}</div>
        </div>
        <div>
            <div style="font-size: .75rem; opacity: .7;">Used (late, left early, away)</div>
            <div style="font-size: 1.25rem; font-weight: 700; color: rgb(217, 119, 6);">−{{ $minutes($used) }}</div>
        </div>
        <div>
            <div style="font-size: .75rem; opacity: .7;">Balance now</div>
            <div data-testid="grace-balance-now" style="font-size: 1.25rem; font-weight: 700; {{ $balance < 0 ? 'color: rgb(220, 38, 38);' : '' }}">{{ $minutes($balance) }}</div>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: .8125rem;">
            <thead>
                <tr>
                    <th style="{{ $head }}">Date</th>
                    <th style="{{ $head }}">Check-ins</th>
                    <th style="{{ $head }} text-align: right;">+ Extra</th>
                    <th style="{{ $head }} text-align: right;">− Late</th>
                    <th style="{{ $head }} text-align: right;">− Left early</th>
                    <th style="{{ $head }} text-align: right;">− Away</th>
                    <th style="{{ $head }} text-align: right;">Balance</th>
                    <th style="{{ $head }}">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="{{ $cell }}" colspan="6">Opening (allowance{{ (int) $ledgerSummary['grace_carry_in_minutes'] > 0 ? ' + carried over' : '' }})</td>
                    <td style="{{ $num }}">{{ $minutes($allowance) }}</td>
                    <td style="{{ $cell }}"></td>
                </tr>
                @forelse ($lines as $row)
                    <tr>
                        <td style="{{ $cell }} white-space: nowrap;">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('D d M') }}</td>
                        <td style="{{ $cell }}">{{ $row['intervals_text'] ?: '—' }}</td>
                        <td style="{{ $num }}">{{ $row['extra_minutes'] ? '+'.$row['extra_minutes'] : '' }}</td>
                        <td style="{{ $num }}">{{ $row['late_minutes'] ? '−'.$row['late_minutes'] : '' }}</td>
                        <td style="{{ $num }}">{{ $row['left_early_minutes'] ? '−'.$row['left_early_minutes'] : '' }}</td>
                        <td style="{{ $num }}">{{ $row['gap_minutes'] ? '−'.$row['gap_minutes'] : '' }}</td>
                        <td style="{{ $num }} {{ $row['grace_balance_seconds'] < 0 ? 'color: rgb(220, 38, 38);' : '' }}">{{ $minutes(intdiv((int) $row['grace_balance_seconds'], 60)) }}</td>
                        <td style="{{ $cell }} opacity: .8;">{{ $row['remarks'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td style="{{ $cell }} text-align: center; opacity: .7;" colspan="8">No working days yet this month.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament::section>
