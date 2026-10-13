<x-filament-panels::page>
    @php
        $hasMultiFactorAuth = \Filament\Facades\Filament::hasMultiFactorAuthentication();
        $hasEmployeeLeaves = !empty($leaveSummary['cards'] ?? []);
        $hasAttendance = !empty($attendanceSummary);
        $hasSideColumn = $hasMultiFactorAuth || $hasEmployeeLeaves || $hasAttendance;
    @endphp

    <div
        @class([
            'grid grid-cols-1 gap-6',
            'lg:grid-cols-3!' => $hasSideColumn,
        ])
    >
        <div
            @class([
                'lg:col-span-2' => $hasSideColumn,
            ])
        >
            <form
                wire:submit="updateProfile"
                wire:key="profile-form"
                x-data="{ isProcessing: false }"
                x-on:submit="if (isProcessing) $event.preventDefault()"
                x-on:form-processing-started="isProcessing = true"
                x-on:form-processing-finished="isProcessing = false"
                class="fi-form grid gap-y-6"
            >
                <div class="flex flex-col gap-6">
                    {{ $this->editProfileForm }}

                    <x-filament::actions
                        :actions="$this->getUpdateProfileFormActions()"
                        :full-width="false"
                    />
                </div>
            </form>
        </div>

        @if ($hasSideColumn)
            <div class="lg:col-span-1 flex flex-col gap-6">
                @if ($hasAttendance)
                    {{-- My Attendance & Monthly Performance --}}
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2">
                                <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5 text-primary-500" />
                                <span>My Attendance</span>
                            </div>
                        </x-slot>

                        <x-slot name="description">
                            {{ $attendanceSummary['month_label'] ?? now()->format('F Y') }} Scorecard
                        </x-slot>

                        <x-slot name="headerEnd">
                            <x-filament::button
                                tag="a"
                                href="{{ $attendanceSummary['checkin_url'] }}"
                                size="xs"
                                color="gray"
                                outlined
                                icon="heroicon-m-arrow-top-right-on-square"
                            >
                                Check In
                            </x-filament::button>
                        </x-slot>

                        {{-- Today's Status Banner --}}
                        @php
                            $todayState = $attendanceSummary['today_state'] ?? [];
                            $stateCode = $todayState['state'] ?? 'not_started';
                            $checkInTime = $todayState['check_in_time'] ?? null;
                            $workedSoFar = $todayState['worked_hours'] ?? 0;
                        @endphp
                        <div class="rounded-lg p-3" style="border: 1px solid rgba(120, 120, 128, 0.16); background: rgba(120, 120, 128, 0.04);">
                            <div class="flex items-center justify-between text-xs mb-3">
                                <span class="font-medium text-gray-500 dark:text-gray-400">Today's Shift</span>
                                @if ($stateCode === 'checked_in')
                                    <x-filament::badge color="success" size="xs">
                                        Checked In at {{ $checkInTime }}
                                    </x-filament::badge>
                                @elseif ($stateCode === 'checked_out')
                                    <x-filament::badge color="info" size="xs">
                                        Shift Done ({{ number_format($workedSoFar, 1) }}h)
                                    </x-filament::badge>
                                @else
                                    <x-filament::badge color="gray" size="xs">
                                        Not Checked In
                                    </x-filament::badge>
                                @endif
                            </div>

                            {{-- 4 Symmetrical Monthly Metrics --}}
                            <div class="grid grid-cols-2 gap-2 text-center text-xs">
                                <div class="rounded-lg p-2.5" style="border: 1px solid rgba(120, 120, 128, 0.14); background: rgba(120, 120, 128, 0.04);">
                                    <div class="text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Present</div>
                                    <div class="text-lg font-extrabold text-emerald-600 dark:text-emerald-400 mt-0.5">
                                        {{ $attendanceSummary['days_present'] }} <span class="text-3xs font-normal text-gray-400">Days</span>
                                    </div>
                                </div>
                                <div class="rounded-lg p-2.5" style="border: 1px solid rgba(120, 120, 128, 0.14); background: rgba(120, 120, 128, 0.04);">
                                    <div class="text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Absent</div>
                                    <div class="text-lg font-extrabold {{ $attendanceSummary['days_absent'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-gray-100' }} mt-0.5">
                                        {{ $attendanceSummary['days_absent'] }} <span class="text-3xs font-normal text-gray-400">Days</span>
                                    </div>
                                </div>
                                <div class="rounded-lg p-2.5" style="border: 1px solid rgba(120, 120, 128, 0.14); background: rgba(120, 120, 128, 0.04);">
                                    <div class="text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Late</div>
                                    <div class="text-lg font-extrabold {{ $attendanceSummary['late_minutes'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }} mt-0.5">
                                        {{ $attendanceSummary['late_minutes'] }} <span class="text-3xs font-normal text-gray-400">mins</span>
                                    </div>
                                </div>
                                <div class="rounded-lg p-2.5" style="border: 1px solid rgba(120, 120, 128, 0.14); background: rgba(120, 120, 128, 0.04);">
                                    <div class="text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Worked</div>
                                    <div class="text-lg font-extrabold text-sky-600 dark:text-sky-400 mt-0.5">
                                        {{ $attendanceSummary['total_hours'] }} <span class="text-3xs font-normal text-gray-400">hrs</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Recent Time-ins --}}
                        <div class="mt-4">
                            <h4 class="text-xs font-bold text-gray-900 dark:text-white mb-2">Recent Logs</h4>
                            @if (empty($attendanceSummary['recent_records']))
                                <p class="text-xs text-gray-500 dark:text-gray-400 py-2 text-center">No attendance records found yet.</p>
                            @else
                                <div class="overflow-hidden rounded-lg" style="border: 1px solid rgba(120, 120, 128, 0.16);">
                                    @foreach ($attendanceSummary['recent_records'] as $record)
                                        <div class="flex items-center justify-between p-2.5 text-xs" style="border-bottom: 1px solid rgba(120, 120, 128, 0.12); background: rgba(120, 120, 128, 0.02);">
                                            <div>
                                                <div class="font-semibold text-gray-900 dark:text-white">{{ $record['date'] }}</div>
                                                <div class="text-3xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                    In: {{ $record['check_in'] }} &bull; Out: {{ $record['check_out'] }}
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <x-filament::badge :color="$record['status_color']" size="xs">
                                                    {{ $record['status_label'] }}
                                                </x-filament::badge>
                                                <div class="text-3xs font-semibold text-gray-600 dark:text-gray-400 mt-0.5">
                                                    {{ $record['worked_hours'] }}
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </x-filament::section>
                @endif

                @if ($hasEmployeeLeaves)
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2">
                                <x-filament::icon icon="heroicon-o-chart-pie" class="h-5 w-5 text-primary-500" />
                                <span>My Leave Entitlements</span>
                            </div>
                        </x-slot>

                        <x-slot name="headerEnd">
                            <x-filament::badge color="primary" size="sm">
                                {{ number_format($leaveSummary['total_left'] ?? 0, 1) }} / {{ number_format($leaveSummary['total_allocated'] ?? 0, 1) }} Days
                            </x-filament::badge>
                        </x-slot>

                        <div class="space-y-3">
                            @foreach ($leaveSummary['cards'] as $card)
                                @php
                                    $pct = $card['allocated'] > 0 ? min(100, max(0, (int) round(($card['left'] / $card['allocated']) * 100))) : 0;
                                    $lower = strtolower($card['name']);
                                    $badgeColor = str_contains($lower, 'sick') ? 'danger' : (str_contains($lower, 'casual') ? 'info' : 'warning');
                                    $barGradient = str_contains($lower, 'sick')
                                        ? 'linear-gradient(90deg, #f43f5e, #e11d48)'
                                        : (str_contains($lower, 'casual')
                                            ? 'linear-gradient(90deg, #0284c7, #0369a1)'
                                            : 'linear-gradient(90deg, #10b981, #059669)');
                                    $stripeColor = str_contains($lower, 'sick') ? '#f43f5e' : (str_contains($lower, 'casual') ? '#0284c7' : '#10b981');
                                @endphp
                                <div class="rounded-lg p-3 dark:bg-white/[0.02]" style="border: 1px solid rgba(120, 120, 128, 0.16); position: relative; overflow: hidden; background: rgba(120, 120, 128, 0.04);">
                                    <div style="position: absolute; top: 0; left: 0; bottom: 0; width: 3px; background: {{ $stripeColor }};"></div>
                                    <div class="pl-1.5">
                                        <div class="flex items-center justify-between text-xs font-semibold">
                                            <span class="text-gray-950 dark:text-white font-bold">{{ $card['name'] }}</span>
                                            <x-filament::badge :color="$badgeColor" size="xs">
                                                {{ number_format($card['left'], 1) }} Left
                                            </x-filament::badge>
                                        </div>
                                        <div style="margin-top: 0.5rem; height: 6px; width: 100%; border-radius: 9999px; background: rgba(120, 120, 128, 0.2); overflow: hidden;">
                                            <div style="height: 100%; border-radius: 9999px; width: {{ $pct }}%; background: {{ $barGradient }}; transition: all 0.3s ease;"></div>
                                        </div>
                                        <div class="mt-2 flex justify-between text-[11px] text-gray-500 dark:text-gray-400">
                                            <span>Allocated: <strong class="text-gray-800 dark:text-gray-200 font-semibold">{{ number_format($card['allocated'], 1) }}</strong></span>
                                            <span>Used: <strong class="text-gray-800 dark:text-gray-200 font-semibold">{{ number_format($card['taken'], 1) }}</strong></span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <x-slot name="footer">
                            <x-filament::button
                                tag="a"
                                href="{{ url('/admin/time-off/dashboard/my-time-offs/create') }}"
                                color="primary"
                                size="sm"
                                icon="heroicon-m-plus"
                                class="w-full"
                            >
                                Request Time Off
                            </x-filament::button>
                        </x-slot>
                    </x-filament::section>
                @endif

                @if ($hasMultiFactorAuth)
                    <div>
                        {{ $this->multiFactorAuthenticationSchema }}
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
