<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment Stage 2.3: one "posting" (job ad) per job x channel x placement,
 * an append-only history of what happened to each ad, and the ad an
 * application came from.
 *
 * Delete behaviour: postings restrict deletion of their company and job, so
 * ad history cannot vanish silently; a job force delete is only allowed by the
 * JobPostingLifecycle guard when none of its ads ever went out. Rolling this
 * migration back drops all posting history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitments_job_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('job_id')->constrained('employees_job_positions')->restrictOnDelete();
            $table->string('channel', 40);
            $table->string('placement', 120)->default('');
            // Identity of the placement: lower-cased, compared byte for byte, so groups that
            // differ only by an emoji or accent stay distinct (the default collation would merge them).
            $table->string('placement_key', 120)->charset('utf8mb4')->collation('utf8mb4_bin')->default('');
            $table->string('status', 30)->default('planned');
            $table->char('token', 32);
            $table->foreignId('utm_source_id')->nullable()->constrained('utm_sources')->nullOnDelete();
            $table->string('live_url', 2048)->nullable();
            $table->string('apply_url', 2048)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removal_requested_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dropped_at')->nullable();
            $table->unsignedTinyInteger('reminder_count')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['job_id', 'channel', 'placement_key'], 'recruitment_job_postings_job_channel_unique');
            $table->unique('token', 'recruitment_job_postings_token_unique');
            $table->index(['company_id', 'status'], 'recruitment_job_postings_company_status_index');
            $table->index(['status', 'removal_requested_at'], 'recruitment_job_postings_removal_index');
        });

        Schema::create('recruitments_job_posting_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('job_posting_id')->constrained('recruitments_job_postings')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('origin', 20);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['job_posting_id', 'id'], 'recruitment_job_posting_events_posting_index');
        });

        Schema::table('recruitments_applicants', function (Blueprint $table): void {
            $table->foreignId('job_posting_id')->nullable()->after('medium_id')
                ->constrained('recruitments_job_postings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recruitments_applicants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('job_posting_id');
        });

        Schema::dropIfExists('recruitments_job_posting_events');
        Schema::dropIfExists('recruitments_job_postings');
    }
};
