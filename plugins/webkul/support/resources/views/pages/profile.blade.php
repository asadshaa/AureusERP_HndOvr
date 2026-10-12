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
                    {{-- My Attendance & Time-ins --}}
                    <div class="rounded-xl border border-gray-200/80 bg-white p-5 shadow-xs dark:border-white/10 dark:bg-gray-900">
                        <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-gray-800">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-clock class="h-5 w-5 text-primary-500" />
                                <h3 class="text-sm font-bold text-gray-950 dark:text-white">
                                    My Attendance & Time-ins
                                </h3>
                            </div>
                            <a
                                href="{{ $attendanceSummary['checkin_url'] }}"
                                class="inline-flex items-center gap-1 rounded-md bg-primary-50 px-2 py-1 text-xs font-semibold text-primary-700 hover:bg-primary-100 transition-colors dark:bg-primary-950/40 dark:text-primary-300 dark:hover:bg-primary-900/60"
                            >
                                Check In / Out
                                <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" />
                            </a>
                        </div>

                        {{-- Today's Status Banner --}}
                        @php
                            $todayState = $attendanceSummary['today_state'] ?? [];
                            $stateCode = $todayState['state'] ?? 'not_started';
                            $checkInTime = $todayState['check_in_time'] ?? null;
                            $workedSoFar = $todayState['worked_hours'] ?? 0;
                        @endphp
                        <div class="mt-3 rounded-lg border border-gray-100 bg-gray-50/70 p-3 dark:border-gray-800 dark:bg-gray-800/40">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-medium text-gray-500 dark:text-gray-400">Today's Status</span>
                                @if ($stateCode === 'checked_in')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                        Checked In at {{ $checkInTime }}
                                    </span>
                                @elseif ($stateCode === 'checked_out')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-800 dark:bg-blue-950/40 dark:text-blue-300">
                                        Shift Completed ({{ number_format($workedSoFar, 1) }}h)
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                        Not Checked In Today
                                    </span>
                                @endif
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-2 text-center text-xs">
                                <div class="rounded-md bg-white p-2 border border-gray-100 dark:border-gray-700 dark:bg-gray-800/80">
                                    <div class="text-3xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Days Present</div>
                                    <div class="text-sm font-bold text-gray-900 dark:text-white mt-0.5">{{ $attendanceSummary['days_present'] }}</div>
                                </div>
                                <div class="rounded-md bg-white p-2 border border-gray-100 dark:border-gray-700 dark:bg-gray-800/80">
                                    <div class="text-3xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Worked Hours</div>
                                    <div class="text-sm font-bold text-gray-900 dark:text-white mt-0.5">{{ $attendanceSummary['total_hours'] }}h</div>
                                </div>
                            </div>
                        </div>

                        {{-- Recent Time-ins --}}
                        <div class="mt-4">
                            <h4 class="text-xs font-bold text-gray-900 dark:text-white mb-2">Recent Time-ins</h4>
                            @if (empty($attendanceSummary['recent_records']))
                                <p class="text-xs text-gray-500 dark:text-gray-400 py-2 text-center">No attendance records found yet.</p>
                            @else
                                <div class="divide-y divide-gray-100 dark:divide-gray-800 overflow-hidden rounded-lg border border-gray-100 dark:border-gray-800">
                                    @foreach ($attendanceSummary['recent_records'] as $record)
                                        <div class="flex items-center justify-between p-2.5 bg-white dark:bg-gray-900 text-xs">
                                            <div>
                                                <div class="font-semibold text-gray-900 dark:text-white">{{ $record['date'] }}</div>
                                                <div class="text-3xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                    In: {{ $record['check_in'] }} &bull; Out: {{ $record['check_out'] }}
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <span @class([
                                                    'inline-flex items-center rounded px-1.5 py-0.5 text-3xs font-bold uppercase',
                                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' => $record['status_color'] === 'success',
                                                    'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' => $record['status_color'] === 'warning',
                                                    'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' => $record['status_color'] === 'danger',
                                                    'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300' => $record['status_color'] === 'info',
                                                    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $record['status_color'] === 'gray',
                                                ])>
                                                    {{ $record['status_label'] }}
                                                </span>
                                                <div class="text-3xs font-semibold text-gray-600 dark:text-gray-400 mt-0.5">
                                                    {{ $record['worked_hours'] }}
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($hasEmployeeLeaves)
                    <div class="rounded-xl border border-gray-200/80 bg-white p-5 shadow-xs dark:border-white/10 dark:bg-gray-900">
                        <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-gray-800">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-chart-pie class="h-5 w-5 text-primary-500" />
                                <h3 class="text-sm font-bold text-gray-950 dark:text-white">
                                    My Leave Entitlements
                                </h3>
                            </div>
                            <span class="inline-flex items-center rounded-md bg-primary-50 px-2 py-0.5 text-xs font-bold text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">
                                {{ number_format($leaveSummary['total_left'] ?? 0, 1) }} / {{ number_format($leaveSummary['total_allocated'] ?? 0, 1) }} Days
                            </span>
                        </div>

                        <div class="mt-4 space-y-3">
                            @foreach ($leaveSummary['cards'] as $card)
                                @php
                                    $pct = $card['allocated'] > 0 ? min(100, max(0, (int) round(($card['left'] / $card['allocated']) * 100))) : 0;
                                    $lower = strtolower($card['name']);
                                    $badgeColor = str_contains($lower, 'sick') ? 'text-rose-600 dark:text-rose-400' : (str_contains($lower, 'casual') ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400');
                                    $barColor = str_contains($lower, 'sick') ? 'bg-rose-500' : (str_contains($lower, 'casual') ? 'bg-amber-500' : 'bg-emerald-500');
                                @endphp
                                <div class="rounded-lg border border-gray-100 bg-gray-50/70 p-3 dark:border-gray-800 dark:bg-gray-800/40">
                                    <div class="flex items-center justify-between text-xs font-semibold">
                                        <span class="text-gray-900 dark:text-white">{{ $card['name'] }}</span>
                                        <span class="{{ $badgeColor }} font-bold">{{ number_format($card['left'], 1) }} Left</span>
                                    </div>
                                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <div class="mt-2 flex justify-between text-3xs text-gray-500 dark:text-gray-400">
                                        <span>Allocated: {{ number_format($card['allocated'], 1) }}</span>
                                        <span>Used: {{ number_format($card['taken'], 1) }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-800 text-center">
                            <a
                                href="{{ url('/admin/time-off/dashboard/my-time-offs/create') }}"
                                class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-primary-50 px-3 py-2 text-xs font-semibold text-primary-700 hover:bg-primary-100 transition-colors dark:bg-primary-950/40 dark:text-primary-300 dark:hover:bg-primary-900/60"
                            >
                                <x-heroicon-m-plus class="h-3.5 w-3.5" />
                                Request Time Off
                            </a>
                        </div>
                    </div>
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
