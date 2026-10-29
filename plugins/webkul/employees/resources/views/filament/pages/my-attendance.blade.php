<x-filament-panels::page>
    @php
        $state = $this->getTodayState();
        $recent = $this->getRecentRecords();
        $remote = ($state['mode'] ?? null) === 'remote';
        $checkedIn = ($state['state'] ?? null) === 'checked_in';
        $checkedOut = ($state['state'] ?? null) === 'checked_out';
    @endphp

    <style>
        .att-card {
            background-color: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
        }
        .dark .att-card {
            background-color: #111827;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3);
        }

        .att-day-cell {
            background-color: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.07);
            border-radius: 0.625rem;
            transition: all 0.15s ease;
        }
        .dark .att-day-cell {
            background-color: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .att-day-cell:hover {
            border-color: rgba(59, 130, 246, 0.45);
        }
        .dark .att-day-cell:hover {
            background-color: rgba(255, 255, 255, 0.06);
            border-color: rgba(96, 165, 250, 0.5);
        }

        .att-day-today {
            border: 1.5px solid #3b82f6 !important;
            background-color: rgba(59, 130, 246, 0.05) !important;
        }
        .dark .att-day-today {
            border: 1.5px solid #60a5fa !important;
            background-color: rgba(59, 130, 246, 0.12) !important;
        }

        .att-day-weekend {
            background-color: #f8fafc;
            border: 1px solid rgba(0, 0, 0, 0.04);
            opacity: 0.65;
        }
        .dark .att-day-weekend {
            background-color: rgba(255, 255, 255, 0.015);
            border: 1px solid rgba(255, 255, 255, 0.035);
            opacity: 0.55;
        }

        .att-stat-chip {
            background-color: #f8fafc;
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
        }
        .dark .att-stat-chip {
            background-color: rgba(255, 255, 255, 0.025);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .att-punch-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .att-punch-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.4);
        }
        .att-checkout-btn {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.3);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .att-checkout-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(245, 158, 11, 0.4);
        }
        .att-complete-btn {
            background: linear-gradient(135deg, #10b981 0%, #047857 100%);
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .att-complete-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.45);
        }
    </style>

    <div
        class="w-full space-y-4"
        x-data="{
            sideOpen: true,
            @include('employees::filament.pages.partials.geo-attendance-alpine', ['remote' => $remote])
        }"
    >
        @if ($state === null)
            <x-filament::section>
                <div class="flex items-center gap-3 text-sm text-gray-500">
                    <x-filament::icon icon="heroicon-o-exclamation-circle" class="h-5 w-5 text-amber-500 shrink-0" />
                    <span>{{ __('employees::attendance.results.employee_not_eligible') }}</span>
                </div>
            </x-filament::section>
        @else
            @php
                $calendar = $this->getMonthlyCalendarData();
            @endphp

            {{-- Top Controls Bar --}}
            <div class="flex flex-wrap items-center justify-between gap-3 pb-1">
                <div class="flex items-center gap-2">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950/50 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5" />
                    </div>
                    <div>
                        <h1 class="text-base font-bold text-gray-950 dark:text-white">
                            My Attendance & Working Shifts
                        </h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Live geofenced attendance tracking and shift history
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-filament::link :href="\Webkul\Employee\Filament\Pages\CheckIn::getUrl()" icon="heroicon-m-device-phone-mobile" size="sm">
                        Phone check-in
                    </x-filament::link>
                    <button
                        type="button"
                        x-on:click="sideOpen = ! sideOpen"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10 transition cursor-pointer"
                    >
                        <x-filament::icon icon="heroicon-m-view-columns" class="h-4 w-4" />
                        <span x-text="sideOpen ? 'Hide Timeclock Panel' : 'Show Timeclock Panel'"></span>
                    </button>
                </div>
            </div>

            {{-- 2-Column Responsive Layout: Side Punch Panel + Full Calendar --}}
            <div class="flex flex-col lg:flex-row gap-5 items-start">
                {{-- LEFT COLUMN: Timeclock Side Panel --}}
                <div
                    x-show="sideOpen"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-x-4"
                    x-transition:enter-end="opacity-100 translate-x-0"
                    class="w-full lg:w-80 lg:shrink-0 space-y-4"
                >
                    {{-- Alert notices if any --}}
                    @if (! $checkedIn && ! $checkedOut && ! empty($state['is_past_start']))
                        <div class="rounded-xl border border-amber-500/25 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2.5">
                            <x-filament::icon icon="heroicon-m-clock" class="h-4 w-4 text-amber-500 shrink-0 mt-0.5" />
                            <div>
                                <span class="font-semibold">Shift started:</span> {{ $state['scheduled_start'] }}. Please check in now.
                            </div>
                        </div>
                    @endif

                    @if ($checkedIn && ! empty($state['is_shift_complete']))
                        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs text-emerald-800 dark:text-emerald-300 animate-pulse flex items-start gap-2.5">
                            <x-filament::icon icon="heroicon-m-check-badge" class="h-4 w-4 text-emerald-500 shrink-0 mt-0.5" />
                            <div>
                                <span class="font-bold">Target completed!</span> ({{ $state['elapsed_formatted'] ?? '8.0h+' }}). You may check out now.
                            </div>
                        </div>
                    @endif

                    {{-- Timeclock Card --}}
                    <x-filament::section compact>
                        <div class="space-y-3.5">
                            {{-- Status Header --}}
                            <div class="flex items-center justify-between pb-2 border-b border-gray-100 dark:border-white/10">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Current Shift</span>
                                <x-filament::badge
                                    :color="$checkedIn ? 'success' : ($checkedOut ? 'gray' : 'warning')"
                                    size="xs"
                                >
                                    {{ $checkedIn ? '● On Shift' : ($checkedOut ? 'Shift Done' : 'Not checked in') }}
                                </x-filament::badge>
                            </div>

                            {{-- Active Shift Details --}}
                            <div class="space-y-1">
                                @if ($checkedIn)
                                    <div class="text-base font-bold text-gray-950 dark:text-white">
                                        In at {{ $state['current_check_in'] ?? $state['check_in'] }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        Duration: <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $state['elapsed_formatted'] ?? 'Just started' }}</span>
                                        @if (! empty($state['scheduled_hours']))
                                            / {{ $state['scheduled_hours'] }}h
                                        @endif
                                    </div>
                                @elseif ($checkedOut)
                                    <div class="text-base font-bold text-gray-950 dark:text-white">
                                        Shift Done at {{ $state['check_out'] }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        Total: <span class="font-semibold text-gray-900 dark:text-gray-200">{{ $state['worked'] }}</span>
                                    </div>
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                        Back in the office? Check in again; each check-in / check-out pair counts.
                                    </div>
                                @else
                                    <div class="text-base font-bold text-gray-950 dark:text-white">
                                        Ready to Check In
                                    </div>
                                    @if (! empty($state['scheduled_start']) && ! empty($state['scheduled_end']))
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            Shift: <span class="font-medium text-gray-800 dark:text-gray-200">{{ $state['scheduled_start'] }} – {{ $state['scheduled_end'] }}</span>
                                        </div>
                                    @endif
                                @endif

                                @if (! empty($state['intervals']))
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400" data-testid="today-intervals">
                                        Check-ins today: <span class="font-medium text-gray-800 dark:text-gray-200">{{ $state['intervals'] }}</span>
                                    </div>
                                @endif

                                @if ($remote)
                                    <div class="flex items-center gap-1.5 text-xs text-blue-600 dark:text-blue-400 pt-1">
                                        <x-filament::icon icon="heroicon-m-globe-alt" class="h-3.5 w-3.5" />
                                        <span>Remote Policy · Geofence Exempt (no location is needed)</span>
                                    </div>
                                @elseif ($state['location'])
                                    <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 pt-1">
                                        <x-filament::icon icon="heroicon-m-map-pin" class="h-3.5 w-3.5 text-primary-500 shrink-0" />
                                        <span class="truncate">{{ $state['location'] }}</span>
                                    </div>
                                @endif

                                @if (! empty($state['late_minutes']) && $state['late_minutes'] > 0)
                                    <div class="pt-0.5 text-[11px] font-medium text-amber-600 dark:text-amber-400">
                                        Late by {{ $state['late_minutes'] }} minutes
                                    </div>
                                @endif
                            </div>

                            {{-- Shift Progress Bar --}}
                            @if ($checkedIn && isset($state['progress_percent']))
                                <div class="space-y-1 pt-1">
                                    <div class="flex justify-between text-[10px] text-gray-500 dark:text-gray-400 font-medium">
                                        <span>Progress ({{ $state['progress_percent'] }}%)</span>
                                        <span class="{{ $state['is_shift_complete'] ? 'text-emerald-600 dark:text-emerald-400 font-bold' : '' }}">
                                            {{ $state['is_shift_complete'] ? 'Target Met!' : 'In Progress' }}
                                        </span>
                                    </div>
                                    <div class="h-1.5 w-full rounded-full bg-gray-100 dark:bg-white/10 overflow-hidden">
                                        <div
                                            class="h-full transition-all duration-500 {{ $state['is_shift_complete'] ? 'bg-emerald-500' : 'bg-primary-600' }}"
                                            style="width: {{ $state['progress_percent'] }}%"
                                        ></div>
                                    </div>
                                </div>
                            @endif

                            {{-- Outcome Error / Result --}}
                            @if ($outcome)
                                <div class="rounded-lg p-2.5 text-xs {{ $outcome['ok'] ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-rose-500/10 text-rose-700 dark:text-rose-300' }}">
                                    <p class="leading-snug">{{ $outcome['message'] }}</p>
                                </div>
                            @endif

                            {{-- Punch Button --}}
                            @if (($state['mode'] ?? null) === 'none' && ! $checkedIn)
                                <p class="text-xs text-center text-gray-500">{{ __('employees::attendance.results.no_location_configured') }}</p>
                            @else
                                <button
                                    type="button"
                                    x-on:click="attempt('{{ $checkedIn ? 'check_out' : 'check_in' }}')"
                                    x-bind:disabled="busy"
                                    class="w-full rounded-xl py-3 px-4 text-sm font-semibold text-white cursor-pointer {{ $checkedIn ? ($state['is_shift_complete'] ? 'att-complete-btn' : 'att-checkout-btn') : 'att-punch-btn' }}"
                                >
                                    <span x-show="phase === 'idle'" class="flex items-center justify-center gap-2">
                                        @if ($checkedIn)
                                            <x-filament::icon icon="heroicon-m-arrow-right-on-rectangle" class="h-4 w-4" />
                                            @if (($state['lockout_remaining_minutes'] ?? 0) > 0)
                                                <span>Check Out (Unlocks in {{ $state['lockout_remaining_minutes'] }}m)</span>
                                            @else
                                                <span>{{ $outcome && ! $outcome['ok'] ? 'Try again — ' : '' }}{{ $state['is_shift_complete'] ? '✓ Complete & Check Out' : 'Check Out' }}</span>
                                            @endif
                                        @else
                                            <x-filament::icon icon="heroicon-m-arrow-left-on-rectangle" class="h-4 w-4" />
                                            <span>{{ $outcome && ! $outcome['ok'] ? 'Try again — ' : '' }}{{ $checkedOut ? 'Check In Again' : 'Check In Now' }}</span>
                                        @endif
                                    </span>
                                    <span x-show="phase === 'locating'" x-cloak class="flex items-center justify-center gap-2">
                                        <span class="animate-spin inline-block h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
                                        <span>Acquiring GPS location…</span>
                                    </span>
                                    <span x-show="phase === 'verifying'" x-cloak class="flex items-center justify-center gap-2">
                                        <span class="animate-spin inline-block h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
                                        <span>Verifying location…</span>
                                    </span>
                                </button>
                            @endif

                            <p class="text-[10px] text-gray-400 dark:text-gray-500 text-center leading-tight">
                                {{ __('employees::attendance.privacy_note') }}
                            </p>
                        </div>
                    </x-filament::section>

                    {{-- Quick Month Stats in Side Panel --}}
                    <div class="grid grid-cols-2 gap-2 text-center">
                        <div class="att-stat-chip">
                            <div class="text-base font-bold text-emerald-700 dark:text-emerald-400">{{ $calendar['stats']['daysPresent'] }}</div>
                            <div class="text-[10px] text-gray-500 dark:text-gray-400">Days Present</div>
                        </div>
                        <div class="att-stat-chip">
                            <div class="text-base font-bold text-primary-700 dark:text-primary-400">{{ $calendar['stats']['totalHours'] }}h</div>
                            <div class="text-[10px] text-gray-500 dark:text-gray-400">Total Hours</div>
                        </div>
                        <div class="att-stat-chip">
                            <div class="text-base font-bold text-amber-700 dark:text-amber-400">{{ $calendar['stats']['lateDays'] }}</div>
                            <div class="text-[10px] text-gray-500 dark:text-gray-400">Late Days</div>
                        </div>
                        <div class="att-stat-chip">
                            <div class="text-base font-bold text-purple-700 dark:text-purple-400">{{ $calendar['stats']['leaveDays'] }}</div>
                            <div class="text-[10px] text-gray-500 dark:text-gray-400">Time Off</div>
                        </div>
                    </div>
                </div>

                {{-- RIGHT COLUMN: Full-Sized Attendance Calendar (Matches app standard width) --}}
                <div class="flex-1 min-w-0 w-full space-y-4">
                    {{-- Calendar Section --}}
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex flex-wrap items-center justify-between gap-3 w-full">
                                <div class="flex items-center gap-2">
                                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950/50 dark:text-primary-400">
                                        <x-filament::icon icon="heroicon-o-calendar" class="h-4 w-4" />
                                    </div>
                                    <div>
                                        <span class="font-bold text-base text-gray-950 dark:text-white">{{ $calendar['monthName'] }} Attendance Calendar</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Monthly record of work days, hours, and leaves</p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        wire:click="previousMonth"
                                        class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-white/10 dark:text-gray-400 dark:hover:text-white transition cursor-pointer"
                                        title="Previous Month"
                                    >
                                        <x-filament::icon icon="heroicon-m-chevron-left" class="h-4 w-4" />
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="currentMonth"
                                        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-primary-600 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-950/30 transition cursor-pointer"
                                    >
                                        Current Month
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="nextMonth"
                                        class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-white/10 dark:text-gray-400 dark:hover:text-white transition cursor-pointer"
                                        title="Next Month"
                                    >
                                        <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </x-slot>

                        {{-- 7-Column Weekday Headers --}}
                        <div style="display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.375rem;" class="text-center text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2 pb-1 border-b border-gray-100 dark:border-white/10">
                            <div>Mon</div>
                            <div>Tue</div>
                            <div>Wed</div>
                            <div>Thu</div>
                            <div>Fri</div>
                            <div class="text-gray-400 dark:text-gray-500">Sat</div>
                            <div class="text-gray-400 dark:text-gray-500">Sun</div>
                        </div>

                        {{-- 7-Column Calendar Days Grid (Full standard sizing) --}}
                        <div style="display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.375rem;">
                            @foreach ($calendar['days'] as $cell)
                                @if ($cell['type'] === 'empty')
                                    <div class="min-h-[4.25rem] rounded-xl border border-dashed border-gray-200/40 dark:border-white/5 opacity-30"></div>
                                @else
                                    <div class="att-day-cell min-h-[4.25rem] p-1.5 flex flex-col justify-between
                                        {{ $cell['isToday'] ? 'att-day-today' : '' }}
                                        {{ $cell['isWeekend'] ? 'att-day-weekend' : '' }}
                                    ">
                                        {{-- Top row: Day Number & Status Dot --}}
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-semibold {{ $cell['isToday'] ? 'text-primary-600 dark:text-primary-400 font-bold' : 'text-gray-800 dark:text-gray-200' }}">
                                                {{ $cell['day'] }}
                                            </span>

                                            @if ($cell['status'] === 'present')
                                                <span class="relative flex h-2 w-2">
                                                    <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                                                </span>
                                            @elseif ($cell['status'] === 'late')
                                                <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                                            @elseif ($cell['status'] === 'leave')
                                                <span class="h-2 w-2 rounded-full bg-purple-500"></span>
                                            @elseif ($cell['status'] === 'leave_pending')
                                                <span class="h-2 w-2 rounded-full border border-purple-500"></span>
                                            @elseif ($cell['status'] === 'needs_review')
                                                <span class="h-2 w-2 rounded-full bg-orange-500"></span>
                                            @elseif ($cell['status'] === 'absent')
                                                <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                                            @endif
                                        </div>

                                        {{-- Bottom label/badge --}}
                                        @if (! empty($cell['label']))
                                            <div class="text-[10px] font-medium leading-tight truncate px-1.5 py-0.5 rounded text-center
                                                {{ $cell['status'] === 'present' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : '' }}
                                                {{ $cell['status'] === 'late' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : '' }}
                                                {{ $cell['status'] === 'leave' ? 'bg-purple-500/10 text-purple-700 dark:text-purple-300' : '' }}
                                                {{ $cell['status'] === 'leave_pending' ? 'border border-dashed border-purple-400 text-purple-700 dark:text-purple-300' : '' }}
                                                {{ $cell['status'] === 'needs_review' ? 'bg-orange-500/10 text-orange-700 dark:text-orange-300' : '' }}
                                                {{ $cell['status'] === 'absent' ? 'bg-rose-500/10 text-rose-700 dark:text-rose-300' : '' }}
                                                {{ $cell['status'] === 'weekend' ? 'text-gray-400 dark:text-gray-500' : '' }}
                                            ">
                                                {{ $cell['label'] }}
                                            </div>
                                        @elseif ($cell['isWeekend'])
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 text-center">
                                                Off
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </x-filament::section>

                    {{-- Grace balance (time bank): the same numbers as HR's monthly report --}}
                    @php($grace = $this->getGraceBalance())
                    @if ($grace)
                        @include('employees::filament.components.grace-ledger', ['ledgerRows' => $grace['rows'], 'ledgerSummary' => $grace['summary'], 'ledgerTitle' => $grace['title']])
                    @endif

                    {{-- Recent Attendance History Section --}}
                    <x-filament::section heading="Recent Attendance Logs" :collapsible="true" :collapsed="true">
                        <div class="divide-y divide-gray-100 dark:divide-white/10">
                            @forelse ($recent as $row)
                                <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                                        </div>
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $row['date'] }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                {{ $row['check_in'] }} – {{ $row['check_out'] }} · <span class="font-medium text-gray-700 dark:text-gray-300">{{ $row['worked'] }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2.5">
                                        <x-filament::badge :color="$row['review'] ? 'warning' : ($row['status'] === 'present' ? 'success' : 'gray')">
                                            {{ $row['review'] ? 'Under review' : Str::headline($row['status']) }}
                                        </x-filament::badge>
                                        {{ ($this->requestCorrectionAction)(['record' => $row['id']]) }}
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-gray-500 py-3">No attendance records found for this period.</p>
                            @endforelse
                        </div>

                        <div class="pt-3 border-t border-gray-100 dark:border-white/10">
                            {{ $this->requestMissingDayAction }}
                        </div>
                    </x-filament::section>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
