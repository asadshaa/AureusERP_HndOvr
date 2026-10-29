@php
    $batch = $this->previewBatch();
    $grid = 'display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .625rem;';
    $card = 'border: 1px solid rgba(127,127,127,.22); border-radius: .75rem; padding: .625rem .875rem;';
    $label = 'font-size: .72rem; font-weight: 600; opacity: .75;';
    $value = 'font-size: 1.35rem; font-weight: 700; margin-top: .125rem; font-variant-numeric: tabular-nums;';
    $table = 'width: 100%; border-collapse: collapse; font-size: .8rem;';
    $th = 'text-align: left; padding: .4rem .5rem; border-bottom: 1px solid rgba(127,127,127,.3); font-weight: 600; white-space: nowrap;';
    $td = 'padding: .4rem .5rem; border-bottom: 1px solid rgba(127,127,127,.15); vertical-align: top;';
    $num = $td.' text-align: right; font-variant-numeric: tabular-nums;';
    $muted = 'font-size: .8rem; opacity: .7;';
    $tones = ['success' => 'rgb(5,150,105)', 'danger' => 'rgb(220,38,38)', 'warning' => 'rgb(217,119,6)', 'info' => 'rgb(37,99,235)', 'gray' => 'inherit'];
@endphp

<x-filament-panels::page>
    <form wire:submit="preview">
        {{ $this->form }}

        <div style="margin-top: 1rem; display: flex; flex-wrap: wrap; gap: .75rem; align-items: center;">
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:target="preview" wire:loading.attr="disabled">
                Preview
            </x-filament::button>
            <span style="{{ $muted }}">
                Pairing: <strong>{{ $this->pairingMode()->label() }}</strong> (change it with the Pairing button above).
                Device times are read as {{ app(\Webkul\Employee\Services\Attendance\DeviceAttendanceImportService::class)->timezone() }} time.
            </span>
        </div>
    </form>

    @if ($batch)
        @php
            $summary = $batch->summary ?? [];
            $counts = $summary['counts'] ?? [];
            $rows = $this->previewRows($batch);
            $okCount = (int) ($counts[\Webkul\Employee\Enums\DeviceImportRowStatus::Ok->value] ?? 0);
            $formats = ['template' => 'Aureus template', 'zkteco' => 'ZKTeco-style export', 'zkteco_attlog' => 'ZKTeco attlog (no header)'];
        @endphp

        <x-filament::section
            :heading="'Preview: '.$batch->file_name"
            :description="($formats[$batch->file_format] ?? 'Device file').' · dates read as '.($batch->date_order === 'mdy' ? 'month/day/year' : 'day/month/year').' · '.$batch->pairingMode()->label().' · '.(($summary['date_from'] ?? null) ? \Illuminate\Support\Carbon::parse($summary['date_from'])->format('d M Y').' to '.\Illuminate\Support\Carbon::parse($summary['date_to'])->format('d M Y') : 'no usable dates')"
        >
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <div style="{{ $grid }}">
                    <div style="{{ $card }}">
                        <div style="{{ $label }}">Rows in the file</div>
                        <div style="{{ $value }}">{{ $batch->total_rows }}</div>
                    </div>
                    @foreach (\Webkul\Employee\Enums\DeviceImportRowStatus::cases() as $status)
                        @if (($counts[$status->value] ?? 0) > 0)
                            <div style="{{ $card }}">
                                <div style="{{ $label }} color: {{ $tones[$status->color()] ?? 'inherit' }};">{{ $status === \Webkul\Employee\Enums\DeviceImportRowStatus::Ok ? 'Ready to import' : $status->label() }}</div>
                                <div style="{{ $value }} color: {{ $tones[$status->color()] ?? 'inherit' }};">{{ $counts[$status->value] }}</div>
                            </div>
                        @endif
                    @endforeach
                    <div style="{{ $card }}">
                        <div style="{{ $label }}">Days to write</div>
                        <div style="{{ $value }}">{{ $summary['days'] ?? 0 }}</div>
                    </div>
                    <div style="{{ $card }}">
                        <div style="{{ $label }} color: {{ $tones['warning'] }};">Days needing review</div>
                        <div style="{{ $value }} color: {{ $tones['warning'] }};">{{ $summary['review_days'] ?? 0 }}</div>
                    </div>
                </div>

                @foreach ($summary['warnings'] ?? [] as $warning)
                    <div style="border: 1px solid rgba(217,119,6,.4); background: rgba(217,119,6,.08); border-radius: .5rem; padding: .5rem .75rem; font-size: .8rem;">{{ $warning }}</div>
                @endforeach

                <p style="{{ $muted }}">
                    Columns used:
                    @foreach ($summary['columns'] ?? [] as $role => $header)
                        <span style="white-space: nowrap;"><strong>{{ $header }}</strong> → {{ ['identifier' => 'device ID', 'code' => 'employee code', 'name' => 'name', 'datetime' => 'date-time', 'date' => 'date', 'time' => 'time', 'direction' => 'in / out'][$role] ?? $role }}</span>@if (! $loop->last), @endif
                    @endforeach
                    · Days needing review have an odd number of punches: the last check-out stays empty and the day goes to Needs Review. Days with manual or GPS entries are not changed.
                </p>

                @if (! empty($summary['employees']))
                    <div style="overflow-x: auto;">
                        <table style="{{ $table }}">
                            <thead>
                                <tr>
                                    <th style="{{ $th }}">Employee</th>
                                    <th style="{{ $th }}">Code</th>
                                    <th style="{{ $th }} text-align: right;">Punches</th>
                                    <th style="{{ $th }} text-align: right;">Days</th>
                                    <th style="{{ $th }} text-align: right;">New days</th>
                                    <th style="{{ $th }} text-align: right;">Added to earlier import</th>
                                    <th style="{{ $th }} text-align: right;">Need review</th>
                                    <th style="{{ $th }} text-align: right;">Not changed (manual)</th>
                                    <th style="{{ $th }} text-align: right;">Problem rows</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($summary['employees'] as $entry)
                                    <tr>
                                        <td style="{{ $td }} font-weight: 600;">{{ $entry['name'] }}</td>
                                        <td style="{{ $td }}">{{ $entry['code'] ?? '—' }}</td>
                                        <td style="{{ $num }}">{{ $entry['rows'] }}</td>
                                        <td style="{{ $num }}">{{ $entry['days'] }}</td>
                                        <td style="{{ $num }}">{{ $entry['new_days'] }}</td>
                                        <td style="{{ $num }}">{{ $entry['merged_days'] }}</td>
                                        <td style="{{ $num }} {{ $entry['review_days'] > 0 ? 'color: '.$tones['warning'].';' : '' }}">{{ $entry['review_days'] }}</td>
                                        <td style="{{ $num }} {{ $entry['flagged_days'] > 0 ? 'color: '.$tones['warning'].';' : '' }}">{{ $entry['flagged_days'] }}</td>
                                        <td style="{{ $num }} {{ $entry['problem_rows'] > 0 ? 'color: '.$tones['danger'].';' : '' }}">{{ $entry['problem_rows'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div>
                    <div style="{{ $label }} margin-bottom: .375rem;">
                        Rows (problems first{{ $batch->total_rows > $rows->count() ? '; first '.$rows->count().' of '.$batch->total_rows.', all of them are in the Excel report' : '' }})
                    </div>
                    <div style="overflow-x: auto; max-height: 28rem; overflow-y: auto;">
                        <table style="{{ $table }}">
                            <thead>
                                <tr>
                                    <th style="{{ $th }}">Row</th>
                                    <th style="{{ $th }}">Device ID / code</th>
                                    <th style="{{ $th }}">Name (file)</th>
                                    <th style="{{ $th }}">Date / time (file)</th>
                                    <th style="{{ $th }}">In / out</th>
                                    <th style="{{ $th }}">Employee</th>
                                    <th style="{{ $th }}">Status</th>
                                    <th style="{{ $th }}">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr wire:key="device-import-row-{{ $row->id }}">
                                        <td style="{{ $num }}">{{ $row->row_number }}</td>
                                        <td style="{{ $td }}">{{ collect([$row->identifier, $row->employee_code])->filter()->unique()->implode(' / ') ?: '—' }}</td>
                                        <td style="{{ $td }}">{{ $row->employee_name ?? '—' }}</td>
                                        <td style="{{ $td }} white-space: nowrap;">{{ $row->raw_datetime ?? '—' }}</td>
                                        <td style="{{ $td }}">{{ $row->direction ? strtoupper($row->direction) : ($row->raw_direction ?? '—') }}</td>
                                        <td style="{{ $td }}">{{ $row->employee ? $row->employee->name.($row->employee->employee_number ? ' ('.$row->employee->employee_number.')' : '') : '—' }}</td>
                                        <td style="{{ $td }}">
                                            <x-filament::badge :color="$row->status->color()" size="sm">{{ $row->status === \Webkul\Employee\Enums\DeviceImportRowStatus::Ok ? 'OK' : $row->status->label() }}</x-filament::badge>
                                        </td>
                                        <td style="{{ $td }} {{ $muted }}">{{ $row->message }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;">
                    {{ $this->importAction }}
                    {{ $this->previewReportAction }}
                    {{ $this->discardAction }}
                    @if ($okCount === 0)
                        <span style="{{ $muted }}">No row can be imported. Fix the file (or set the employees' device IDs) and preview it again.</span>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
