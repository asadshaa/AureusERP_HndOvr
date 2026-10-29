<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One onboarding or exit checklist per employee run, with its tasks.
     * open_key ("employee:type") is set only while a checklist is open, so
     * the unique index allows a single open onboarding and a single open
     * exit per employee (auto start and double clicks stay idempotent),
     * while finished and cancelled runs are kept for the record.
     * previous_values holds the employee fields "Start exit" changed, so an
     * undo before the leaving date restores them exactly.
     */
    public function up(): void
    {
        Schema::create('employees_employee_checklists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('employees_checklist_templates')->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('open');
            $table->string('open_key', 80)->nullable()->unique();
            $table->date('anchor_date');
            $table->date('last_working_day')->nullable();
            $table->date('leaving_date')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->foreignId('departure_reason_id')->nullable()->constrained('employees_departure_reasons')->nullOnDelete();
            $table->text('departure_description')->nullable();
            $table->json('previous_values')->nullable();
            $table->boolean('auto_started')->default(false);
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('user_disabled_at')->nullable();
            $table->foreignId('disabled_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'type', 'status'], 'employee_checklists_lookup_index');
            $table->index(['employee_id', 'type'], 'employee_checklists_employee_index');
        });

        Schema::create('employees_employee_checklist_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('checklist_id')->constrained('employees_employee_checklists')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
            $table->foreignId('template_task_id')->nullable()->constrained('employees_checklist_template_tasks')->nullOnDelete();
            $table->string('code', 60)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('responsible_role', 20);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->foreignId('document_type_id')->nullable()->constrained('employees_document_types')->nullOnDelete();
            $table->string('action_key', 40)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('completion_source', 20)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('employee_document_id')->nullable()->constrained('employees_employee_documents')->nullOnDelete();
            $table->date('scheduled_for')->nullable();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('last_reminded_on')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'status', 'due_date'], 'employee_checklist_tasks_due_index');
            $table->index(['assignee_id', 'status'], 'employee_checklist_tasks_assignee_index');
            $table->index(['employee_id', 'status'], 'employee_checklist_tasks_employee_index');
            $table->index(['action_key', 'status', 'scheduled_for'], 'employee_checklist_tasks_schedule_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_employee_checklist_tasks');
        Schema::dropIfExists('employees_employee_checklists');
    }
};
