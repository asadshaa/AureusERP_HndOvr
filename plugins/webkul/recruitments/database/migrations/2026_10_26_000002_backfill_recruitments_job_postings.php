<?php

use Illuminate\Database\Migrations\Migration;
use Webkul\Recruitment\Services\LegacyPostingChannelBackfill;

/**
 * Turns the legacy employees_job_positions.posting_channels labels into
 * posting rows (idempotent; the JSON column itself is left untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(LegacyPostingChannelBackfill::class)->run();
    }

    public function down(): void
    {
        //
    }
};
