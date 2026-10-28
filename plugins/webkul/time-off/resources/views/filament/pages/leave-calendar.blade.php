@php
    $calendar = $this->getCalendar();
    $departments = $this->getDepartmentOptions();
    $control = 'border: 1px solid rgba(127,127,127,.35); border-radius: .5rem; padding: .375rem .625rem; background: transparent; font-size: .875rem;';
    $row = 'display: grid; grid-template-columns: 9rem 1fr; gap: .75rem; padding: .5rem .25rem; border-top: 1px solid rgba(127,127,127,.15); align-items: start;';
    $chip = 'display: inline-block; border-radius: 9999px; padding: .125rem .625rem; margin: .125rem .25rem .125rem 0; font-size: .8rem;';
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div style="display: flex; flex-wrap: wrap; gap: .75rem; align-items: center;">
            <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-left" wire:click="previousMonth">Previous</x-filament::button>
            <input type="month" wire:model.live="month" style="{{ $control }}" aria-label="Month">
            <x-filament::button color="gray" size="sm" icon-position="after" icon="heroicon-o-chevron-right" wire:click="nextMonth">Next</x-filament::button>

            <select wire:model.live="departmentId" style="{{ $control }}" aria-label="Department">
                <option value="">All departments</option>
                @foreach ($departments as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>

            <label style="display: inline-flex; gap: .375rem; align-items: center; font-size: .875rem;">
                <input type="checkbox" wire:model.live="includePending">
                Include requests waiting for approval
            </label>
        </div>
    </x-filament::section>

    <x-filament::section
        :heading="$calendar['label']"
        :description="$calendar['people'].' '.($calendar['people'] === 1 ? 'person' : 'people').' on leave this month. Approved leave only'.($includePending ? ', plus pending requests (dashed).' : '.').' Cancelled and refused leave is never shown.'"
    >
        <div>
            @foreach ($calendar['days'] as $day)
                <div style="{{ $row }} {{ $day['weekend'] ? 'opacity: .55;' : '' }}">
                    <div style="font-weight: 600; font-size: .875rem; font-variant-numeric: tabular-nums;">
                        {{ $day['label'] }}
                        @if ($day['weekend'])
                            <span style="font-weight: 400; font-size: .75rem;">(weekend)</span>
                        @endif
                    </div>
                    <div>
                        @forelse ($day['entries'] as $entry)
                            <span
                                style="{{ $chip }} {{ $entry['approved'] ? 'background: rgba(37,99,235,.12);' : 'border: 1px dashed rgba(217,119,6,.8);' }}"
                                title="{{ $entry['state'] }}"
                            >
                                <strong>{{ $entry['employee'] }}</strong>
                                · {{ $entry['type'] }}@if ($entry['portion'] !== 'full day') ({{ $entry['portion'] }})@endif
                                @if ($entry['department'])
                                    <span style="opacity: .65;">· {{ $entry['department'] }}</span>
                                @endif
                                @unless ($entry['approved'])
                                    <span style="opacity: .75;">· {{ $entry['state'] }}</span>
                                @endunless
                            </span>
                        @empty
                            <span style="opacity: .45; font-size: .875rem;">—</span>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
