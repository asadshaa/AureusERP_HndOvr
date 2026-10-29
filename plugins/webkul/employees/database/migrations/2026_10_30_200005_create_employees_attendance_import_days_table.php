<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A snapshot of each attendance day a device import wrote: which batch wrote
 * it, whether the batch created the day, and a fingerprint of the day
 * (times, status, notes and live intervals) right after the write. A day
 * whose live fingerprint still equals its current snapshot is untouched
 * since the import, so a later import may add punches to it and an undo may
 * remove it; any other day counts as manually corrected and is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees_attendance_import_days')) {
            return;
        }

        Schema::create('employees_attendance_import_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('employees_attendance_import_batches')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->foreignId('attendance_record_id')->nullable()->constrained('employees_attendance_records', 'id', 'attendance_import_days_record_fk')->nullOnDelete();
            $table->boolean('record_created')->default(false);
            $table->unsignedSmallInteger('punch_count')->default(0);
            $table->boolean('needs_review')->default(false);
            $table->char('fingerprint', 40);
            $table->string('status', 20)->default('current');
            $table->timestamps();

            $table->index(['attendance_record_id', 'status'], 'attendance_import_days_record_index');
            $table->index(['batch_id', 'status'], 'attendance_import_days_batch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_attendance_import_days');
    }
};
