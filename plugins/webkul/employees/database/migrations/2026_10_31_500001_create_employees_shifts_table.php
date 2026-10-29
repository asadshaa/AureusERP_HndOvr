<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Employee\Support\DefaultShifts;

/**
 * Shift definitions per company (HR parity plan 3.4): name, code, start and
 * end time (an end before the start is an overnight shift), required
 * minutes, unpaid break, flexible window and the roster colour.
 *
 * Every existing company gets the default shifts (General, Morning, Evening,
 * Night); companies created later get them from EmployeeServiceProvider's
 * Company::created hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_shifts')) {
            Schema::create('employees_shifts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', 'id', 'emp_shifts_company_fk')->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('code', 10);
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedSmallInteger('required_minutes');
                // Unpaid break: deducted from worked time when no break was recorded.
                $table->unsignedSmallInteger('break_minutes')->default(0);
                $table->unsignedSmallInteger('flexible_minutes')->default(0);
                $table->string('color', 7)->default('#64748b');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'emp_shifts_created_by_fk')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'emp_shifts_updated_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'code'], 'emp_shifts_company_code_unique');
            });
        }

        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DB::table('employees_shifts')->insertOrIgnore(array_map(fn (array $shift): array => $shift + [
                'company_id' => $companyId,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], DefaultShifts::rows()));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_shifts');
    }
};
