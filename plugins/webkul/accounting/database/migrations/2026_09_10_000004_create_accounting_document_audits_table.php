<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail for document access and lifecycle events
     * (uploaded, version added, attached/detached, viewed, downloaded,
     * archived/restored, and denied-access attempts). No FK cascade back
     * from company_id/actor_id is ever expected to fire in normal
     * operation; actor_id nulls out rather than deleting the audit row if
     * a user account is later removed, so the evidence trail outlives the
     * account that made it.
     */
    public function up(): void
    {
        Schema::create('accounting_document_audits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('accounting_documents')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action');
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_document_audits');
    }
};
