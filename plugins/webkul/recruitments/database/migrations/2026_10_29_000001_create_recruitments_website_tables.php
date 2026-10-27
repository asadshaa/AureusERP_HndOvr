<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company careers website (Recruitment automation plan, phases A3 and A4):
 *  - recruitments_website_connections: one WordPress site per company, with the
 *    integration user's Application Password stored encrypted;
 *  - recruitments_intake_failures: website applications that could not be
 *    imported, kept with their payload until HR retries or dismisses them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitments_website_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique('recruitment_website_connections_company_unique')->constrained('companies')->cascadeOnDelete();
            $table->string('base_url', 255);
            $table->string('username', 255);
            $table->text('app_password');
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->string('last_pull_error', 1000)->nullable();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recruitments_intake_failures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('recruitments_website_connections')->nullOnDelete();
            $table->string('source', 30)->default('company_website');
            $table->string('external_id', 255);
            $table->json('payload')->nullable();
            $table->string('error', 2000);
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 20)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applicant_id')->nullable()->constrained('recruitments_applicants')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'source', 'external_id'], 'recruitment_intake_failures_external_unique');
            $table->index(['company_id', 'resolved_at'], 'recruitment_intake_failures_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitments_intake_failures');
        Schema::dropIfExists('recruitments_website_connections');
    }
};
