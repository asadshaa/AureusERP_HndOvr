<x-filament-panels::page>
    {{ $this->form }}

    @if ($preview)
        <x-filament::section heading="Conversion preview">
            <div class="flex flex-wrap items-center gap-2 p-3 rounded-lg bg-gray-50/80 dark:bg-white/[0.03] border border-gray-200/80 dark:border-white/10 mb-4 text-xs">
                <span class="font-bold text-gray-950 dark:text-white">{{ $preview['bank'] }}</span>
                <span class="text-gray-400 dark:text-gray-600">&bull;</span>
                <span class="text-gray-600 dark:text-gray-400">Account: <strong class="text-gray-900 dark:text-gray-200 font-semibold">{{ $preview['bank_account_number'] }}</strong></span>
                <span class="text-gray-400 dark:text-gray-600">&bull;</span>
                <span class="text-gray-600 dark:text-gray-400">Period: <strong class="text-gray-900 dark:text-gray-200 font-semibold">{{ $preview['period'] }}</strong></span>
                <span class="text-gray-400 dark:text-gray-600">&bull;</span>
                <span class="text-gray-600 dark:text-gray-400">Currency: <strong class="text-gray-900 dark:text-gray-200 font-semibold">{{ $preview['selected_currency'] }}</strong></span>
                <x-filament::badge color="info" size="xs">
                    {{ $preview['row_count'] }} transactions{{ $preview['truncated'] ? ' (first 1,000 shown)' : '' }}
                </x-filament::badge>
            </div>

            @foreach ($preview['validation_errors'] as $error)
                <p class="text-xs text-rose-600 dark:text-rose-400 mb-1">{{ $error['message'] }}</p>
            @endforeach
            @foreach ($preview['missing_rates'] as $missingRate)
                <p class="text-xs text-amber-600 dark:text-amber-400 mb-1">{{ $missingRate }}</p>
            @endforeach

            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10 mt-3">
                <table class="w-full text-left text-xs divide-y divide-gray-200 dark:divide-white/10">
                    <thead class="bg-gray-50/80 dark:bg-white/[0.04] text-gray-600 dark:text-gray-400 font-semibold uppercase text-3xs tracking-wider">
                        <tr>
                            <th class="px-3.5 py-2.5">Date</th>
                            <th class="px-3.5 py-2.5">Description</th>
                            <th class="px-3.5 py-2.5 text-right">Original Amount</th>
                            <th class="px-3.5 py-2.5 text-center">Exchange Rate</th>
                            <th class="px-3.5 py-2.5 text-right">Company Amount</th>
                            <th class="px-3.5 py-2.5 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5 bg-white dark:bg-gray-900">
                        @foreach ($preview['rows'] as $row)
                            @php
                                $origNum = (float) $row['original_amount'];
                                $compNum = (float) $row['company_amount'];
                                $rateNum = $row['exchange_rate'] !== null ? (float) $row['exchange_rate'] : null;
                            @endphp
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
                                <td class="px-3.5 py-2.5 whitespace-nowrap font-medium text-gray-900 dark:text-gray-200">{{ $row['date'] }}</td>
                                <td class="px-3.5 py-2.5 text-gray-800 dark:text-gray-300 max-w-xs truncate" title="{{ $row['description'] }}">{{ $row['description'] }}</td>
                                <td class="px-3.5 py-2.5 text-right whitespace-nowrap font-bold {{ $origNum >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                    {{ $origNum >= 0 ? '+' : '' }}{{ number_format($origNum, 2) }} <span class="text-3xs font-normal text-gray-400">{{ $row['original_currency'] }}</span>
                                </td>
                                <td class="px-3.5 py-2.5 text-center whitespace-nowrap text-gray-500 dark:text-gray-400 font-mono text-3xs">
                                    {{ $rateNum !== null ? number_format($rateNum, 4) : '—' }}
                                </td>
                                <td class="px-3.5 py-2.5 text-right whitespace-nowrap font-bold {{ $compNum >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                    {{ $compNum >= 0 ? '+' : '' }}{{ number_format($compNum, 2) }} <span class="text-3xs font-normal text-gray-400">{{ $row['company_currency'] }}</span>
                                </td>
                                <td class="px-3.5 py-2.5 text-center whitespace-nowrap">
                                    <x-filament::badge :color="$row['status'] === 'complete' ? 'success' : 'danger'" size="xs">
                                        {{ ucfirst($row['status']) }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @php
            $fsTagHasActivity = $preview['fs_tag_summary']['recognized'] > 0 || $preview['fs_tag_summary']['unrecognized'] > 0;
            $fsTagMissingColumnIsNoteworthy = $preview['company_uses_fs_tags'] && ! $preview['fs_tag_column_found'];
        @endphp
        @if ($fsTagHasActivity || $fsTagMissingColumnIsNoteworthy)
            <x-filament::section heading="FS Tag check" class="mt-6">
                @if ($fsTagHasActivity)
                    <div class="flex flex-wrap gap-2 mb-4">
                        <x-filament::badge color="gray">
                            {{ $preview['row_count'] }} transactions
                        </x-filament::badge>
                        <x-filament::badge color="success">
                            {{ $preview['fs_tag_summary']['recognized'] }} recognized
                        </x-filament::badge>
                        <x-filament::badge color="danger">
                            {{ $preview['fs_tag_summary']['unrecognized'] }} unrecognized
                        </x-filament::badge>
                        <x-filament::badge color="gray">
                            {{ $preview['fs_tag_summary']['none'] }} untagged
                        </x-filament::badge>
                    </div>
                @endif

                @if ($fsTagMissingColumnIsNoteworthy)
                    <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                        No "FS Tag" column was recognized in this file, so none of these transactions will be tagged.
                        If the file has one, check its header spelling.
                    </p>
                @endif

                @if ($preview['fs_tag_summary']['unrecognized'] > 0)
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10 mt-3">
                        <table class="w-full text-left text-xs divide-y divide-gray-200 dark:divide-white/10">
                            <thead class="bg-gray-50/80 dark:bg-white/[0.04] text-gray-600 dark:text-gray-400 font-semibold uppercase text-3xs tracking-wider">
                                <tr>
                                    <th class="px-3.5 py-2.5">Description</th>
                                    <th class="px-3.5 py-2.5">Tag in file</th>
                                    <th class="px-3.5 py-2.5 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5 bg-white dark:bg-gray-900">
                                @foreach ($preview['rows'] as $row)
                                    @if ($row['fs_tag_status'] !== 'none')
                                        <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
                                            <td class="px-3.5 py-2.5 text-gray-900 dark:text-gray-200 font-medium">{{ $row['description'] }}</td>
                                            <td class="px-3.5 py-2.5 text-gray-700 dark:text-gray-300">
                                                <span class="font-mono text-xs px-2 py-0.5 rounded bg-gray-100 dark:bg-white/10 text-gray-800 dark:text-gray-200 border border-gray-200/50 dark:border-white/5">
                                                    {{ $row['fs_tag_code'] }}
                                                </span>
                                            </td>
                                            <td class="px-3.5 py-2.5 text-right">
                                                @if ($row['fs_tag_status'] === 'recognized')
                                                    <x-filament::badge color="success" size="xs">Recognized</x-filament::badge>
                                                @else
                                                    <x-filament::badge color="danger" size="xs" :tooltip="$row['fs_tag_issue']">Not set up yet</x-filament::badge>
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($preview['fs_tag_summary']['unrecognized'] > 0)
                    <p class="text-sm text-gray-600 dark:text-gray-300 mt-4">
                        You can still import now — unrecognized tags won't be applied, but every transaction will
                        still come in for review under Bank Transaction Mapping.
                    </p>
                @endif
            </x-filament::section>
        @endif
    @endif

    <x-filament::section class="mt-6">
        <x-slot name="heading">Posting safety</x-slot>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Import only creates normalized statement rows and draft mappings. Reconciliation failures, duplicate files,
            unapproved mappings and unbalanced journals cannot post to the official ledger.
        </p>
    </x-filament::section>
</x-filament-panels::page>
