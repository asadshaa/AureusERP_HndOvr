<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a company's device punches become check-in / check-out pairs:
 * "first_last" (first punch in, last punch out; the default) or "paired"
 * (every punch paired in order). Additive with a default, so existing
 * policy rows keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_attendance_policies') || Schema::hasColumn('employees_attendance_policies', 'punch_pairing_mode')) {
            return;
        }

        Schema::table('employees_attendance_policies', function (Blueprint $table): void {
            $table->string('punch_pairing_mode', 20)->default('first_last')->after('carry_over');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employees_attendance_policies') || ! Schema::hasColumn('employees_attendance_policies', 'punch_pairing_mode')) {
            return;
        }

        Schema::table('employees_attendance_policies', function (Blueprint $table): void {
            $table->dropColumn('punch_pairing_mode');
        });
    }
};
