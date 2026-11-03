<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        @if ($this->previewData)
            @php $p = $this->previewData; $prov = $p['provisional']; @endphp

            <x-filament::section>
                <x-slot name="heading">Preview (dry-run — nothing imported yet)</x-slot>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 text-sm">
                    <div><div class="text-gray-500">Leaf accounts</div><div class="text-lg font-bold">{{ $p['leaves'] }}</div></div>
                    <div><div class="text-gray-500">Group nodes</div><div class="text-lg font-bold">{{ $p['groups'] }}</div></div>
                    <div><div class="text-gray-500">Warnings</div><div class="text-lg font-bold">{{ count($p['warnings']) }}</div></div>
                    <div><div class="text-gray-500">Blocking errors</div><div class="text-lg font-bold {{ $p['has_errors'] ? 'text-danger-600' : 'text-success-600' }}">{{ $p['has_errors'] ? 'Yes' : 'No' }}</div></div>
                </div>

                <div class="mt-4 overflow-x-auto rounded border border-gray-200 dark:border-white/5">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50/60 dark:bg-white/5 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Provisional totals (from sheet)</th>
                                <th class="px-3 py-2 text-right">Debit</th>
                                <th class="px-3 py-2 text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5 tabular-nums">
                            @foreach (['opening' => 'Opening', 'movement' => 'Movement', 'adjustment' => 'Adjustment', 'closing' => 'Closing'] as $k => $label)
                                <tr>
                                    <td class="px-3 py-1.5">{{ $label }}</td>
                                    <td class="px-3 py-1.5 text-right">{{ number_format($prov[$k.'_debit'], 2) }}</td>
                                    <td class="px-3 py-1.5 text-right">{{ number_format($prov[$k.'_credit'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-gray-500">The provisional totals come from the sheet. The official Trial Balance is always generated from posted ledger lines after import.</p>
            </x-filament::section>

            @if (count($p['types'] ?? []))
                @php $typeLabels = $this->typeLabels(); @endphp
                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">Account types ({{ count($p['types']) }}) — review the mapped type of each account</x-slot>
                    <x-slot name="description">Each account gets the suggested type unless you choose another one here. Only the types its Nature (P&amp;L or B/S) allows are offered. Re-run the preview after changing types.</x-slot>

                    <div class="overflow-x-auto rounded border border-gray-200 dark:border-white/5">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50/60 dark:bg-white/5 text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Code</th>
                                    <th class="px-3 py-2 text-left">Title</th>
                                    <th class="px-3 py-2 text-left">Classification</th>
                                    <th class="px-3 py-2 text-left">Suggested</th>
                                    <th class="px-3 py-2 text-left">Import as</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($p['types'] as $index => $choice)
                                    <tr wire:key="coa-type-{{ $index }}">
                                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $choice['code'] }}</td>
                                        <td class="px-3 py-1.5">{{ $choice['title'] }}</td>
                                        <td class="px-3 py-1.5 text-xs text-gray-500">{{ $choice['path'] }}</td>
                                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $typeLabels[$choice['suggested']] ?? $choice['suggested'] }}</td>
                                        <td class="px-3 py-1.5">
                                            <select wire:model="typeOverrides.{{ $index }}" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                                @foreach ($choice['allowed'] as $type)
                                                    <option value="{{ $type }}">{{ $typeLabels[$type] ?? $type }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif

            @if (count($p['warnings']))
                <x-filament::section collapsible>
                    <x-slot name="heading"><span class="text-warning-600">{{ count($p['warnings']) }} warning(s) — review before importing</span></x-slot>
                    <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($this->previewWarnings() as $w)
                            @php $isError = ($w['severity'] ?? '') === 'error'; @endphp
                            <li>
                                <span @class(['font-semibold', 'text-danger-600' => $isError, 'text-warning-600' => ! $isError])>{{ strtoupper($w['severity'] ?? 'warning') }}</span>
                                {{ $w['message'] ?? '' }}
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
