<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per appraisal reminder sent (HR Parity 3.2), e.g.
     * "self_due_soon:2026-11-10" or "manager_overdue:2026-11-20:1". The
     * unique key is the claim: hr:appraisal-reminders inserts the row before
     * anyone is told, so an overlap or a re-run never reminds twice, and a
     * moved deadline arms new reminders.
     */
    public function up(): void
    {
        Schema::create('employees_performance_review_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('review_id')->constrained('employees_performance_reviews')->cascadeOnDelete();
            $table->string('kind', 60);
            $table->date('sent_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['review_id', 'kind'], 'performance_review_reminders_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_performance_review_reminders');
    }
};
