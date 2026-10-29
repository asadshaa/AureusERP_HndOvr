<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One employee's shift on one date, overriding the roster and the work
 * schedule (a changed shift, a day off, or one side of a swap), always with
 * a reason. shift_id null = day off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees_shift_overrides')) {
            return;
        }

        Schema::create('employees_shift_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'emp_shift_overrides_company_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees', 'id', 'emp_shift_overrides_employee_fk')->cascadeOnDelete();
            $table->date('date');
            // A shift in use by an override cannot be deleted (switch it off instead).
            $table->foreignId('shift_id')->nullable()->constrained('employees_shifts', 'id', 'emp_shift_overrides_shift_fk')->restrictOnDelete();
            // change | off | swap
            $table->string('kind', 10)->default('change');
            $table->foreignId('swap_employee_id')->nullable()->constrained('employees_employees', 'id', 'emp_shift_overrides_swap_fk')->nullOnDelete();
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'emp_shift_overrides_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'emp_shift_overrides_updated_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date'], 'emp_shift_overrides_employee_date_unique');
            $table->index(['company_id', 'date'], 'emp_shift_overrides_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_shift_overrides');
    }
};
