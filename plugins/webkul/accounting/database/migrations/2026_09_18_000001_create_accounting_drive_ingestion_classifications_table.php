<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 of Drive -> Aureus ingestion: classification + review queue +
     * approval routing. One row per DriveIngestion that reached
     * DriveIngestionStatus::Registered -- a wide "classification" record
     * kept separate from accounting_drive_ingestions itself (rather than
     * bolting these columns onto that table) because it's a different
     * identity: an ingestion row is Drive-file-shaped (checksum, mime
     * type, folder), while a classification row is accounting-shaped
     * (document type, extracted fields, resolved GL/partner/FS Tag,
     * validation state) and is naturally 1:1 with an ingestion via
     * drive_ingestion_id, not a set of extra nullable columns on it.
     *
     * This phase does NOT create invoices, post journals, or touch GL
     * balances -- it only classifies, validates, and (when clean) routes
     * into the existing approval engine. approval_request_id is the hook
     * point a later phase will use to create the actual invoice/bill once
     * approved.
     */
    public function up(): void
    {
        Schema::create('accounting_drive_ingestion_classifications', function (Blueprint $table) {
            $table->id();

            // Constraint names given explicitly throughout -- MySQL's
            // 64-character identifier limit rejects the auto-generated
            // name (e.g. "..._drive_ingestion_id_foreign") for a table
            // name this long, found live while running this migration.
            $table->foreignId('drive_ingestion_id')
                ->constrained('accounting_drive_ingestions', 'id', 'adic_drive_ingestion_id_fk')
                ->cascadeOnDelete();
            $table->unique('drive_ingestion_id', 'adic_drive_ingestion_id_unique');

            $table->foreignId('company_id')
                ->constrained('companies', 'id', 'adic_company_id_fk')
                ->restrictOnDelete();

            // Minimal, filename/metadata-based classification -- NOT real
            // OCR or PDF text extraction. See DriveClassificationService's
            // class doc for exactly what this does and does not attempt.
            $table->string('document_type')->nullable();

            $table->string('extracted_invoice_number')->nullable();
            $table->string('extracted_partner_name')->nullable();
            $table->decimal('extracted_amount', 18, 4)->nullable();
            $table->string('extracted_currency_code', 10)->nullable();
            $table->date('extracted_date')->nullable();
            $table->string('extracted_fs_tag_code')->nullable();

            $table->foreignId('resolved_partner_id')->nullable()
                ->constrained('partners_partners', 'id', 'adic_resolved_partner_id_fk')->nullOnDelete();
            $table->foreignId('resolved_fs_tag_id')->nullable()
                ->constrained('accounting_fs_tags', 'id', 'adic_resolved_fs_tag_id_fk')->nullOnDelete();
            $table->foreignId('resolved_account_id')->nullable()
                ->constrained('accounts_accounts', 'id', 'adic_resolved_account_id_fk')->nullOnDelete();

            $table->string('validation_status')->default('pending_review');
            $table->json('validation_issues')->nullable();

            $table->foreignId('approval_request_id')->nullable()
                ->constrained('support_approval_requests', 'id', 'adic_approval_request_id_fk')->nullOnDelete();

            $table->timestamps();

            $table->index('validation_status', 'adic_validation_status_idx');
            $table->index('company_id', 'adic_company_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_drive_ingestion_classifications');
    }
};
