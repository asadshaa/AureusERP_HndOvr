<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A device a user has registered so documents can be sent to it.
     *
     * The private key never leaves the device; only the SPKI public key
     * and its SHA-256 fingerprint are stored here. The fingerprint is
     * unique so the same keypair cannot be registered twice, which is
     * what makes it usable as a stable device identity later when the
     * peer transport needs to bind a transfer to a specific endpoint.
     *
     * Revocation is a timestamp rather than a delete, so a revoked
     * device still resolves for audit rows that reference it.
     */
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            $table->string('label');
            $table->string('platform')->nullable();
            $table->text('public_key');
            $table->string('public_key_fingerprint', 64)->unique();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
