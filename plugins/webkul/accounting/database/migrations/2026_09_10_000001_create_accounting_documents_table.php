<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The core document record: title/metadata plus a pointer to whichever
     * version is currently "the" file. The actual file content lives in
     * accounting_document_versions -- this table never stores a path itself,
     * so replacing a file always means adding a version, never overwriting
     * one. current_version_id has no FK constraint here (the versions table
     * doesn't exist yet); it's added by the versions migration once both
     * tables exist.
     */
    public function up(): void
    {
        Schema::create('accounting_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->string('document_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'document_type']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_documents');
    }
};
