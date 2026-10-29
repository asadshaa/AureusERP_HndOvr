<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job offers (HR parity 3.6). Additive only:
 *  - recruitments_offer_letter_templates: per-company letter templates (rich
 *    text with {{placeholders}}), one default per company;
 *  - recruitments_offer_settings: per-company offer settings (the salary
 *    above which the CEO approves; empty = config default);
 *  - recruitments_job_offers: one offer to one application, its terms
 *    (salary in the company currency, monthly gross with an optional
 *    breakdown), approval request, generated letter (private disk), the
 *    tokenised accept/decline link and the candidate's response;
 *  - recruitments_job_offer_events: the offer's history (audit trail).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recruitments_offer_letter_templates')) {
            Schema::create('recruitments_offer_letter_templates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('email_subject', 255)->nullable();
                $table->longText('body');
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['company_id', 'is_active'], 'recruitment_offer_templates_company_index');
            });
        }

        if (! Schema::hasTable('recruitments_offer_settings')) {
            Schema::create('recruitments_offer_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->unique('recruitment_offer_settings_company_unique')->constrained('companies')->cascadeOnDelete();
                $table->decimal('ceo_approval_threshold', 15, 2)->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('recruitments_job_offers')) {
            Schema::create('recruitments_job_offers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('applicant_id')->constrained('recruitments_applicants')->cascadeOnDelete();
                $table->foreignId('candidate_id')->nullable()->constrained('recruitments_candidates')->nullOnDelete();
                $table->foreignId('job_id')->nullable()->constrained('employees_job_positions')->nullOnDelete();
                $table->foreignId('department_id')->nullable()->constrained('employees_departments')->nullOnDelete();
                $table->foreignId('reporting_manager_id')->nullable()->constrained('employees_employees')->nullOnDelete();
                $table->foreignId('hiring_manager_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('template_id')->nullable()->constrained('recruitments_offer_letter_templates')->nullOnDelete();
                $table->foreignId('approval_request_id')->nullable()->constrained('support_approval_requests')->nullOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained('employees_employees')->nullOnDelete();
                $table->string('reference', 40)->nullable()->unique('recruitment_job_offers_reference_unique');
                $table->string('status', 30)->default('draft');
                $table->string('designation', 255)->nullable();
                $table->date('joining_date');
                $table->date('expiry_date');
                $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
                $table->decimal('salary_gross', 15, 2)->nullable();
                $table->json('salary_breakdown')->nullable();
                $table->unsignedTinyInteger('probation_months')->nullable();
                $table->string('working_hours', 255)->nullable();
                $table->text('benefits')->nullable();
                $table->text('internal_notes')->nullable();
                $table->boolean('requires_ceo_approval')->default(false);
                $table->string('letter_path', 500)->nullable();
                $table->timestamp('letter_generated_at')->nullable();
                $table->string('response_token', 64)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('sent_to', 255)->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->text('response_reason')->nullable();
                $table->string('response_ip', 45)->nullable();
                $table->string('response_user_agent', 500)->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->timestamp('withdrawn_at')->nullable();
                $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('withdraw_reason')->nullable();
                $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['company_id', 'status'], 'recruitment_job_offers_company_status_index');
                $table->index(['status', 'expiry_date'], 'recruitment_job_offers_status_expiry_index');
            });
        }

        if (! Schema::hasTable('recruitments_job_offer_events')) {
            Schema::create('recruitments_job_offer_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('job_offer_id')->constrained('recruitments_job_offers')->cascadeOnDelete();
                $table->string('event', 40);
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('note')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['job_offer_id', 'id'], 'recruitment_job_offer_events_offer_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitments_job_offer_events');
        Schema::dropIfExists('recruitments_job_offers');
        Schema::dropIfExists('recruitments_offer_settings');
        Schema::dropIfExists('recruitments_offer_letter_templates');
    }
};
