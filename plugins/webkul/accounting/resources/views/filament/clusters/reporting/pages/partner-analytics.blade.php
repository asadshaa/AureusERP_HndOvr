<x-filament-panels::page>
    <form wire:submit="$refresh">
        {{ $this->form }}
    </form>

    @php($analytics = $this->analytics)
    @php($daysOutstanding = $analytics['party_type'] === 'customer' ? $analytics['dso'] : $analytics['dpo'])

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach([
            'Parties' => number_format($analytics['party_count']),
            'Documents' => number_format($analytics['document_count']),
            'Document value' => number_format($analytics['document_value'], 2),
            'Outstanding' => number_format($analytics['outstanding'], 2),
            'Overdue' => number_format($analytics['overdue'], 2),
            'Overdue rate' => number_format($analytics['overdue_rate'], 2).'%',
            'Payments' => number_format($analytics['payment_value'], 2).' ('.$analytics['payment_count'].')',
            'Top-party concentration' => number_format($analytics['top_concentration'], 2).'%',
            ($analytics['party_type'] === 'customer' ? 'DSO (days)' : 'DPO (days)') => $daysOutstanding === null ? '-' : number_format($daysOutstanding, 1),
        ] as $label => $value)
            <x-filament::section compact>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">{{ $analytics['party_type'] === 'customer' ? 'Receivables' : 'Payables' }} ageing as of {{ $analytics['aging_as_of'] }}</x-slot>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                <thead><tr class="text-left text-xs uppercase text-gray-500"><th class="py-2 text-right">Not due</th><th class="py-2 text-right">0–30</th><th class="py-2 text-right">31–60</th><th class="py-2 text-right">61–90</th><th class="py-2 text-right">90+</th><th class="py-2 text-right">Total</th></tr></thead>
                <tbody>
                    <tr>
                        @foreach (['current', '0_30', '31_60', '61_90', '90_plus', 'total'] as $bucket)
                            <td @class(['py-2 text-right tabular-nums', 'font-semibold' => $bucket === 'total'])>{{ number_format($analytics['aging'][$bucket], 2) }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Top {{ $analytics['party_type'] === 'customer' ? 'customers' : 'vendors' }}</x-slot>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead><tr class="text-left text-xs uppercase text-gray-500"><th class="py-2">Name</th><th class="py-2 text-right">Documents</th><th class="py-2 text-right">Value</th><th class="py-2 text-right">Outstanding</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse($analytics['top_parties'] as $party)
                        <tr><td class="py-2">{{ $party['name'] }}</td><td class="py-2 text-right">{{ $party['document_count'] }}</td><td class="py-2 text-right">{{ number_format($party['document_value'], 2) }}</td><td class="py-2 text-right">{{ number_format($party['outstanding'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-500">No posted documents in this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Monthly trend</x-slot>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead><tr class="text-left text-xs uppercase text-gray-500"><th class="py-2">Month</th><th class="py-2 text-right">Documents</th><th class="py-2 text-right">Value</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse($analytics['trends'] as $trend)
                        <tr><td class="py-2">{{ $trend['period'] }}</td><td class="py-2 text-right">{{ $trend['document_count'] }}</td><td class="py-2 text-right">{{ number_format($trend['document_value'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="py-6 text-center text-gray-500">No trend data in this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
