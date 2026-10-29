<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Webkul\Employee\Database\Seeders\OvertimeWorkflowSeeder;

/**
 * Gives every existing company the "Overtime" request type and its
 * line manager -> HR approval workflow (new companies get it from
 * EmployeeServiceProvider's Company::created hook). Idempotent and additive:
 * existing types and workflows are never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_request_types') || ! Schema::hasTable('support_approval_workflows') || ! Schema::hasTable('support_approval_steps')) {
            return;
        }

        app(OvertimeWorkflowSeeder::class)->run();
    }

    public function down(): void
    {
        // Additive configuration only: requests may already reference the type.
    }
};
