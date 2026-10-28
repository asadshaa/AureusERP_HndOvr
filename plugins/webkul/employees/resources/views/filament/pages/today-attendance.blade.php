@php
    $grid = 'display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .75rem;';
    $card = 'border: 1px solid rgba(127,127,127,.2); border-radius: .75rem; padding: .875rem 1rem;';
    $label = 'font-size: .75rem; font-weight: 600; opacity: .75;';
    $value = 'font-size: 1.5rem; font-weight: 700; margin-top: .25rem; font-variant-numeric: tabular-nums;';
    $sub = 'font-size: .7rem; opacity: .6; margin-top: .125rem;';
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($error)
        <x-filament::section>
            <p style="color: rgb(220, 38, 38);">{{ $error }}</p>
        </x-filament::section>
    @elseif ($report)
        @php($c = $report['counts'])

        @if ($report['off_day'])
            <div style="border: 1px solid rgba(59,130,246,.35); background: rgba(59,130,246,.08); border-radius: .75rem; padding: .75rem 1rem; font-size: .875rem;">
                <strong>{{ $report['date_label'] }} is
                    {{ $report['off_day']['type'] === 'holiday' ? 'a public holiday: '.$report['off_day']['name'] : 'a weekend day' }}.</strong>
                Nobody is expected in; anyone who checked in is shown as worked.
            </div>
        @endif

        <x-filament::section
            :heading="$report['company']['name'].' — '.$report['date_label']"
            :description="$report['is_today'] ? 'Live for today; reload the page for the latest punches. Late = first check-in after the office start plus the grace minutes. On leave counts approved time off only.' : 'Late = first check-in after the office start plus the grace minutes. On leave counts approved time off only.'"
        >
            <div style="{{ $grid }}">
                <div style="{{ $card }}">
                    <div style="{{ $label }}">Active employees</div>
                    <div style="{{ $value }}">{{ $c['total'] }}</div>
                    <div style="{{ $sub }}">Employed on this day</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }} color: rgb(5,150,105);">Present</div>
                    <div style="{{ $value }} color: rgb(5,150,105);">{{ $c['present'] }}</div>
                    <div style="{{ $sub }}">Checked in</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }} color: rgb(217,119,6);">Late</div>
                    <div style="{{ $value }} color: rgb(217,119,6);">{{ $c['late'] }}</div>
                    <div style="{{ $sub }}">After start + grace</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }} color: rgb(220,38,38);">Absent</div>
                    <div style="{{ $value }} color: rgb(220,38,38);">{{ $c['absent'] }}</div>
                    <div style="{{ $sub }}">Working day, no check-in, no approved leave</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }} color: rgb(37,99,235);">On leave</div>
                    <div style="{{ $value }} color: rgb(37,99,235);">{{ $c['on_leave'] }}</div>
                    <div style="{{ $sub }}">Approved time off</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }} color: rgb(37,99,235);">On-Duty</div>
                    <div style="{{ $value }} color: rgb(37,99,235);">{{ $c['on_duty'] }}</div>
                    <div style="{{ $sub }}">Approved OD</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }}">Remote / GPS office</div>
                    <div style="{{ $value }}">{{ $c['remote'] }} / {{ $c['gps_office'] }}</div>
                    <div style="{{ $sub }}">Remote check-ins / GPS at workplace</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }} color: rgb(220,38,38);">Missing check-out</div>
                    <div style="{{ $value }} color: rgb(220,38,38);">{{ $c['missing_check_out'] }}</div>
                    <div style="{{ $sub }}">{{ $report['is_today'] ? 'Still open after office end' : 'Never checked out' }}</div>
                </div>
                <div style="{{ $card }}">
                    <div style="{{ $label }} color: rgb(147,51,234);">Needs review</div>
                    <div style="{{ $value }} color: rgb(147,51,234);">{{ $c['needs_review'] }}</div>
                    <div style="{{ $sub }}">Flagged punches this day</div>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
