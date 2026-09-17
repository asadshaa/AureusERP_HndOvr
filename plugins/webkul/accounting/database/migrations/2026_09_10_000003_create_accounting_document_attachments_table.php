<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polymorphic link between a document and the accounting record(s) it
     * is evidence for (an invoice, a bill, a journal entry, a bank
     * statement line, a manual adjustment, ...). Kept as its own table
     * (rather than a column on accounting_documents) so one uploaded file
     * can be attached to more than one record without duplicating it, and
     * so attaching/detaching is its own auditable event.
     *
     * company_id is duplicated here (not just reachable via document_id)
     * specifically so a mismatch between the document's own company and
     * the attachable record's company can be checked and indexed directly,
     * without a join, everywhere this table is queried.
     */
    public function up(): void
    {
        Schema::create('accounting_document_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('accounting_documents')->cascadeOnDelete();
            $table->morphs('attachable', 'doc_attachments_attachable_idx');

            $table->string('note')->nullable();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['document_id', 'attachable_type', 'attachable_id'], 'document_attachments_unique_link');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_document_attachments');
    }
};
