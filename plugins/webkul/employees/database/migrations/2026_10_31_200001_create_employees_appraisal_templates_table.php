<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Employee\Support\DefaultAppraisalTemplate;

return new class extends Migration
{
    /**
     * Appraisal form templates per company (HR Parity 3.2): sections of
     * competencies / KPIs with weights, the 1-5 rating scale labels, the
     * configurable rating bands, optional goals for the next period and when
     * the employee sees the manager's review. A cycle copies its template
     * when it is launched, so later edits never change a running appraisal.
     * Every existing company gets the default template; companies created
     * later get it from the Company::created hook.
     */
    public function up(): void
    {
        Schema::create('employees_appraisal_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 60)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('sections');
            $table->json('rating_scale');
            $table->json('rating_bands');
            $table->boolean('include_goals')->default(true);
            $table->string('results_visibility', 30)->default('after_close');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'employees_appraisal_templates_code_unique');
            $table->index(['company_id', 'is_active'], 'employees_appraisal_templates_lookup_index');
        });

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DefaultAppraisalTemplate::provisionForCompany((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_appraisal_templates');
    }
};
