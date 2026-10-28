<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several check-in / check-out pairs per day. The daily row in
 * employees_attendance_records stays the day summary (check_in = first in,
 * check_out = last out, worked_hours = sum of intervals); each pair is one
 * row here. Additive: the backfill copies every existing record's pair into
 * one interval and never changes the record itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_attendance_intervals')) {
            Schema::create('employees_attendance_intervals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
                $table->foreignId('attendance_record_id')->constrained('employees_attendance_records')->cascadeOnDelete();
                $table->timestamp('check_in');
                $table->timestamp('check_out')->nullable();
                $table->string('source', 40)->default('manual');
                $table->foreignId('start_verification_id')->nullable()
                    ->constrained('employees_attendance_verifications')->nullOnDelete();
                $table->foreignId('end_verification_id')->nullable()
                    ->constrained('employees_attendance_verifications')->nullOnDelete();
                $table->string('verification_status', 20)->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('void_reason')->nullable();
                $table->timestamps();

                $table->index(['employee_id', 'check_in'], 'attendance_intervals_employee_in_index');
                $table->index(['attendance_record_id', 'voided_at'], 'attendance_intervals_record_index');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_attendance_intervals');
    }

    /** One interval per existing record that has a check-in and no interval yet (idempotent). */
    private function backfill(): void
    {
        DB::table('employees_attendance_records')
            ->whereNotNull('check_in')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('employees_attendance_intervals')
                ->whereColumn('employees_attendance_intervals.attendance_record_id', 'employees_attendance_records.id'))
            ->orderBy('id')
            ->chunkById(500, function ($records): void {
                $now = now();

                DB::table('employees_attendance_intervals')->insert($records->map(fn ($record): array => [
                    'company_id'            => $record->company_id,
                    'employee_id'           => $record->employee_id,
                    'attendance_record_id'  => $record->id,
                    'check_in'              => $record->check_in,
                    // A check-out at or before the check-in is not a usable pair: keep the interval open.
                    'check_out'             => $record->check_out !== null && $record->check_out > $record->check_in ? $record->check_out : null,
                    'source'                => 'system_backfill',
                    'start_verification_id' => $record->check_in_verification_id,
                    'end_verification_id'   => $record->check_out_verification_id,
                    'verification_status'   => $record->verification_status,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ])->all());
            });
    }
};
