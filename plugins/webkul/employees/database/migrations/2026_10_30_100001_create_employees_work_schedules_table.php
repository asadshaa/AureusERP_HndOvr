<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated work schedules per employee (HR parity plan 2.2). A day
 * without a schedule row uses the company default (config/attendance.php ->
 * monthly_report office hours). Periods of one employee never overlap
 * (enforced by Webkul\Employee\Models\WorkSchedule); history is kept by
 * ending a period (effective_to) and starting a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees_work_schedules')) {
            return;
        }

        Schema::create('employees_work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'emp_work_sched_company_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees', 'id', 'emp_work_sched_employee_fk')->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('required_minutes');
            // ISO-8601 weekday numbers (1 = Monday ... 7 = Sunday).
            $table->json('working_days');
            // The start may slide by up to this many minutes (the end slides with it).
            $table->unsignedSmallInteger('flexible_minutes')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'emp_work_sched_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'emp_work_sched_updated_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'effective_from'], 'emp_work_sched_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_work_schedules');
    }
};
