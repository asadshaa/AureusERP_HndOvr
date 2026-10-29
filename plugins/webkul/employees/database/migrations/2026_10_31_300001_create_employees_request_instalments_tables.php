<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan instalments and salary-advance deductions as first-class rows (HR
 * parity plan 3.3), feeding the monthly payroll-input export (1.5):
 *
 *  - employees_request_instalments: one row per instalment of an approved
 *    loan (from the payload schedule) and one row for the deduct-from month
 *    of an approved salary advance; status due / deducted / skipped /
 *    settled, with who changed it and when;
 *  - employees_request_instalment_events: the audit trail (created,
 *    backfilled, deducted, skipped, reverted, rescheduled, settled), with the
 *    rows before and after.
 *
 * Nothing here posts to accounting or calculates pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_request_instalments')) {
            Schema::create('employees_request_instalments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', 'id', 'emp_req_inst_company_fk')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees_employees', 'id', 'emp_req_inst_employee_fk')->restrictOnDelete();
                $table->foreignId('request_id')->constrained('employees_requests', 'id', 'emp_req_inst_request_fk')->cascadeOnDelete();
                // loan | salary_advance
                $table->string('kind', 20);
                $table->unsignedSmallInteger('number');
                // "Y-m": the payroll month the amount is deducted in.
                $table->char('due_month', 7);
                $table->decimal('amount', 20, 4);
                $table->string('status', 20)->default('due');
                $table->string('note', 1000)->nullable();
                $table->timestamp('status_changed_at')->nullable();
                $table->foreignId('status_changed_by')->nullable()->constrained('users', 'id', 'emp_req_inst_changed_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->unique(['request_id', 'number'], 'emp_req_inst_request_number_unique');
                $table->index(['company_id', 'due_month', 'status'], 'emp_req_inst_month_lookup');
                $table->index(['employee_id', 'status'], 'emp_req_inst_employee_lookup');
            });
        }

        if (! Schema::hasTable('employees_request_instalment_events')) {
            Schema::create('employees_request_instalment_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', 'id', 'emp_req_inst_event_company_fk')->cascadeOnDelete();
                $table->foreignId('request_id')->constrained('employees_requests', 'id', 'emp_req_inst_event_request_fk')->cascadeOnDelete();
                $table->foreignId('instalment_id')->nullable()->constrained('employees_request_instalments', 'id', 'emp_req_inst_event_instalment_fk')->nullOnDelete();
                $table->string('action', 30);
                $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'emp_req_inst_event_user_fk')->nullOnDelete();
                $table->string('reason', 1000)->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['request_id', 'id'], 'emp_req_inst_event_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_request_instalment_events');
        Schema::dropIfExists('employees_request_instalments');
    }
};
