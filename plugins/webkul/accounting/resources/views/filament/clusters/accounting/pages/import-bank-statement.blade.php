<x-filament-panels::page>
    {{ $this->form }}

    @if ($preview)
        <x-filament::section heading="Conversion preview">
            <p>
                {{ $preview['bank'] }} · {{ $preview['bank_account_number'] }} · {{ $preview['period'] }} ·
                detected {{ $preview['detected_currency'] }} · selected {{ $preview['selected_currency'] }} ·
                {{ $preview['row_count'] }} transactions{{ $preview['truncated'] ? ' (first 1,000 shown)' : '' }}
            </p>
            @foreach ($preview['validation_errors'] as $error)
                <p>{{ $error['message'] }}</p>
            @endforeach
            @foreach ($preview['missing_rates'] as $missingRate)
                <p>{{ $missingRate }}</p>
            @endforeach
            <table>
                <thead><tr><th>Date</th><th>Description</th><th>Original</th><th>Rate</th><th>Company amount</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($preview['rows'] as $row)
                        <tr>
                            <td>{{ $row['date'] }}</td><td>{{ $row['description'] }}</td>
                            <td>{{ $row['original_amount'] }} {{ $row['original_currency'] }}</td>
                            <td>{{ $row['exchange_rate'] }}</td>
                            <td>{{ $row['company_amount'] }} {{ $row['company_currency'] }}</td>
                            <td>{{ $row['status'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
                    <table>
                        <thead>
                            <tr><th>Description</th><th>Tag in file</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['rows'] as $row)
                                @if ($row['fs_tag_status'] !== 'none')
                                    <tr>
                                        <td>{{ $row['description'] }}</td>
                                        <td>{{ $row['fs_tag_code'] }}</td>
                                        <td>
                                            @if ($row['fs_tag_status'] === 'recognized')
                                                <x-filament::badge color="success">Recognized</x-filament::badge>
                                            @else
                                                <x-filament::badge color="danger" :tooltip="$row['fs_tag_issue']">Not set up yet</x-filament::badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
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
