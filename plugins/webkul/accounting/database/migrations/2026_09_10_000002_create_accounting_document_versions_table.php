<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only file history. A row here is never updated or deleted by
     * normal application code -- "replacing" a document's file means
     * inserting a new version and repointing accounting_documents.current_version_id
     * at it, so every prior version (and its checksum) stays exactly as it
     * was for audit purposes. storage_disk is recorded per-version (not
     * assumed from today's config) so a version written while
     * DOC_STORAGE_DISK=local still resolves correctly even if the app is
     * later switched to s3.
     */
    public function up(): void
    {
        Schema::create('accounting_document_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained('accounting_documents')->cascadeOnDelete();
            $table->unsignedInteger('version_number');

            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('checksum_sha256', 64);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason')->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
        });

        Schema::table('accounting_documents', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('accounting_document_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('accounting_document_versions');
    }
};
