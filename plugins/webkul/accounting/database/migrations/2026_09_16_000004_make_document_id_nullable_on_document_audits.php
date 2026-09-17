<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peer-exchange audit events are genuinely document-less: a pairing, a
 * revocation, or a rejected peer signature has no document to point at.
 * Widening this column keeps one audit trail an investigator can read
 * end-to-end, rather than splitting peer events into a second table that
 * would have to be correlated by hand.
 *
 * Widening nullability is backward compatible -- every existing row keeps
 * its document_id and every existing query still works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_document_audits', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows written by the peer subsystem have no document to restore, so
        // they must go before the column can be narrowed again.
        DB::table('accounting_document_audits')->whereNull('document_id')->delete();

        Schema::table('accounting_document_audits', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable(false)->change();
        });
    }
};
