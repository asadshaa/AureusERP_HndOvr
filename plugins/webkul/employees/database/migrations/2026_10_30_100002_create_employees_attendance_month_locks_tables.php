<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance month finalise / lock by HR (HR parity plan 2.3):
 *
 *  - employees_attendance_month_locks: one row per company and month; status
 *    "finalised" locks the month, "reopened" unlocks it (the last re-open
 *    reason is kept on the row);
 *  - employees_attendance_month_snapshots: one row per employee of a
 *    finalised month, holding that employee's monthly report (summary, days,
 *    schedule) as JSON, so the payroll export stays stable. Replaced on the
 *    next finalise;
 *  - employees_attendance_month_lock_events: the audit trail (every finalise
 *    and re-open, who, when, why, and the open issues at the time).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_attendance_month_locks')) {
            Schema::create('employees_attendance_month_locks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', 'id', 'emp_att_lock_company_fk')->cascadeOnDelete();
                // "Y-m", the month in Asia/Karachi (attendance.monthly_report.timezone).
                $table->char('month', 7);
                $table->string('status', 20);
                $table->unsignedInteger('employees_count')->default(0);
                $table->json('open_issues')->nullable();
                $table->timestamp('finalised_at')->nullable();
                $table->foreignId('finalised_by')->nullable()->constrained('users', 'id', 'emp_att_lock_finalised_by_fk')->nullOnDelete();
                $table->timestamp('reopened_at')->nullable();
                $table->foreignId('reopened_by')->nullable()->constrained('users', 'id', 'emp_att_lock_reopened_by_fk')->nullOnDelete();
                $table->string('reopen_reason', 1000)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'month'], 'emp_att_lock_company_month_unique');
            });
        }

        if (! Schema::hasTable('employees_attendance_month_snapshots')) {
            Schema::create('employees_attendance_month_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('lock_id')->constrained('employees_attendance_month_locks', 'id', 'emp_att_snap_lock_fk')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained('companies', 'id', 'emp_att_snap_company_fk')->cascadeOnDelete();
                $table->char('month', 7);
                $table->foreignId('employee_id')->constrained('employees_employees', 'id', 'emp_att_snap_employee_fk')->cascadeOnDelete();
                $table->json('data');
                $table->timestamp('finalised_at');
                $table->foreignId('finalised_by')->nullable()->constrained('users', 'id', 'emp_att_snap_finalised_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'month', 'employee_id'], 'emp_att_snap_unique');
            });
        }

        if (! Schema::hasTable('employees_attendance_month_lock_events')) {
            Schema::create('employees_attendance_month_lock_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('lock_id')->constrained('employees_attendance_month_locks', 'id', 'emp_att_lock_event_lock_fk')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained('companies', 'id', 'emp_att_lock_event_company_fk')->cascadeOnDelete();
                $table->char('month', 7);
                $table->string('action', 20);
                $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'emp_att_lock_event_user_fk')->nullOnDelete();
                $table->string('reason', 1000)->nullable();
                $table->json('details')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['company_id', 'month'], 'emp_att_lock_event_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_attendance_month_lock_events');
        Schema::dropIfExists('employees_attendance_month_snapshots');
        Schema::dropIfExists('employees_attendance_month_locks');
    }
};
