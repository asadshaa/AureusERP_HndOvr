<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user number an employee has on the attendance device (ZKTeco "AC-No.",
 * "User ID", "Enroll No."). Device file imports match punches on it first.
 * Additive and nullable; unique per company (MySQL allows many NULLs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees_employees', 'biometric_id')) {
            return;
        }

        Schema::table('employees_employees', function (Blueprint $table): void {
            $table->string('biometric_id', 32)->nullable()->after('barcode');

            $table->unique(['company_id', 'biometric_id'], 'employees_company_biometric_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees_employees', 'biometric_id')) {
            return;
        }

        Schema::table('employees_employees', function (Blueprint $table): void {
            $table->dropUnique('employees_company_biometric_unique');
            $table->dropColumn('biometric_id');
        });
    }
};
