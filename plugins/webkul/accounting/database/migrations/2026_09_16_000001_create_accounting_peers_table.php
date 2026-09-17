<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paired remote AureusERP instance.
 *
 * Peer credentials have to live in the database rather than .env the way
 * the Drive integration's do, because peers are added at runtime by an
 * operator. The two values we present outbound are held under Laravel
 * `encrypted` casts; the token we *verify* is stored only as a hash, so a
 * database dump alone cannot impersonate either side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_peers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            $table->string('name');
            $table->string('endpoint_url');
            $table->string('status')->default('pending');

            // Maps an inbound invoice onto a local vendor. Nullable because a
            // peer is paired before anyone decides which partner it is; accept
            // is blocked until it is set (see DocumentExchangeService::accept).
            $table->foreignId('partner_id')->nullable()->constrained('partners_partners')->nullOnDelete();

            $table->text('outbound_token')->nullable();
            $table->string('inbound_token_hash', 64)->nullable();
            $table->text('signing_secret')->nullable();

            // One-time pairing handshake material.
            $table->string('pairing_code_hash', 64)->nullable();
            $table->timestamp('pairing_expires_at')->nullable();

            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->unique('inbound_token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_peers');
    }
};
