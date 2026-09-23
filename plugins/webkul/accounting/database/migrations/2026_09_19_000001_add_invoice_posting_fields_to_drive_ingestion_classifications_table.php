<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 of Drive -> Aureus ingestion: on an approval decision for a
     * DriveIngestionClassification, an Approved decision creates and posts
     * the actual invoice/bill (see DriveInvoicePostingService), and this is
     * where that outcome is recorded back on the classification row.
     *
     * created_invoice_id is nullable and only ever set once -- it also
     * doubles as the idempotency guard: DriveInvoicePostingService refuses
     * to create a second Move for a classification that already has one.
     */
    public function up(): void
    {
        Schema::table('accounting_drive_ingestion_classifications', function (Blueprint $table) {
            $table->foreignId('created_invoice_id')->nullable()
                ->after('approval_request_id')
                ->constrained('accounts_account_moves', 'id', 'adic_created_invoice_id_fk')
                ->nullOnDelete();

            $table->timestamp('posted_at')->nullable()->after('created_invoice_id');

            $table->text('posting_failure_reason')->nullable()->after('posted_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_drive_ingestion_classifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_invoice_id');
            $table->dropColumn(['posted_at', 'posting_failure_reason']);
        });
    }
};
