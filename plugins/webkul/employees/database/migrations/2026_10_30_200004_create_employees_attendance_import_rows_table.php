<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every row of an uploaded device file, as read from the file (identifier,
 * date-time, direction and the original cells) plus what the import made of
 * it (matched employee, punch time in UTC, status, message). punch_key
 * ("employee_id|Y-m-d H:i:s" UTC) is set only while the punch is imported
 * and live, so the unique index makes a punch importable exactly once; an
 * undo clears it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees_attendance_import_rows')) {
            return;
        }

        Schema::create('employees_attendance_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('employees_attendance_import_batches')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('identifier', 64)->nullable();
            $table->string('employee_code', 64)->nullable();
            $table->string('employee_name')->nullable();
            $table->string('raw_datetime', 64)->nullable();
            $table->string('local_datetime', 19)->nullable();
            $table->string('raw_direction', 32)->nullable();
            $table->string('direction', 3)->nullable();
            $table->json('raw')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees_employees')->nullOnDelete();
            $table->timestamp('punched_at')->nullable();
            $table->date('attendance_date')->nullable();
            $table->string('status', 30);
            $table->string('message')->nullable();
            $table->string('punch_key', 40)->nullable()->unique('attendance_import_rows_punch_key_unique');
            $table->foreignId('attendance_record_id')->nullable()->constrained('employees_attendance_records', 'id', 'attendance_import_rows_record_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['batch_id', 'status'], 'attendance_import_rows_batch_index');
            $table->index(['employee_id', 'attendance_date'], 'attendance_import_rows_employee_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_attendance_import_rows');
    }
};
