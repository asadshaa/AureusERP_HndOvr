<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Document that has ever been synchronized to Google
     * Drive -- created lazily on first export, never for documents that
     * are never synced. This is deliberately a separate table, not new
     * columns on accounting_documents: Drive sync is optional,
     * feature-specific metadata, and accounting_documents stays
     * storage-agnostic the same way it already is about local-vs-S3 via
     * DocumentStorageProvider. Mirrors the existing
     * DocumentVersion/DocumentAttachment/DocumentAudit pattern of
     * companion tables around Document, rather than introducing a
     * parallel document concept.
     */
    public function up(): void
    {
        Schema::create('accounting_document_drive_syncs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->unique()->constrained('accounting_documents')->cascadeOnDelete();

            // The durable link. If this is set, every export updates that
            // file in place -- it is NEVER re-created. This is what makes
            // export idempotent.
            $table->string('drive_file_id')->nullable();
            $table->string('drive_parent_folder_id')->nullable();

            // Google's own revision id for whatever we last saw/wrote.
            // Compared on every sync to detect "has Drive changed since
            // we last looked" without re-downloading content first.
            $table->string('drive_revision_id')->nullable();

            $table->foreignId('last_synced_version_id')->nullable()
                ->constrained('accounting_document_versions')->nullOnDelete();
            $table->string('last_synced_checksum', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->string('status')->default('not_synced');
            $table->text('last_sync_error')->nullable();

            $table->boolean('exists_in_drive')->default(false);

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_document_drive_syncs');
    }
};
