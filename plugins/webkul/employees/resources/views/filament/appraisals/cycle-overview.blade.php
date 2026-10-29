@php
    $stats = $this->stats();
    $statuses = \Webkul\Employee\Support\AppraisalKinds::statuses();
    $maxBand = max(1, collect($stats['bands'])->max('count'));
    $colors = ['success' => '22, 163, 74', 'info' => '59, 130, 246', 'primary' => '217, 119, 6', 'warning' => '245, 158, 11', 'danger' => '220, 38, 38', 'gray' => '107, 114, 128'];
    $grid = 'display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));';
    $muted = 'font-size: .8rem; opacity: .75;';
@endphp

<x-filament-panels::page>
    <div style="{{ $grid }}">
        <x-filament::section heading="Completion" :description="$stats['done'].' of '.$stats['total'].' appraisals reviewed'">
            <div style="height: .75rem; border-radius: 9999px; background: rgba(127,127,127,.18); overflow: hidden;">
                <div style="height: 100%; width: {{ $stats['percent'] }}%; background: rgb(22, 163, 74);"></div>
            </div>
            <div style="margin-top: .25rem; font-weight: 600;">{{ $stats['percent'] }}%</div>

            <table style="width: 100%; margin-top: .75rem; font-size: .875rem;">
                @foreach ($statuses as $status => $label)
                    <tr>
                        <td style="padding: .2rem 0;">
                            <x-filament::badge :color="\Webkul\Employee\Support\AppraisalKinds::statusColor($status)" style="display: inline-flex;">{{ $label }}</x-filament::badge>
                        </td>
                        <td style="padding: .2rem 0; text-align: right; font-variant-numeric: tabular-nums;">{{ $stats['statuses'][$status] ?? 0 }}</td>
                    </tr>
                @endforeach
            </table>
        </x-filament::section>

        <x-filament::section heading="Rating distribution" :description="$stats['average'] !== null ? 'Average final score '.number_format($stats['average'], 2) : 'No reviewed appraisals yet'">
            <div style="display: grid; gap: .5rem;">
                @foreach ($stats['bands'] as $band)
                    <div>
                        <div style="display: flex; justify-content: space-between; font-size: .875rem;">
                            <span>{{ $band['label'] }}</span>
                            <span style="font-variant-numeric: tabular-nums; font-weight: 600;">{{ $band['count'] }}</span>
                        </div>
                        <div style="height: .6rem; border-radius: 9999px; background: rgba(127,127,127,.12); overflow: hidden;">
                            <div style="height: 100%; width: {{ (int) round($band['count'] * 100 / $maxBand) }}%; background: rgb({{ $colors[$band['color']] ?? $colors['gray'] }});"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($stats['unbanded'] > 0)
                <p style="margin-top: .5rem; {{ $muted }}">{{ $stats['unbanded'] }} reviewed without a band (overall rating only).</p>
            @endif
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
