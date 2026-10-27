<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interview scheduling and feedback (Recruitment roadmap Stage 2.3):
 *  - recruitments_interviews: one scheduled interview of an application, in
 *    UTC, with the calendar sequence used for .ics updates and the moments
 *    the candidate was told and the 24h reminder went out. ends_at is
 *    derived (starts_at + duration_minutes) so overlap and "has ended"
 *    queries stay plain and indexable;
 *  - recruitments_interview_interviewers: who interviews, and each
 *    interviewer's own scorecard (rating, recommendation, notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitments_interviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('recruitments_applicants')->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('employees_job_positions')->nullOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('recruitments_stages')->nullOnDelete();
            $table->string('title', 255);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('mode', 20)->default('in_person');
            $table->string('location', 500)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('cancel_reason', 1000)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamp('candidate_notified_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'starts_at'], 'recruitment_interviews_company_start_index');
            $table->index(['status', 'starts_at'], 'recruitment_interviews_status_start_index');
            $table->index(['status', 'ends_at'], 'recruitment_interviews_status_end_index');
        });

        Schema::create('recruitments_interview_interviewers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interview_id')->constrained('recruitments_interviews')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('recommendation', 20)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('feedback_requested_at')->nullable();
            $table->timestamps();

            $table->unique(['interview_id', 'user_id'], 'recruitment_interview_interviewers_unique');
            $table->index(['user_id', 'submitted_at'], 'recruitment_interview_interviewers_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitments_interview_interviewers');
        Schema::dropIfExists('recruitments_interviews');
    }
};
