<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Webkul\Employee\Services\Payroll\RequestInstalmentService;

/**
 * Gives every loan and salary advance approved before instalments existed
 * its instalment rows (from the payload schedule / deduct-from month), all
 * "due", audited as "backfilled". Idempotent: a request that already has
 * rows is skipped, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_request_instalments') || ! Schema::hasTable('employees_requests')) {
            return;
        }

        app(RequestInstalmentService::class)->backfill();
    }

    public function down(): void
    {
        // Additive data only: HR may already have marked instalments.
    }
};
