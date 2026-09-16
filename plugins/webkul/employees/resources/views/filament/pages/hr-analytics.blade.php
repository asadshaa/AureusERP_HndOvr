<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            'Headcount' => $analytics['headcount'],
            'Turnover rate' => number_format($analytics['turnover_rate'], 2).'%',
            'Late arrivals' => $analytics['late_arrivals'],
            'Leave days' => number_format($analytics['leave_days'], 2),
            'Timesheet compliance' => number_format($analytics['timesheet_compliance_rate'], 2).'%',
            'Utilization' => number_format($analytics['utilization_rate'], 2).'%',
            'Monthly payroll baseline' => $analytics['employees_with_salary_data'] > 0
                ? number_format($analytics['monthly_payroll_cost'], 2)
                : 'No salary data recorded',
            'Open employee requests' => $analytics['open_requests'],
            'Claims & reimbursements' => $analytics['claims_and_reimbursements']['count'].' ('.number_format($analytics['claims_and_reimbursements']['amount'], 2).')',
        ] as $label => $value)
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-2 text-2xl font-semibold">{{ $value }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Headcount by department">
            <div class="space-y-3">
                @forelse ($analytics['department_distribution'] as $row)
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                        <span>{{ $row['name'] }}</span><span class="font-medium">{{ $row['total'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No active employees in this company.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section heading="Recruitment funnel">
            <div class="space-y-3">
                @forelse ($analytics['recruitment_funnel'] as $row)
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                        <span>{{ $row['stage'] }}</span><span class="font-medium">{{ $row['total'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No applications in the selected period.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section heading="Applicant source effectiveness">
            <div class="space-y-3">
                @forelse ($analytics['applicant_sources'] as $row)
                    <div class="grid grid-cols-3 border-b border-gray-100 pb-2 dark:border-gray-800">
                        <span>{{ $row['source'] }}</span>
                        <span class="text-right">{{ $row['total'] }} applicants</span>
                        <span class="text-right font-medium">{{ $row['hired'] }} hired</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No source data in the selected period.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section heading="Workforce indicators">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-gray-500">Attendance overtime</dt><dd class="font-medium">{{ number_format($analytics['attendance_overtime_hours'], 2) }} h</dd></div>
                <div><dt class="text-gray-500">Timesheet overtime</dt><dd class="font-medium">{{ number_format($analytics['timesheet_overtime_hours'], 2) }} h</dd></div>
                <div><dt class="text-gray-500">Average performance</dt><dd class="font-medium">{{ number_format($analytics['average_performance'], 2) }}</dd></div>
                <div><dt class="text-gray-500">Average time to hire</dt><dd class="font-medium">{{ number_format($analytics['average_time_to_hire_days'], 2) }} days</dd></div>
                <div class="col-span-2"><dt class="text-gray-500">Approved financial HR requests</dt><dd class="font-medium">{{ number_format($analytics['approved_financial_requests'], 2) }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Performance distribution">
            <div class="space-y-3">
                @forelse ($analytics['performance_distribution'] as $row)
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                        <span>Rating {{ $row['rating'] }}</span><span class="font-medium">{{ $row['total'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No completed reviews in this company yet.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section heading="Employee movement">
            <div class="space-y-3">
                @forelse ($analytics['employee_movement'] as $row)
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                        <span>{{ $row['status'] }}</span><span class="font-medium">{{ $row['total'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No status changes recorded in the selected period.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
