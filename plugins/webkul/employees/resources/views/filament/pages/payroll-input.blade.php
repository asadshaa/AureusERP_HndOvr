@php
    $table = 'width: 100%; border-collapse: collapse; font-size: .875rem;';
    $th = 'text-align: left; padding: .5rem .75rem; font-weight: 600; border-bottom: 1px solid rgba(127,127,127,.25); white-space: nowrap; vertical-align: bottom;';
    $thNum = $th.' text-align: right;';
    $td = 'padding: .5rem .75rem; border-bottom: 1px solid rgba(127,127,127,.12); vertical-align: top;';
    $tdNum = $td.' text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;';
    $tfoot = 'padding: .5rem .75rem; border-top: 2px solid rgba(127,127,127,.4); font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;';
    $format = function ($value, string $type): string {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money'         => number_format((float) $value, 2),
            'days', 'hours' => rtrim(rtrim(number_format((float) $value, 2), '0'), '.'),
            'date'          => \Illuminate\Support\Carbon::parse($value)->format('d M Y'),
            default         => (string) $value,
        };
    };
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($error)
        <x-filament::section>
            <p style="color: rgb(220, 38, 38);">{{ $error }}</p>
        </x-filament::section>
    @elseif ($report)
        @if ($report['draft'])
            <div role="alert" style="padding: .75rem 1rem; border-radius: .5rem; border: 1px solid rgba(217, 119, 6, .45); background: rgba(217, 119, 6, .08); color: rgb(180, 83, 9); font-weight: 500;">
                {{ $report['month_label'] }} is not finalised in the Attendance Report, so attendance, leave and overtime can still change.
                You can export anyway: the file is marked DRAFT in its title and file name.
            </div>
        @endif

        <x-filament::section
            :heading="$report['company']['name'].' — '.$report['month_label'].($report['draft'] ? ' (DRAFT)' : '')"
            description="One row per employee employed during the month. Aureus does not calculate pay, tax or EOBI: this is the input for the payroll tool. Loan and advance amounts are what falls due in the month (including any already marked as deducted)."
        >
            @if (! empty($report['finalised']))
                <p style="margin-bottom: .75rem; font-weight: 600; color: rgb(22, 163, 74);">
                    Finalised on {{ $report['finalised']['at']->format('d M Y h:i A') }}{{ $report['finalised']['by'] ? ' by '.$report['finalised']['by'] : '' }}: attendance figures come from the frozen snapshot.
                </p>
            @endif

            <p style="margin-bottom: .75rem; font-size: .875rem;">
                Instalments still due this month: <strong>{{ $report['deductions']['due_count'] }}</strong>
                ({{ number_format((float) $report['deductions']['due_amount'], 2) }}).
                @unless ($report['sensitive'])
                    CNIC, bank account and base salary are hidden (you do not have access to sensitive employee data).
                @endunless
            </p>

            <div style="overflow-x: auto;">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            @foreach ($report['columns'] as $key => $column)
                                <th style="{{ $column['type'] === 'text' || $column['type'] === 'date' ? $th : $thNum }}">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['rows'] as $row)
                            <tr>
                                @foreach ($report['columns'] as $key => $column)
                                    @if ($column['type'] === 'text' || $column['type'] === 'date')
                                        <td style="{{ $td }}{{ $key === 'name' ? ' font-weight: 500; white-space: nowrap;' : '' }}{{ $key === 'notes' ? ' min-width: 18rem; color: rgb(107, 114, 128);' : '' }}">{{ $format($row[$key] ?? null, $column['type']) }}</td>
                                    @else
                                        <td style="{{ $tdNum }}">{{ $format($row[$key] ?? null, $column['type']) }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($report['columns']) }}" style="{{ $td }}">Nobody was employed in this company during {{ $report['month_label'] }}.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($report['rows'] !== [])
                        <tfoot>
                            <tr>
                                @foreach ($report['columns'] as $key => $column)
                                    @if ($loop->first)
                                        <td style="{{ $tfoot }} text-align: left;">Total</td>
                                    @else
                                        <td style="{{ $tfoot }}">{{ array_key_exists($key, $report['totals']) ? $format($report['totals'][$key], $column['type'] === 'int' || $column['type'] === 'minutes' ? 'days' : $column['type']) : '' }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
