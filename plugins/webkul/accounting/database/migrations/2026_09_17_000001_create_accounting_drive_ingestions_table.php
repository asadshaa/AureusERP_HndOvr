<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 of Drive -> Aureus ingestion: discovery + dedup + review
     * record only. One row per Drive file ever discovered in a company's
     * inbound folder -- never for files that were never seen. Mirrors the
     * accounting_document_drive_syncs table's shape for the opposite
     * direction, but is deliberately its own table rather than reusing
     * that one: a sync row is keyed on document_id (an Aureus document
     * that may or may not have been exported), while an ingestion row is
     * keyed on drive_file_id (a Drive file that may or may not yet have
     * become an Aureus document) -- the two identities point opposite
     * ways and neither is a subset of the other.
     */
    public function up(): void
    {
        Schema::create('accounting_drive_ingestions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            // The real stable identifier -- Drive's own file id. Never the
            // filename, which is not unique and can be renamed in Drive
            // without changing identity.
            $table->string('drive_file_id');
            $table->string('drive_folder_id')->nullable();

            $table->string('checksum_sha256', 64)->index();
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->timestamp('drive_modified_at')->nullable();
            $table->string('filename');

            $table->string('status')->default('discovered');

            $table->foreignId('document_id')->nullable()
                ->constrained('accounting_documents')->nullOnDelete();

            $table->text('failure_reason')->nullable();

            $table->timestamp('discovered_at');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('drive_file_id');
            $table->index('status');

            // The idempotency guard: a retry of the same Drive file for
            // the same company must never create a second ingestion row.
            $table->unique(['company_id', 'drive_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_drive_ingestions');
    }
};
