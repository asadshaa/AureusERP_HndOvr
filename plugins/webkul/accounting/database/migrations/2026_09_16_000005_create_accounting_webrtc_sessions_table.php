<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ephemeral signaling session for browser-to-browser WebRTC DataChannel transfer.
     *
     * Holds SDP offers, SDP answers, and ICE candidate candidates so two browsers
     * can establish direct P2P connectivity without an external WebSocket daemon.
     */
    public function up(): void
    {
        Schema::create('accounting_webrtc_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 16)->unique();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();

            $table->string('transmittable_type');
            $table->unsignedBigInteger('transmittable_id');

            $table->string('status')->default('waiting');

            $table->mediumText('offer_sdp')->nullable();
            $table->mediumText('answer_sdp')->nullable();

            $table->json('sender_candidates')->nullable();
            $table->json('receiver_candidates')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['code', 'status']);
            $table->index(['company_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_webrtc_sessions');
    }
};
