<div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3 dark:border-gray-800">
        <div class="flex items-center gap-2">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950/50 dark:text-primary-400">
                <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
            </span>
            <div>
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $summary['month'] }} Attendance Overview
                </h4>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Weekends excluded &bull; {{ $summary['working_days'] }} working days total in month
                </p>
            </div>
        </div>
        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">
            Employee: <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $employee->name }}</span>
        </div>
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
        {{-- Present --}}
        <div class="flex flex-col rounded-lg border border-emerald-100 bg-emerald-50/50 p-2.5 text-center dark:border-emerald-900/30 dark:bg-emerald-950/20">
            <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">Present</span>
            <span class="mt-1 text-xl font-bold text-emerald-950 dark:text-emerald-200">{{ $summary['present'] }}</span>
            <span class="text-[10px] text-emerald-600 dark:text-emerald-500">Days</span>
        </div>

        {{-- Half Day --}}
        <div class="flex flex-col rounded-lg border border-amber-100 bg-amber-50/50 p-2.5 text-center dark:border-amber-900/30 dark:bg-amber-950/20">
            <span class="text-xs font-medium text-amber-700 dark:text-amber-400">Half Day</span>
            <span class="mt-1 text-xl font-bold text-amber-950 dark:text-amber-200">{{ $summary['half_day'] }}</span>
            <span class="text-[10px] text-amber-600 dark:text-amber-500">Days</span>
        </div>

        {{-- Absent --}}
        <div class="flex flex-col rounded-lg border border-rose-100 bg-rose-50/50 p-2.5 text-center dark:border-rose-900/30 dark:bg-rose-950/20">
            <span class="text-xs font-medium text-rose-700 dark:text-rose-400">Absent</span>
            <span class="mt-1 text-xl font-bold text-rose-950 dark:text-rose-200">{{ $summary['absent'] }}</span>
            <span class="text-[10px] text-rose-600 dark:text-rose-500">Days</span>
        </div>

        {{-- Leave --}}
        <div class="flex flex-col rounded-lg border border-sky-100 bg-sky-50/50 p-2.5 text-center dark:border-sky-900/30 dark:bg-sky-950/20">
            <span class="text-xs font-medium text-sky-700 dark:text-sky-400">Leaves</span>
            <span class="mt-1 text-xl font-bold text-sky-950 dark:text-sky-200">{{ $summary['leave'] }}</span>
            <span class="text-[10px] text-sky-600 dark:text-sky-500">Days</span>
        </div>

        {{-- Late Minutes --}}
        <div class="flex flex-col rounded-lg border border-orange-100 bg-orange-50/50 p-2.5 text-center dark:border-orange-900/30 dark:bg-orange-950/20">
            <span class="text-xs font-medium text-orange-700 dark:text-orange-400">Late Arrival</span>
            <span class="mt-1 text-xl font-bold text-orange-950 dark:text-orange-200">{{ $summary['late_minutes'] }}</span>
            <span class="text-[10px] text-orange-600 dark:text-orange-500">Minutes</span>
        </div>

        {{-- Worked Hours --}}
        <div class="flex flex-col rounded-lg border border-indigo-100 bg-indigo-50/50 p-2.5 text-center dark:border-indigo-900/30 dark:bg-indigo-950/20">
            <span class="text-xs font-medium text-indigo-700 dark:text-indigo-400">Worked</span>
            <span class="mt-1 text-xl font-bold text-indigo-950 dark:text-indigo-200">{{ number_format($summary['worked_hours'], 1) }}</span>
            <span class="text-[10px] text-indigo-600 dark:text-indigo-500">Hours</span>
        </div>

        {{-- Overtime --}}
        <div class="flex flex-col rounded-lg border border-purple-100 bg-purple-50/50 p-2.5 text-center dark:border-purple-900/30 dark:bg-purple-950/20">
            <span class="text-xs font-medium text-purple-700 dark:text-purple-400">Overtime</span>
            <span class="mt-1 text-xl font-bold text-purple-950 dark:text-purple-200">{{ number_format($summary['overtime_hours'], 1) }}</span>
            <span class="text-[10px] text-purple-600 dark:text-purple-500">Hours</span>
        </div>
    </div>
</div>
