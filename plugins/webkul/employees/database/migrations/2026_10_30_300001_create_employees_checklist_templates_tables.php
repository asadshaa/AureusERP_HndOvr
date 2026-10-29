<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Employee\Support\DefaultChecklistTemplates;

return new class extends Migration
{
    /**
     * Onboarding / offboarding checklist templates per company (HR Parity
     * 2.4). Each task names who does it (HR, line manager, IT / admin user,
     * the employee), when (days from the joining date or the last working
     * day) and optionally the employee document type that completes it.
     * Every existing company gets the default templates; companies created
     * later get them from the Company::created hook.
     */
    public function up(): void
    {
        Schema::create('employees_checklist_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('code', 60)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'type', 'code'], 'employees_checklist_templates_code_unique');
            $table->index(['company_id', 'type', 'is_active'], 'employees_checklist_templates_lookup_index');
        });

        Schema::create('employees_checklist_template_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('employees_checklist_templates')->cascadeOnDelete();
            $table->string('code', 60)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('responsible_role', 20);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('due_offset_days')->default(0);
            $table->foreignId('document_type_id')->nullable()->constrained('employees_document_types')->nullOnDelete();
            $table->string('action_key', 40)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['template_id', 'code'], 'employees_checklist_template_tasks_code_unique');
        });

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DefaultChecklistTemplates::provisionForCompany((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_checklist_template_tasks');
        Schema::dropIfExists('employees_checklist_templates');
    }
};
