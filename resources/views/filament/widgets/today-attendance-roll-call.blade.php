<x-filament-widgets::widget>
    <style>
        .roll-call-card {
            background-color: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 0.75rem;
            padding: 1rem;
            transition: all 0.15s ease-in-out;
        }
        .roll-call-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-card {
            background-color: #18181b !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-card:hover {
            border-color: rgba(255, 255, 255, 0.25) !important;
        }

        /* Titles and Subtitles in Dark Mode */
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-label {
            color: #9ca3af !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-sub {
            color: #6b7280 !important;
        }

        /* Metric values in Dark mode */
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-val-total {
            color: #ffffff !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-val-present {
            color: #34d399 !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-val-late {
            color: #fbbf24 !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-val-leave {
            color: #60a5fa !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-val-absent {
            color: #f87171 !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-val-review {
            color: #c084fc !important;
        }

        /* Icon badge backgrounds in dark mode */
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-icon-total {
            background-color: rgba(255, 255, 255, 0.08) !important;
            color: #e5e7eb !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-icon-present {
            background-color: rgba(16, 185, 129, 0.15) !important;
            color: #34d399 !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-icon-late {
            background-color: rgba(245, 158, 11, 0.15) !important;
            color: #fbbf24 !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-icon-leave {
            background-color: rgba(59, 130, 246, 0.15) !important;
            color: #60a5fa !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-icon-absent {
            background-color: rgba(239, 68, 68, 0.15) !important;
            color: #f87171 !important;
        }
        :is(.dark, :root.dark, [class~="dark"]) .roll-call-icon-review {
            background-color: rgba(168, 85, 247, 0.15) !important;
            color: #c084fc !important;
        }
    </style>

    <x-filament::section class="overflow-hidden">
        <x-slot name="heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950/50 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-user-group" class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                            Today's Roll Call & Attendance
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $todayDate }} · Live company attendance status
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-filament::button
                        :href="$attendanceUrl"
                        tag="a"
                        size="xs"
                        color="gray"
                        outlined
                        icon="heroicon-m-arrow-top-right-on-square"
                    >
                        Attendance Register
                    </x-filament::button>
                </div>
            </div>
        </x-slot>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.875rem;" class="pt-1">
            {{-- Total Workforce --}}
            <a
                href="{{ $attendanceUrl }}"
                class="roll-call-card group relative flex flex-col justify-between shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <div class="roll-call-icon-total flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-600">
                        <x-filament::icon icon="heroicon-o-users" class="h-4 w-4" />
                    </div>
                    <span class="roll-call-label text-xs font-semibold text-gray-600">Total Staff</span>
                </div>
                <div class="roll-call-val-total mt-3 text-2xl font-bold tracking-tight text-gray-950">
                    {{ $totalEmployees }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-gray-500">
                    <span class="roll-call-sub">Active headcount</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-primary-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- Present / Checked In --}}
            <a
                href="{{ $attendanceUrl }}?tableFilters[status][value]=present"
                class="roll-call-card group relative flex flex-col justify-between shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <div class="roll-call-icon-present flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400">Checked In</span>
                    </div>
                </div>
                <div class="roll-call-val-present mt-3 text-2xl font-bold tracking-tight text-emerald-900">
                    {{ $presentCount }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-emerald-700/80 dark:text-emerald-400/80">
                    <span class="roll-call-sub">Present on shift</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-emerald-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- Late Arrivals --}}
            <a
                href="{{ $attendanceUrl }}"
                class="roll-call-card group relative flex flex-col justify-between shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <div class="roll-call-icon-late flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                    </div>
                    <span class="text-xs font-semibold text-amber-700 dark:text-amber-400">Late Arrivals</span>
                </div>
                <div class="roll-call-val-late mt-3 text-2xl font-bold tracking-tight text-amber-900">
                    {{ $lateCount }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-amber-700/80 dark:text-amber-400/80">
                    <span class="roll-call-sub">After shift start</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-amber-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- On Leave --}}
            <a
                href="{{ url('/admin/time-off/management/time-offs') }}"
                class="roll-call-card group relative flex flex-col justify-between shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <div class="roll-call-icon-leave flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                        <x-filament::icon icon="heroicon-o-calendar" class="h-4 w-4" />
                    </div>
                    <span class="text-xs font-semibold text-blue-700 dark:text-blue-400">On Leave</span>
                </div>
                <div class="roll-call-val-leave mt-3 text-2xl font-bold tracking-tight text-blue-900">
                    {{ $onLeaveCount }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-blue-700/80 dark:text-blue-400/80">
                    <span class="roll-call-sub">Approved Time Off</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-blue-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- Not Checked In --}}
            <a
                href="{{ $attendanceUrl }}?tableFilters[status][value]=absent"
                class="roll-call-card group relative flex flex-col justify-between shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <div class="roll-call-icon-absent flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600">
                        <x-filament::icon icon="heroicon-o-arrow-right-on-rectangle" class="h-4 w-4" />
                    </div>
                    <span class="text-xs font-semibold text-rose-700 dark:text-rose-400">Not Checked In</span>
                </div>
                <div class="roll-call-val-absent mt-3 text-2xl font-bold tracking-tight text-rose-900">
                    {{ $notCheckedIn }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-rose-700/80 dark:text-rose-400/80">
                    <span class="roll-call-sub">Pending arrival / absent</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-rose-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- Needs Review --}}
            <a
                href="{{ $attendanceUrl }}?tableFilters[verification_status][value]=needs_review"
                class="roll-call-card group relative flex flex-col justify-between shadow-sm"
            >
                <div class="flex items-center justify-between">
                    <div class="roll-call-icon-review flex h-8 w-8 items-center justify-center rounded-lg bg-purple-50 text-purple-600">
                        <x-filament::icon icon="heroicon-o-shield-exclamation" class="h-4 w-4" />
                    </div>
                    <span class="text-xs font-semibold text-purple-700 dark:text-purple-400">Needs Review</span>
                </div>
                <div class="roll-call-val-review mt-3 text-2xl font-bold tracking-tight text-purple-900">
                    {{ $needsReviewCount }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-purple-700/80 dark:text-purple-400/80">
                    <span class="roll-call-sub">Flagged punches</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-purple-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
