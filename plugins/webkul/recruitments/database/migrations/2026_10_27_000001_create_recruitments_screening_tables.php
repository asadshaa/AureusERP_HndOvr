<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screening questions (Recruitment automation plan, phase A1):
 *  - recruitments_screening_questions: the company's editable question library;
 *  - recruitments_job_questions: the questions asked for one job (copied from the
 *    library or written for the job, editable per job);
 *  - recruitments_applicant_answers: each answer with a snapshot of the question as
 *    it was asked, so later edits never rewrite history;
 *  - recruitments_applicants.screening_status: passed / flagged / overridden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitments_screening_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label', 500);
            $table->string('help_text', 1000)->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(true);
            $table->json('knockout')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'sort'], 'recruitment_screening_questions_company_index');
        });

        Schema::create('recruitments_job_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('employees_job_positions')->cascadeOnDelete();
            $table->foreignId('screening_question_id')->nullable()->constrained('recruitments_screening_questions')->nullOnDelete();
            $table->string('type', 20);
            $table->string('label', 500);
            $table->string('help_text', 1000)->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(true);
            $table->json('knockout')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_id', 'sort'], 'recruitment_job_questions_job_index');
        });

        Schema::create('recruitments_applicant_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('recruitments_applicants')->cascadeOnDelete();
            $table->foreignId('job_question_id')->nullable()->constrained('recruitments_job_questions')->nullOnDelete();
            $table->json('question_snapshot');
            $table->json('answer')->nullable();
            $table->boolean('passed')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('source', 30)->default('manual');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['applicant_id', 'job_question_id'], 'recruitment_applicant_answers_unique');
        });

        Schema::table('recruitments_applicants', function (Blueprint $table): void {
            $table->string('screening_status', 20)->nullable()->after('screening_score')->index();
        });
    }

    public function down(): void
    {
        Schema::table('recruitments_applicants', function (Blueprint $table): void {
            $table->dropIndex(['screening_status']);
            $table->dropColumn('screening_status');
        });

        Schema::dropIfExists('recruitments_applicant_answers');
        Schema::dropIfExists('recruitments_job_questions');
        Schema::dropIfExists('recruitments_screening_questions');
    }
};
