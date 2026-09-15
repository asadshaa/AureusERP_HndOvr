<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per send attempt-set. Written and committed BEFORE any network
 * call (the same ordering the Drive integration uses), so a failed or lost
 * request can never lose the user's intent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_outbound_transmissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('peer_id')->nullable()->constrained('accounting_peers')->restrictOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('channel');
            $table->string('recipient_email')->nullable();

            // Morph: a Move (invoice) or a Document.
            $table->string('transmittable_type');
            $table->unsignedBigInteger('transmittable_id');

            $table->string('status')->default('queued');
            $table->string('payload_type');
            $table->string('payload_sha256', 64)->nullable();

            // Sent to the peer so a retry after a lost response is deduped
            // there rather than creating a second invoice.
            $table->uuid('idempotency_key')->unique();

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // External (non-ERP) channel only.
            $table->string('claim_token_hash', 64)->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'aot_company_status_idx');
            $table->index(['transmittable_type', 'transmittable_id'], 'aot_transmittable_idx');
            $table->index('claim_token_hash', 'aot_claim_token_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_outbound_transmissions');
    }
};
