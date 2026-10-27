<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the careers website, Google for Jobs and job boards need about a job
 * (Recruitment automation plan, phase A2), plus the CV analyser's criteria
 * (phase A5). All columns are nullable or defaulted, so existing jobs keep
 * working; the slug is unique per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees_job_positions', function (Blueprint $table): void {
            $table->string('public_title')->nullable()->after('name');
            $table->string('slug', 190)->nullable()->after('public_title');
            $table->string('location_type', 20)->nullable()->after('address_id');
            $table->date('valid_through')->nullable()->after('date_to');
            $table->decimal('salary_min', 15, 2)->nullable();
            $table->decimal('salary_max', 15, 2)->nullable();
            $table->foreignId('salary_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('salary_period', 10)->default('MONTH');
            $table->boolean('salary_visible')->default(false);
            $table->unsignedTinyInteger('min_experience_years')->nullable();
            $table->json('screening_keywords')->nullable();

            $table->unique(['company_id', 'slug'], 'employees_job_positions_company_slug_unique');
        });

        Schema::table('recruitments_candidates', function (Blueprint $table): void {
            $table->longText('cv_text')->nullable();
            $table->string('cv_text_source', 40)->nullable();
            $table->string('cv_text_fingerprint', 64)->nullable();
            $table->timestamp('cv_text_extracted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recruitments_candidates', function (Blueprint $table): void {
            $table->dropColumn(['cv_text', 'cv_text_source', 'cv_text_fingerprint', 'cv_text_extracted_at']);
        });

        Schema::table('employees_job_positions', function (Blueprint $table): void {
            $table->dropUnique('employees_job_positions_company_slug_unique');
            $table->dropConstrainedForeignId('salary_currency_id');
            $table->dropColumn([
                'public_title', 'slug', 'location_type', 'valid_through', 'salary_min', 'salary_max',
                'salary_period', 'salary_visible', 'min_experience_years', 'screening_keywords',
            ]);
        });
    }
};
