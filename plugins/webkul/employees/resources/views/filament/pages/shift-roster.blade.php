@php
    $ink = function (?string $hex): string {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) !== 6) {
            return 'inherit';
        }
        [$red, $green, $blue] = array_map('hexdec', str_split($hex, 2));

        return (0.299 * $red + 0.587 * $green + 0.114 * $blue) < 140 ? '#ffffff' : '#111827';
    };
    $th = 'padding: .35rem .4rem; font-size: .7rem; font-weight: 600; text-align: center; white-space: nowrap; border-bottom: 1px solid rgba(127,127,127,.25);';
    $name = 'padding: .35rem .6rem; font-size: .8125rem; white-space: nowrap; border-bottom: 1px solid rgba(127,127,127,.12); position: sticky; left: 0; background: var(--roster-bg); z-index: 1;';
    $td = 'padding: 2px; border-bottom: 1px solid rgba(127,127,127,.12); text-align: center;';
    $chip = 'display: block; width: 100%; min-width: 2.25rem; border-radius: .375rem; padding: .3rem .2rem; font-size: .75rem; font-weight: 700; line-height: 1.1; border: 0;';
@endphp

<x-filament-panels::page>
    <style>
        .roster-wrap { --roster-bg: #ffffff; }
        .dark .roster-wrap { --roster-bg: rgb(24 24 27); }
    </style>

    {{ $this->form }}

    @if ($error)
        <x-filament::section>
            <p style="color: rgb(220, 38, 38);">{{ $error }}</p>
        </x-filament::section>
    @elseif ($calendar)
        <x-filament::section
            :heading="$calendar['company']['name'].' — '.\Illuminate\Support\Carbon::parse($calendar['from'])->format('d M').' to '.\Illuminate\Support\Carbon::parse($calendar['to'])->format('d M Y')"
            description="Click a day of someone you manage to change their shift, give a day off, swap with a colleague or go back to the roster. A dotted outline marks a day changed by hand. Overnight shifts count on the day they start."
        >
            <div style="display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: .75rem;">
                <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-left" wire:click="previousPeriod">Earlier</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="thisWeek">This week</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-right" icon-position="after" wire:click="nextPeriod">Later</x-filament::button>

                <div data-testid="roster-legend" style="display: flex; flex-wrap: wrap; gap: .4rem; margin-left: auto; font-size: .75rem;">
                    @foreach ($calendar['shifts'] as $shift)
                        @continue(! $shift['active'])
                        <span title="{{ $shift['name'] }} {{ $shift['hours'] }}{{ $shift['break_minutes'] ? ', unpaid break '.$shift['break_minutes'].' min' : '' }}"
                              style="display: inline-flex; align-items: center; gap: .3rem; border: 1px solid rgba(127,127,127,.25); border-radius: 999px; padding: .1rem .5rem .1rem .15rem;">
                            <span style="display: inline-block; min-width: 1.4rem; text-align: center; border-radius: 999px; padding: 0 .3rem; font-weight: 700; background: {{ $shift['color'] }}; color: {{ $ink($shift['color']) }};">{{ $shift['code'] }}</span>
                            {{ $shift['name'] }} {{ $shift['hours'] }}
                        </span>
                    @endforeach
                    <span style="display: inline-flex; align-items: center; gap: .3rem; border: 1px solid rgba(127,127,127,.25); border-radius: 999px; padding: .1rem .5rem;">Off = day off</span>
                </div>
            </div>

            @if ($calendar['rows'] === [])
                <p style="opacity: .7; font-size: .875rem;">No employees to show for these filters.</p>
            @else
                <div class="roster-wrap" style="overflow-x: auto;">
                    <table data-testid="roster-grid" style="border-collapse: separate; border-spacing: 0; width: 100%;">
                        <thead>
                            <tr>
                                <th style="{{ $th }} text-align: left; position: sticky; left: 0; background: var(--roster-bg); z-index: 2;">Employee</th>
                                @foreach ($calendar['dates'] as $date)
                                    <th style="{{ $th }} {{ $date['weekend'] ? 'opacity: .65;' : '' }} {{ $date['today'] ? 'color: rgb(37,99,235);' : '' }}">
                                        {{ $date['day'] }}<br><span style="font-weight: 400;">{{ $date['label'] }}</span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($calendar['rows'] as $row)
                                <tr wire:key="roster-{{ $row['employee_id'] }}">
                                    <td style="{{ $name }}">
                                        <div style="font-weight: 600;">{{ $row['name'] }}</div>
                                        <div style="font-size: .7rem; opacity: .6;">{{ trim($row['code'].($row['department'] !== '' ? ' · '.$row['department'] : ''), ' ·') }}</div>
                                    </td>
                                    @foreach ($calendar['dates'] as $date)
                                        @php
                                            $cell = $row['cells'][$date['date']];
                                            $style = $chip.($cell['off']
                                                ? ' background: rgba(127,127,127,.12); color: inherit; font-weight: 500;'
                                                : ' background: '.($cell['color'] ?? 'rgba(127,127,127,.25)').'; color: '.$ink($cell['color']).';')
                                                .($cell['override'] ? ' outline: 2px dashed rgba(220,38,38,.8); outline-offset: -2px;' : '')
                                                .($row['can_edit'] ? ' cursor: pointer;' : ' cursor: default;');
                                        @endphp
                                        <td style="{{ $td }} {{ $date['today'] ? 'background: rgba(37,99,235,.06);' : '' }}">
                                            @if ($row['can_edit'])
                                                <button type="button" style="{{ $style }}" title="{{ $cell['title'] }}"
                                                        wire:click="mountAction('editDay', @js(['employee' => $row['employee_id'], 'date' => $date['date']]))">
                                                    {{ $cell['code'] }}
                                                </button>
                                            @else
                                                <span style="{{ $style }}" title="{{ $cell['title'] }}">{{ $cell['code'] }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif

</x-filament-panels::page>
