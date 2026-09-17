<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One record per "send this document to that device" act.
     *
     * This is the authorization record, not a copy of the file: bytes
     * always stay under DocumentStorageProvider and are served through
     * DocumentService. The row exists so the server -- not either peer --
     * decides who may receive what, and so the act is auditable whether
     * delivery happens through the server or, later, directly between
     * two browsers.
     *
     * expected_sha256 is denormalised from the document version at send
     * time on purpose: it is what the recipient must verify against, and
     * it must not silently change if a new version is added mid-flight.
     *
     * The later peer-transport phase adds expected_fingerprint and a
     * server signature to this table; nothing here needs to change for
     * that.
     */
    public function up(): void
    {
        Schema::create('accounting_document_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('accounting_documents')->cascadeOnDelete();

            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_device_id')->nullable()->constrained('user_devices')->nullOnDelete();

            $table->string('status')->default('pending');
            $table->string('expected_sha256', 64)->nullable();
            $table->text('note')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['recipient_id', 'status']);
            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_document_transfers');
    }
};
