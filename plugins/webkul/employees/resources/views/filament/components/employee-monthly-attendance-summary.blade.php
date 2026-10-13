@php
    $workingDays = (int) ($summary['working_days'] ?? 22);
    $present = (int) ($summary['present'] ?? 0);
    $halfDay = (int) ($summary['half_day'] ?? 0);
    $absent = (int) ($summary['absent'] ?? 0);
    $leave = (int) ($summary['leave'] ?? 0);
    $lateMinutes = (int) ($summary['late_minutes'] ?? 0);
    $earlyMinutes = (int) ($summary['early_minutes'] ?? 0);
    $workedHours = (float) ($summary['worked_hours'] ?? 0.0);
    $overtimeHours = (float) ($summary['overtime_hours'] ?? 0.0);
    $monthLabel = (string) ($summary['month'] ?? now()->format('F Y'));

    $attendanceRate = $workingDays > 0 ? min(100, max(0, (int) round((($present + ($halfDay * 0.5)) / $workingDays) * 100))) : 0;
@endphp

<style>
    .erp-att-container {
        border-radius: 0.875rem;
        border: 1px solid rgba(120, 120, 128, 0.15);
        background: rgba(255, 255, 255, 0.7);
        padding: 1.25rem;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.03);
        backdrop-filter: blur(8px);
    }
    :is(.dark) .erp-att-container {
        border-color: rgba(255, 255, 255, 0.08);
        background: rgba(24, 24, 27, 0.65);
        box-shadow: none;
    }

    .erp-att-grid {
        display: grid;
        grid-template-columns: repeat(1, minmax(0, 1fr));
        gap: 0.875rem;
    }
    @media (min-width: 640px) {
        .erp-att-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (min-width: 1024px) {
        .erp-att-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }

    .erp-att-card {
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        border-radius: 0.75rem;
        padding: 1rem;
        border: 1px solid rgba(120, 120, 128, 0.16);
        background: #ffffff;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
    }
    :is(.dark) .erp-att-card {
        border-color: rgba(255, 255, 255, 0.07);
        background: rgba(255, 255, 255, 0.025);
    }
    .erp-att-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.06);
        border-color: rgba(120, 120, 128, 0.28);
    }
    :is(.dark) .erp-att-card:hover {
        box-shadow: 0 4px 16px -2px rgba(0, 0, 0, 0.35);
        border-color: rgba(255, 255, 255, 0.15);
        background: rgba(255, 255, 255, 0.04);
    }

    .erp-att-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
    }
    .erp-att-card-present::before { background: linear-gradient(90deg, #10b981, #059669); }
    .erp-att-card-absent::before { background: linear-gradient(90deg, #f43f5e, #e11d48); }
    .erp-att-card-punctual::before { background: linear-gradient(90deg, #f59e0b, #d97706); }
    .erp-att-card-hours::before { background: linear-gradient(90deg, #0284c7, #0369a1); }
</style>

<div class="erp-att-container mb-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 pb-3 border-b border-gray-200/80 dark:border-white/10">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950/60 dark:text-primary-400">
                <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-base font-bold text-gray-950 dark:text-white tracking-tight">
                        {{ $monthLabel }} Attendance Overview
                    </h3>
                    <x-filament::badge color="primary" size="sm">
                        {{ $employee->name }}
                    </x-filament::badge>
                    @if (!empty($employee->department?->name))
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            &bull; {{ $employee->department->name }}
                        </span>
                    @endif
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    Monthly performance metrics &bull; Weekends (Saturday & Sunday) automatically excluded &bull; {{ $workingDays }} Working Days Total
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <x-filament::badge color="gray" size="sm">
                {{ $monthLabel }}
            </x-filament::badge>
        </div>
    </div>

    {{-- 4 Symmetrical App-Aligned Stat Cards --}}
    <div class="erp-att-grid">
        {{-- Card 1: Present & Attendance Rate --}}
        <div class="erp-att-card erp-att-card-present">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <x-filament::icon icon="heroicon-o-check-badge" class="h-4 w-4" />
                        </span>
                        <div>
                            <span class="block text-xs font-bold uppercase tracking-wider text-gray-900 dark:text-gray-100">
                                Attendance
                            </span>
                            <span class="block text-[10px] text-gray-400 dark:text-gray-500">
                                Days Present
                            </span>
                        </div>
                    </div>
                    <x-filament::badge color="success" size="xs">
                        {{ $attendanceRate }}% Rate
                    </x-filament::badge>
                </div>

                <div class="mt-3.5 flex items-baseline gap-1.5">
                    <span class="text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                        {{ $present }}
                    </span>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        / {{ $workingDays }} working days
                    </span>
                </div>

                <div class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                    <div class="h-full rounded-full bg-emerald-500 dark:bg-emerald-400 transition-all duration-300" style="width: {{ $attendanceRate }}%"></div>
                </div>
            </div>

            <div class="mt-3.5 flex items-center justify-between border-t border-gray-100 dark:border-white/5 pt-2 text-[11px] text-gray-500 dark:text-gray-400">
                <span>Half Days: <strong class="text-gray-800 dark:text-gray-200">{{ $halfDay }}</strong></span>
                <span class="font-medium text-emerald-600 dark:text-emerald-400">Verified</span>
            </div>
        </div>

        {{-- Card 2: Absent & Leaves --}}
        <div class="erp-att-card erp-att-card-absent">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400">
                            <x-filament::icon icon="heroicon-o-x-circle" class="h-4 w-4" />
                        </span>
                        <div>
                            <span class="block text-xs font-bold uppercase tracking-wider text-gray-900 dark:text-gray-100">
                                Absenteeism
                            </span>
                            <span class="block text-[10px] text-gray-400 dark:text-gray-500">
                                Missed Days & Leaves
                            </span>
                        </div>
                    </div>
                    @if ($absent > 0)
                        <x-filament::badge color="danger" size="xs">
                            {{ $absent }} Absent
                        </x-filament::badge>
                    @else
                        <x-filament::badge color="success" size="xs">
                            0 Absent
                        </x-filament::badge>
                    @endif
                </div>

                <div class="mt-3.5 flex items-baseline gap-1.5">
                    <span class="text-3xl font-extrabold tracking-tight {{ $absent > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-950 dark:text-white' }}">
                        {{ $absent }}
                    </span>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        missed workdays
                    </span>
                </div>

                <div class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                    @php $absentPct = $workingDays > 0 ? min(100, (int) round(($absent / $workingDays) * 100)) : 0; @endphp
                    <div class="h-full rounded-full bg-rose-500 dark:bg-rose-400 transition-all duration-300" style="width: {{ $absentPct }}%"></div>
                </div>
            </div>

            <div class="mt-3.5 flex items-center justify-between border-t border-gray-100 dark:border-white/5 pt-2 text-[11px] text-gray-500 dark:text-gray-400">
                <span>Approved Leaves: <strong class="text-gray-800 dark:text-gray-200">{{ $leave }} days</strong></span>
                <span class="font-medium text-sky-600 dark:text-sky-400">Time Off</span>
            </div>
        </div>

        {{-- Card 3: Punctuality & Late Arrival --}}
        <div class="erp-att-card erp-att-card-punctual">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                        </span>
                        <div>
                            <span class="block text-xs font-bold uppercase tracking-wider text-gray-900 dark:text-gray-100">
                                Punctuality
                            </span>
                            <span class="block text-[10px] text-gray-400 dark:text-gray-500">
                                Shift Compliance
                            </span>
                        </div>
                    </div>
                    @if ($lateMinutes > 0)
                        <x-filament::badge color="warning" size="xs">
                            {{ $lateMinutes }}m Late
                        </x-filament::badge>
                    @else
                        <x-filament::badge color="success" size="xs">
                            On Time
                        </x-filament::badge>
                    @endif
                </div>

                <div class="mt-3.5 flex items-baseline gap-1.5">
                    <span class="text-3xl font-extrabold tracking-tight {{ $lateMinutes > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-950 dark:text-white' }}">
                        {{ $lateMinutes }}
                    </span>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        late arrival minutes
                    </span>
                </div>

                <div class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                    @php $punctualScore = max(0, 100 - min(100, (int) round(($lateMinutes / 120) * 100))); @endphp
                    <div class="h-full rounded-full bg-amber-500 dark:bg-amber-400 transition-all duration-300" style="width: {{ $punctualScore }}%"></div>
                </div>
            </div>

            <div class="mt-3.5 flex items-center justify-between border-t border-gray-100 dark:border-white/5 pt-2 text-[11px] text-gray-500 dark:text-gray-400">
                <span>Early Leave: <strong class="text-gray-800 dark:text-gray-200">{{ $earlyMinutes }}m</strong></span>
                <span class="font-medium text-amber-600 dark:text-amber-400">Shift Window</span>
            </div>
        </div>

        {{-- Card 4: Hours Logged & Overtime --}}
        <div class="erp-att-card erp-att-card-hours">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400">
                            <x-filament::icon icon="heroicon-o-briefcase" class="h-4 w-4" />
                        </span>
                        <div>
                            <span class="block text-xs font-bold uppercase tracking-wider text-gray-900 dark:text-gray-100">
                                Working Time
                            </span>
                            <span class="block text-[10px] text-gray-400 dark:text-gray-500">
                                Total Worked
                            </span>
                        </div>
                    </div>
                    @if ($overtimeHours > 0)
                        <x-filament::badge color="primary" size="xs">
                            +{{ number_format($overtimeHours, 1) }}h OT
                        </x-filament::badge>
                    @else
                        <x-filament::badge color="info" size="xs">
                            Standard
                        </x-filament::badge>
                    @endif
                </div>

                <div class="mt-3.5 flex items-baseline gap-1.5">
                    <span class="text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                        {{ number_format($workedHours, 1) }}
                    </span>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        logged hours
                    </span>
                </div>

                <div class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                    @php $hoursPct = min(100, (int) round(($workedHours / max(1, $workingDays * 8)) * 100)); @endphp
                    <div class="h-full rounded-full bg-sky-500 dark:bg-sky-400 transition-all duration-300" style="width: {{ $hoursPct }}%"></div>
                </div>
            </div>

            <div class="mt-3.5 flex items-center justify-between border-t border-gray-100 dark:border-white/5 pt-2 text-[11px] text-gray-500 dark:text-gray-400">
                <span>Overtime: <strong class="text-gray-800 dark:text-gray-200">{{ number_format($overtimeHours, 1) }} hrs</strong></span>
                <span class="font-medium text-sky-600 dark:text-sky-400">8.0h Shift Base</span>
            </div>
        </div>
    </div>
</div>
