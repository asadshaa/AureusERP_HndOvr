<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR reporting lines and approval flows.
 *
 * - employees_reporting_assignments: a manager for ONE purpose (leave,
 *   attendance, claims, ...) when it differs from the person's line manager
 *   (employees_employees.parent_id, which stays the general line). One row
 *   per person and purpose; no row means "the line manager".
 * - employees_reporting_changes: every change of a line manager or a purpose
 *   manager, kept for good (who, when, why, from whom to whom). A change HR
 *   makes to her own line is stored as "pending" until a second HR person or
 *   an admin approves it.
 * - employees_approval_delegations: a substitute approver for a date range
 *   ("while I am away, Sana approves for me"). The approval engine lets the
 *   substitute act wherever the person could, except on their own requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_reporting_assignments')) {
            Schema::create('employees_reporting_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
                $table->string('purpose', 30);
                $table->foreignId('manager_employee_id')->constrained('employees_employees')->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['employee_id', 'purpose'], 'employees_reporting_assignments_employee_purpose_unique');
                $table->index(['company_id', 'purpose'], 'employees_reporting_assignments_company_purpose_index');
            });
        }

        if (! Schema::hasTable('employees_reporting_changes')) {
            Schema::create('employees_reporting_changes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
                $table->string('purpose', 30)->nullable();
                $table->foreignId('old_manager_employee_id')->nullable()->constrained('employees_employees')->nullOnDelete();
                $table->foreignId('new_manager_employee_id')->nullable()->constrained('employees_employees')->nullOnDelete();
                $table->string('status', 20)->default('applied');
                $table->text('reason')->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status'], 'employees_reporting_changes_company_status_index');
                $table->index(['employee_id', 'created_at'], 'employees_reporting_changes_employee_created_index');
            });
        }

        if (! Schema::hasTable('employees_approval_delegations')) {
            Schema::create('employees_approval_delegations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['to_user_id', 'starts_on', 'ends_on'], 'employees_approval_delegations_to_range_index');
                $table->index(['from_user_id', 'starts_on', 'ends_on'], 'employees_approval_delegations_from_range_index');
                $table->index(['company_id', 'revoked_at'], 'employees_approval_delegations_company_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_approval_delegations');
        Schema::dropIfExists('employees_reporting_changes');
        Schema::dropIfExists('employees_reporting_assignments');
    }
};
