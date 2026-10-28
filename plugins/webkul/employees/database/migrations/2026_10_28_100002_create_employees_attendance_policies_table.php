<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company attendance time-bank policy (grace allowance, daily extra
 * credit cap, carry-over). A company without a row uses the defaults in
 * config/attendance.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees_attendance_policies')) {
            return;
        }

        Schema::create('employees_attendance_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->unsignedInteger('monthly_grace_minutes')->default(0);
            $table->unsignedInteger('daily_extra_credit_cap_minutes')->default(60);
            $table->boolean('carry_over')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_attendance_policies');
    }
};
