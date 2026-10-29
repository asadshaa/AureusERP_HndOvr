<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every company now has its own Annual, Sick and Casual Leave
 * (LeaveWorkflowSeeder::provisionLeaveTypes()). The old shared copies (no
 * company) still showed in the request form and were refused for lack of an
 * allocation, so they are deactivated. Only copies nobody ever used (no
 * allocation, no leave) are touched; nothing is deleted.
 */
return new class extends Migration
{
    private const NAMES = ['Annual Leave', 'Casual Leave', 'Sick Leave'];

    public function up(): void
    {
        if (! Schema::hasTable('time_off_leave_types')) {
            return;
        }

        $this->unusedSharedTypes()->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('time_off_leave_types')) {
            return;
        }

        $this->unusedSharedTypes()->update(['is_active' => true, 'updated_at' => now()]);
    }

    private function unusedSharedTypes(): Builder
    {
        return DB::table('time_off_leave_types')
            ->whereNull('company_id')
            ->whereIn('name', self::NAMES)
            ->whereNotExists(fn (Builder $query) => $query->from('time_off_leave_allocations')->whereColumn('time_off_leave_allocations.holiday_status_id', 'time_off_leave_types.id'))
            ->whereNotExists(fn (Builder $query) => $query->from('time_off_leaves')->whereColumn('time_off_leaves.holiday_status_id', 'time_off_leave_types.id'));
    }
};
