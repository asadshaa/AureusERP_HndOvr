<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free job boards (Recruitment → Configurations → Job boards): per company,
 * whether HR has registered the website feed with a board (or created the
 * free account for a manual board), when, and notes. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recruitments_job_board_registrations')) {
            return;
        }

        Schema::create('recruitments_job_board_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('channel', 50);
            $table->boolean('is_registered')->default(false);
            $table->date('registered_at')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'channel'], 'recruitment_job_board_registrations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitments_job_board_registrations');
    }
};
