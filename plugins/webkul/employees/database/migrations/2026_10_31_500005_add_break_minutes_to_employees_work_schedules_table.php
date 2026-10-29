<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unpaid break per work schedule (HR parity plan 3.5). 0 keeps every
 * existing schedule exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_work_schedules') || Schema::hasColumn('employees_work_schedules', 'break_minutes')) {
            return;
        }

        Schema::table('employees_work_schedules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('flexible_minutes');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees_work_schedules', 'break_minutes')) {
            Schema::table('employees_work_schedules', function (Blueprint $table): void {
                $table->dropColumn('break_minutes');
            });
        }
    }
};
