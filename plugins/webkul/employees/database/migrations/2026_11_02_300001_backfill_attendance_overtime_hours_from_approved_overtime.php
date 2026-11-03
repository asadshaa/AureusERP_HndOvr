<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Webkul\Employee\Services\Attendance\OvertimeService;

/**
 * employees_attendance_records.overtime_hours is now filled from approved
 * overtime requests (OvertimeService) instead of being typed by hand. This
 * gives the days whose overtime was approved before that change their hours,
 * but only where the column is still 0: a value already on the record (typed
 * by hand or seeded) is left alone, and so is a day of a finalised month.
 * Idempotent; the counts are written to the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_attendance_records') || ! Schema::hasTable('employees_requests')) {
            return;
        }

        $counts = app(OvertimeService::class)->backfillAttendanceOvertimeHours();

        Log::info('Attendance overtime_hours backfill from approved overtime.', $counts);
    }

    public function down(): void
    {
        // Additive data only: the hours match approved overtime requests.
    }
};
