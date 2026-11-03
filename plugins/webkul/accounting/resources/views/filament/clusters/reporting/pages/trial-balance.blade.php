<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        @php
            $data = $this->trialBalanceData;
            $completeness = $data['company'] ? $this->completeness : null;
            $fmt = fn ($v) => (abs((float) $v) < 0.005) ? '-' : number_format((float) $v, 2);
            $cols = ['opening_debit','opening_credit','movement_debit','movement_credit','adjustment_debit','adjustment_credit','closing_debit','closing_credit'];
        @endphp

        @if (! $data['company'])
            <x-filament::section>Select a company to generate the Trial Balance.</x-filament::section>
        @else
            <x-filament::section compact>
                <span class="font-semibold">Report completeness:</span>
                {{ $completeness['status']->value }} · {{ $completeness['provisional_label'] }}
            </x-filament::section>
            <x-filament::section>
                <x-slot name="heading">
                    Trial Balance — {{ $data['company']->name }}
                </x-slot>
                <x-slot name="description">
                    Currency mode: {{ $data['currency_mode'] ?? 'company' }};
                    conversion: {{ $data['conversion_status'] ?? 'complete' }};
                    basis: {{ $data['rate_basis'] ?? '' }}.<br>
                    {{ \Carbon\Carbon::parse($data['from'])->format('M d, Y') }}
                    to {{ \Carbon\Carbon::parse($data['to'])->format('M d, Y') }} · posted ledger lines
                </x-slot>

                @foreach (($data['warnings'] ?? []) as $warning)
                    <p>{{ $warning }}</p>
                @endforeach

                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/5">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50/60 dark:bg-white/5 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left" rowspan="2">Code</th>
                                <th class="px-3 py-2 text-left" rowspan="2">Account</th>
                                <th class="px-3 py-2 text-left" rowspan="2">Currency</th>
                                <th class="px-3 py-2 text-center" colspan="2">Opening</th>
                                <th class="px-3 py-2 text-center" colspan="2">Movement</th>
                                <th class="px-3 py-2 text-center" colspan="2">Adjustment</th>
                                <th class="px-3 py-2 text-center" colspan="2">Closing</th>
                            </tr>
                            <tr>
                                @foreach (['Debit','Credit','Debit','Credit','Debit','Credit','Debit','Credit'] as $h)
                                    <th class="px-3 py-1 text-right">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($data['rows'] as $row)
                                <tr @class(['bg-gray-50/40 dark:bg-white/5 font-semibold' => $row['is_group']])>
                                    <td class="px-3 py-1.5 whitespace-nowrap">{{ $row['code'] }}</td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">{{ $row['name'] }}@if ($row['unclassified'] ?? false) <span class="text-warning-600 text-xs">(unclassified / not linked to this company)</span>@endif</td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">{{ $row['currency'] ?? $data['company']?->currency?->code }}</td>
                                    @foreach ($cols as $c)
                                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $fmt($row[$c]) }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="11" class="px-3 py-4 text-center text-gray-500">No ledger activity for this selection.</td></tr>
                            @endforelse
                        </tbody>
                        @if (! empty($data['currency_totals']))
                            <tfoot class="border-t-2 border-gray-300 dark:border-white/10 font-bold">
                                @foreach ($data['currency_totals'] as $currency => $currencyTotal)
                                @php($data['totals'] = $currencyTotal)
                                <tr>
                                    <td class="px-3 py-2" colspan="2">Total</td>
                                    <td class="px-3 py-2">{{ $currency }}</td>
                                    @foreach ($cols as $c)
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($data['totals'][$c] ?? 0) }}</td>
                                    @endforeach
                                </tr>
                                <tr @class(['text-danger-600' => abs((float) ($data['totals']['difference'] ?? 0)) > 0.005, 'text-success-600' => abs((float) ($data['totals']['difference'] ?? 0)) <= 0.005])>
                                    <td class="px-3 py-1" colspan="8">Difference (Closing Debit − Closing Credit)</td>
                                    <td class="px-3 py-1 text-right tabular-nums" colspan="2">{{ $fmt($data['totals']['difference'] ?? 0) }}</td>
                                </tr>
                                @endforeach
                            </tfoot>
                        @endif
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
