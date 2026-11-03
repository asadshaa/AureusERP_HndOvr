<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the candidate's answer to an offer reached Aureus: "link" (the signed
 * accept / decline page) or, when HR records it for the candidate, "email",
 * "phone" or "in_person", with the user who recorded it. Nullable: offers
 * answered before this column existed keep it empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitments_job_offers', function (Blueprint $table): void {
            if (! Schema::hasColumn('recruitments_job_offers', 'response_channel')) {
                $table->string('response_channel', 20)->nullable()->after('response_reason');
            }

            if (! Schema::hasColumn('recruitments_job_offers', 'response_recorded_by')) {
                $table->foreignId('response_recorded_by')->nullable()->after('response_channel')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('recruitments_job_offers', function (Blueprint $table): void {
            if (Schema::hasColumn('recruitments_job_offers', 'response_recorded_by')) {
                $table->dropConstrainedForeignId('response_recorded_by');
            }

            if (Schema::hasColumn('recruitments_job_offers', 'response_channel')) {
                $table->dropColumn('response_channel');
            }
        });
    }
};
