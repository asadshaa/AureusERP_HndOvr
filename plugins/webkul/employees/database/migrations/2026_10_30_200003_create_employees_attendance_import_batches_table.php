<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One uploaded biometric / device attendance file: the stored file, who
 * previewed and imported it and when, the pairing mode used, the counts and
 * the undo. The punches themselves are in employees_attendance_import_rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees_attendance_import_batches')) {
            return;
        }

        Schema::create('employees_attendance_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->char('file_hash', 64)->nullable();
            $table->string('file_format', 30)->nullable();
            $table->string('date_order', 10)->nullable();
            $table->string('pairing_mode', 20)->default('first_last');
            $table->string('timezone', 64);
            $table->string('status', 30)->default('preview');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('problem_rows')->default(0);
            $table->unsignedInteger('days_written')->default(0);
            $table->unsignedInteger('review_days')->default(0);
            $table->unsignedInteger('flagged_days')->default(0);
            $table->json('summary')->nullable();
            $table->json('undo_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'created_at'], 'attendance_import_batches_company_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_attendance_import_batches');
    }
};
