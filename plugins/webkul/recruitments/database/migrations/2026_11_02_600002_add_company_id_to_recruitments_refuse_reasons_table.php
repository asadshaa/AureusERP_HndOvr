<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refuse reasons per company. Existing reasons keep no company: they stay
 * shared by every company (as before); reasons added from Recruitment >
 * Configurations > Refuse reasons belong to the active company only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('recruitments_refuse_reasons', 'company_id')) {
            return;
        }

        Schema::table('recruitments_refuse_reasons', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('creator_id')->constrained('companies')->cascadeOnDelete();
            $table->index(['company_id', 'is_active'], 'recruitment_refuse_reasons_company_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('recruitments_refuse_reasons', 'company_id')) {
            return;
        }

        Schema::table('recruitments_refuse_reasons', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropIndex('recruitment_refuse_reasons_company_index');
            $table->dropColumn('company_id');
        });
    }
};
