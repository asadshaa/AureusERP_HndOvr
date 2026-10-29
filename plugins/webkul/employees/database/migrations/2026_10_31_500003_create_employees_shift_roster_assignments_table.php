<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A roster pattern assigned to one employee or to a whole department from a
 * start date (the pattern's day 1), optionally until an end date. An
 * employee's own assignment wins over their department's. Assignments of
 * one employee (or one department) never overlap (enforced by the model).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees_shift_roster_assignments')) {
            return;
        }

        Schema::create('employees_shift_roster_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'emp_roster_assign_company_fk')->cascadeOnDelete();
            // A pattern in use cannot be deleted (switch it off instead).
            $table->foreignId('roster_id')->constrained('employees_shift_rosters', 'id', 'emp_roster_assign_roster_fk')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees_employees', 'id', 'emp_roster_assign_employee_fk')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('employees_departments', 'id', 'emp_roster_assign_department_fk')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'emp_roster_assign_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'emp_roster_assign_updated_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'start_date'], 'emp_roster_assign_employee_idx');
            $table->index(['department_id', 'start_date'], 'emp_roster_assign_department_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_shift_roster_assignments');
    }
};
