<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        @php
            $report = $this->reportData;
        @endphp

        @if ($report)
            @if ($report['issues']->isNotEmpty())
                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">
                        <span class="text-warning-600">
                            {{ __('accounting::filament/clusters/reporting/pages/financial-reports.content.issues', ['count' => $report['issues']->count()]) }}
                        </span>
                    </x-slot>

                    <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($report['issues'] as $issue)
                            <li>
                                <span @class(['font-semibold', 'text-danger-600' => $issue->isError(), 'text-warning-600' => ! $issue->isError()])>
                                    {{ strtoupper($issue->severity) }}
                                </span>
                                {{ $issue->message }}
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">
                    {{ $report['template']->name }} — {{ $report['year'] }}
                </x-slot>

                @if ($report['template']->status !== \Webkul\Accounting\Enums\TemplateStatus::PUBLISHED)
                    <x-slot name="description">
                        {{ __('accounting::filament/clusters/reporting/pages/financial-reports.content.draft-notice') }}
                    </x-slot>
                @endif

                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/5">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50/50 dark:bg-white/5">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500 dark:text-gray-400"></th>
                                @foreach ($report['columns'] as $column)
                                    @if ($column->isSpacer())
                                        <th class="w-3 bg-gray-100/60 dark:bg-white/10"></th>
                                    @else
                                        <th class="px-4 py-2 text-right font-semibold text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                            {{ $column->label }}
                                        </th>
                                    @endif
                                @endforeach
                            </tr>
                            <tr>
                                <th class="px-4 pb-2 text-left"></th>
                                @foreach ($report['columns'] as $column)
                                    @if ($column->isSpacer())
                                        <th class="w-3 bg-gray-100/60 dark:bg-white/10"></th>
                                    @else
                                        <th class="px-4 pb-2 text-right text-xs font-normal text-gray-400 whitespace-nowrap">
                                            @if (in_array($column->key, $report['unavailable'], true))
                                                {{ __('accounting::filament/clusters/reporting/pages/financial-reports.content.not-available-column') }}
                                            @else
                                                {{ $this->columnSubLabel($column) }}
                                            @endif
                                        </th>
                                    @endif
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['rows'] as $row)
                                @continue(! $row->isVisible)

                                @if ($row->lineType === \Webkul\Accounting\Enums\LineType::SPACER)
                                    <tr>
                                        <td class="px-4 py-1" colspan="{{ count($report['columns']) + 1 }}">&nbsp;</td>
                                    </tr>
                                    @continue
                                @endif

                                <tr @class(['bg-gray-50/60 dark:bg-white/5' => $row->lineType === \Webkul\Accounting\Enums\LineType::SECTION_HEADER])>
                                    <td @class([
                                        'px-4 py-1.5 text-gray-900 dark:text-white whitespace-nowrap',
                                        'font-bold' => $row->isBold,
                                    ]) style="padding-left: {{ 1 + $row->indentLevel * 1.25 }}rem">
                                        {{ $row->caption }}
                                    </td>
                                    @foreach ($report['columns'] as $column)
                                        @if ($column->isSpacer())
                                            <td class="w-3 bg-gray-100/60 dark:bg-white/10"></td>
                                        @else
                                            @php
                                                $value = $row->carriesValues() ? $row->valueFor($column->key) : null;
                                                $checkFails = $row->isCheck && $value !== null && abs($value) > 0.01;
                                            @endphp
                                            <td @class([
                                                'px-4 py-1.5 text-right tabular-nums whitespace-nowrap',
                                                'font-bold' => $row->isBold,
                                                'text-danger-600 font-semibold' => $checkFails,
                                                'text-success-600' => $row->isCheck && ! $checkFails,
                                                'text-gray-700 dark:text-gray-200' => ! $row->isCheck && $value !== null,
                                                'text-gray-400 dark:text-gray-500' => ! $row->isCheck && $value === null,
                                            ])>
                                                {{ $row->carriesValues() ? $this->formatValue($value, $report['usd']) : '' }}
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                {{ __('accounting::filament/clusters/reporting/pages/financial-reports.content.empty') }}
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
