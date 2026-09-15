<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per receipt. Nothing here touches the ledger: an inbound invoice
 * sits as evidence until a human with ReviewInboundTransmissions accepts
 * it, which is what creates the draft bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_inbound_transmissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('peer_id')->constrained('accounting_peers')->restrictOnDelete();

            // The sender's idempotency_key. Paired with peer_id this is what
            // makes a redelivery a no-op instead of a duplicate invoice.
            $table->string('remote_reference');

            $table->string('payload_type');
            $table->json('payload');

            $table->string('status')->default('received');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('created_move_id')->nullable()->constrained('accounts_account_moves')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('accounting_documents')->nullOnDelete();

            $table->timestamps();

            $table->unique(['peer_id', 'remote_reference'], 'ait_peer_remote_ref_unq');
            $table->index(['company_id', 'status'], 'ait_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_inbound_transmissions');
    }
};
