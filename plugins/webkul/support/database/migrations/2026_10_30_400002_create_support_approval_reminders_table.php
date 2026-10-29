<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per approval reminder or escalation sent (approvals:send-reminders),
 * per request, step and submission round. The unique key is the idempotency
 * guard: a reminder number (or the single escalation) of a step can be claimed
 * once, so an overlapping or repeated run never sends it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_approval_reminders')) {
            return;
        }

        Schema::create('support_approval_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('support_approval_requests')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('support_approval_steps')->cascadeOnDelete();
            $table->unsignedInteger('submission_round')->default(1);
            $table->string('kind', 20);
            $table->unsignedSmallInteger('reminder_number')->default(0);
            $table->json('recipient_ids')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['request_id', 'step_id', 'submission_round', 'kind', 'reminder_number'], 'approval_reminders_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_approval_reminders');
    }
};
