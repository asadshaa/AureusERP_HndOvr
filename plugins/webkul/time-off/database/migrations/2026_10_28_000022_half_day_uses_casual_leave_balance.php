<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decision (2026-10-28): "Half day" needs no allocation of its own. It
 * counts 0.5 day against the employee's Casual Leave (Annual Leave when the
 * company has no Casual Leave type) in the balance shown on the request form
 * and in the balance check (LeaveBalanceService). Only the seeded "Half day"
 * types (half-day unit) are changed; the previous value is not needed to
 * roll back because it was copied from Casual Leave ("yes").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('time_off_leave_types')) {
            return;
        }

        DB::table('time_off_leave_types')
            ->where('name', 'Half day')
            ->where('request_unit', 'half_day')
            ->update(['requires_allocation' => 'no', 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('time_off_leave_types')) {
            return;
        }

        DB::table('time_off_leave_types')
            ->where('name', 'Half day')
            ->where('request_unit', 'half_day')
            ->update(['requires_allocation' => 'yes', 'updated_at' => now()]);
    }
};
