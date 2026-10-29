<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Webkul\Recruitment\Database\Seeders\JobOfferDefaultsSeeder;

/**
 * Gives every existing company the "job_offer" approval workflow (hiring
 * manager -> HR -> CEO above the salary threshold) and the default offer
 * letter template (new companies get them from RecruitmentServiceProvider's
 * Company::created hook). Idempotent and additive: existing workflows,
 * steps and templates are never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_approval_workflows') || ! Schema::hasTable('recruitments_offer_letter_templates')) {
            return;
        }

        app(JobOfferDefaultsSeeder::class)->run();
    }

    public function down(): void
    {
        // Additive configuration only: offers may already reference the workflow and templates.
    }
};
