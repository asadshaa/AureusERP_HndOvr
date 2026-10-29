<x-filament-widgets::widget>
    @php
        $emp = $employee ?? [];
        $leaves = $leaveSummary ?? ['cards' => []];
        $recent = $recentLeaves ?? [];
        $urls = $urls ?? [];
        $cards = $leaves['cards'] ?? [];

        $totalAllocated = (float) ($leaves['total_allocated'] ?? 25.0);
        $totalLeft = (float) ($leaves['total_left'] ?? 25.0);
        $totalTaken = (float) ($leaves['total_taken'] ?? 0.0);
        $totalPending = (float) ($leaves['total_pending'] ?? 0.0);
        $totalPct = $totalAllocated > 0 ? min(100, max(0, (int) round(($totalLeft / $totalAllocated) * 100))) : 0;
    @endphp

    <style>
        .erp-leaves-container {
            border-radius: 0.875rem;
            border: 1px solid rgba(120, 120, 128, 0.15);
            background: rgba(255, 255, 255, 0.7);
            padding: 1.25rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.03);
            backdrop-filter: blur(8px);
        }
        :is(.dark) .erp-leaves-container {
            border-color: rgba(255, 255, 255, 0.08);
            background: rgba(24, 24, 27, 0.65);
            box-shadow: none;
        }

        .erp-leaves-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 0.875rem;
        }
        @media (min-width: 640px) {
            .erp-leaves-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (min-width: 1024px) {
            .erp-leaves-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        .erp-leave-card {
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
        :is(.dark) .erp-leave-card {
            border-color: rgba(255, 255, 255, 0.07);
            background: rgba(255, 255, 255, 0.025);
        }
        .erp-leave-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.06);
            border-color: rgba(120, 120, 128, 0.28);
        }
        :is(.dark) .erp-leave-card:hover {
            box-shadow: 0 4px 16px -2px rgba(0, 0, 0, 0.35);
            border-color: rgba(255, 255, 255, 0.15);
            background: rgba(255, 255, 255, 0.04);
        }

        .erp-leave-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }
        .erp-card-total::before { background: linear-gradient(90deg, #10b981, #059669); }
        .erp-card-annual::before { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .erp-card-casual::before { background: linear-gradient(90deg, #0284c7, #0369a1); }
        .erp-card-sick::before { background: linear-gradient(90deg, #f43f5e, #e11d48); }
    </style>

    <div class="erp-leaves-container">
        {{-- Clean Workspace Identity Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 pb-3 border-b border-gray-200/80 dark:border-white/10">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950/60 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-base font-bold text-gray-950 dark:text-white tracking-tight">
                            {{ $emp['name'] ?? 'My Leave Overview' }}
                        </h3>
                        @if (!empty($emp['number']))
                            <x-filament::badge color="gray" size="sm">
                                {{ $emp['number'] }}
                            </x-filament::badge>
                        @endif
                        @if (!empty($emp['job_title']) && $emp['job_title'] !== '—')
                            <x-filament::badge color="primary" size="sm">
                                {{ $emp['job_title'] }}
                            </x-filament::badge>
                        @endif
                        @if (!empty($emp['department']) && $emp['department'] !== '—')
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                • {{ $emp['department'] }}
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        Time off entitlements & remaining balance summary ({{ number_format($totalAllocated, 0) }} Days Total Entitlement)
                        @if (!empty($emp['manager_name']) && $emp['manager_name'] !== 'Not Assigned')
                            <span class="text-gray-400 dark:text-gray-600">•</span>
                            <span>Manager: <strong class="text-gray-700 dark:text-gray-300 font-medium">{{ $emp['manager_name'] }}</strong></span>
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 self-start sm:self-auto shrink-0">
                @if (!empty($urls['request_leave']))
                    <x-filament::button
                        tag="a"
                        :href="$urls['request_leave']"
                        size="xs"
                        color="primary"
                        icon="heroicon-m-plus"
                    >
                        Request Time Off
                    </x-filament::button>
                @endif

                @if (!empty($urls['profile']))
                    <x-filament::button
                        tag="a"
                        :href="$urls['profile']"
                        size="xs"
                        color="gray"
                        outlined
                        icon="heroicon-m-user"
                    >
                        My Profile
                    </x-filament::button>
                @endif
            </div>
        </div>

        {{-- 4 Symmetrical Dashboard Stat Cards --}}
        <div class="erp-leaves-grid">
            {{-- Card 1: Total Leave Balance --}}
            <a
                href="{{ $urls['my_leaves'] ?? url('/admin/time-off/dashboard/my-time-offs') }}"
                class="erp-leave-card erp-card-total group block text-inherit no-underline cursor-pointer"
            >
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                                <x-filament::icon icon="heroicon-o-check-badge" class="h-4 w-4" />
                            </span>
                            <div>
                                <span class="block text-xs font-bold uppercase tracking-wider text-gray-900 dark:text-gray-100 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                                    Total Balance
                                </span>
                                <span class="block text-3xs text-gray-400 dark:text-gray-500">
                                    All Categories
                                </span>
                            </div>
                        </div>
                        <x-filament::badge color="success" size="xs">
                            {{ number_format($totalAllocated, 0) }} Total
                        </x-filament::badge>
                    </div>

                    <div class="mt-3.5 flex items-baseline gap-1.5">
                        <span class="text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                            {{ number_format($totalLeft, 1) }}
                        </span>
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                            / {{ number_format($totalAllocated, 1) }} days left
                        </span>
                    </div>

                    {{-- Progress Bar --}}
                    <div class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="h-full rounded-full bg-emerald-500 dark:bg-emerald-400 transition-all duration-300" style="width: {{ $totalPct }}%"></div>
                    </div>
                </div>

                <div class="mt-3.5 flex items-center justify-between border-t border-gray-100 dark:border-white/5 pt-2 text-2xs text-gray-500 dark:text-gray-400">
                    <span>Used: <strong class="text-gray-800 dark:text-gray-200">{{ number_format($totalTaken, 1) }}</strong></span>
                    <div class="flex items-center gap-1.5">
                        @if ($totalPending > 0)
                            <span class="font-medium text-amber-600 dark:text-amber-400">Pending: {{ number_format($totalPending, 1) }}</span>
                        @else
                            <span class="font-medium text-emerald-600 dark:text-emerald-400">Active</span>
                        @endif
                        <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-primary-500 opacity-0 transition-opacity group-hover:opacity-100" />
                    </div>
                </div>
            </a>

            {{-- Cards 2, 3, 4: Annual (12), Casual (5), Sick (8) --}}
            @foreach ($cards as $card)
                @php
                    $themeKey = $card['key'] ?? 'annual';
                    $iconComponent = match($card['icon'] ?? '') {
                        'heroicon-o-sun'      => 'heroicon-o-sun',
                        'heroicon-o-sparkles' => 'heroicon-o-sparkles',
                        'heroicon-o-heart'    => 'heroicon-o-heart',
                        default               => 'heroicon-o-calendar',
                    };

                    $iconWrapStyle = match($themeKey) {
                        'annual' => 'bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400',
                        'casual' => 'bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400',
                        'sick'   => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400',
                        default  => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                    };

                    $barColor = match($themeKey) {
                        'annual' => 'bg-amber-500 dark:bg-amber-400',
                        'casual' => 'bg-sky-500 dark:bg-sky-400',
                        'sick'   => 'bg-rose-500 dark:bg-rose-400',
                        default  => 'bg-primary-500 dark:bg-primary-400',
                    };

                    $badgeColor = match($themeKey) {
                        'annual' => 'warning',
                        'casual' => 'info',
                        'sick'   => 'danger',
                        default  => 'gray',
                    };

                    $subtitle = match($themeKey) {
                        'annual' => 'Vacation & Rest',
                        'casual' => 'Short Notice',
                        'sick'   => 'Health & Medical',
                        default  => 'Time Off',
                    };
                @endphp

                <a
                    href="{{ $urls['my_leaves'] ?? url('/admin/time-off/dashboard/my-time-offs') }}"
                    class="erp-leave-card erp-card-{{ $themeKey }} group block text-inherit no-underline cursor-pointer"
                >
                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg {{ $iconWrapStyle }}">
                                    <x-filament::icon :icon="$iconComponent" class="h-4 w-4" />
                                </span>
                                <div>
                                    <span class="block text-xs font-bold uppercase tracking-wider text-gray-900 dark:text-gray-100 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                                        {{ $card['name'] }}
                                    </span>
                                    <span class="block text-3xs text-gray-400 dark:text-gray-500">
                                        {{ $subtitle }}
                                    </span>
                                </div>
                            </div>
                            <x-filament::badge :color="$badgeColor" size="xs">
                                {{ number_format($card['allocated'], 0) }} Alloc
                            </x-filament::badge>
                        </div>

                        <div class="mt-3.5 flex items-baseline gap-1.5">
                            <span class="text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                                {{ number_format($card['left'], 1) }}
                            </span>
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                / {{ number_format($card['allocated'], 1) }} days left
                            </span>
                        </div>

                        {{-- Progress Bar --}}
                        <div class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="h-full rounded-full {{ $barColor }} transition-all duration-300" style="width: {{ $card['percentage'] }}%"></div>
                        </div>
                    </div>

                    <div class="mt-3.5 flex items-center justify-between border-t border-gray-100 dark:border-white/5 pt-2 text-2xs text-gray-500 dark:text-gray-400">
                        <span>Used: <strong class="text-gray-800 dark:text-gray-200">{{ number_format($card['taken'], 1) }}</strong></span>
                        <div class="flex items-center gap-1.5">
                            @if (($card['pending'] ?? 0) > 0)
                                <span class="font-medium text-amber-600 dark:text-amber-400">Pending: {{ number_format($card['pending'], 1) }}</span>
                            @else
                                <span class="text-gray-400 dark:text-gray-500">{{ $card['percentage'] }}% Left</span>
                            @endif
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-primary-500 opacity-0 transition-opacity group-hover:opacity-100" />
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        {{-- Subtle Recent Applications Footer --}}
        @if (!empty($recent))
            <div class="mt-3.5 pt-2.5 border-t border-gray-100 dark:border-white/5 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span class="text-2xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-clock" class="h-3.5 w-3.5 text-gray-400" />
                    Recent Applications:
                </span>
                <div class="flex flex-wrap items-center gap-3">
                    @foreach ($recent as $r)
                        @php
                            $rColor = match($r['status_color']) {
                                'success' => 'success',
                                'warning' => 'warning',
                                'danger'  => 'danger',
                                'info'    => 'info',
                                default   => 'gray',
                            };
                        @endphp
                        <a
                            href="{{ $urls['my_leaves'] ?? url('/admin/time-off/dashboard/my-time-offs') }}"
                            class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                        >
                            <span class="font-medium text-gray-900 dark:text-white">{{ $r['type'] }}</span>
                            <span class="text-gray-400 dark:text-gray-500">({{ $r['days'] }}d, {{ $r['date_range'] }})</span>
                            <x-filament::badge :color="$rColor" size="xs">
                                {{ $r['status_label'] }}
                            </x-filament::badge>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
