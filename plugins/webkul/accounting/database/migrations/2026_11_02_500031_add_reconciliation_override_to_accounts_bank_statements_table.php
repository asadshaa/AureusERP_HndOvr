<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A statement whose import failed reconciliation (ReconciliationFailed)
     * can never generate journals. BankStatementImportService now offers two
     * ways forward: re-validating the stored figures, or an authorised
     * reviewer explicitly accepting the reported differences with a reason.
     * These columns are the audit record of that acceptance: who, when, why,
     * and exactly which validation errors were accepted. Additive only.
     */
    public function up(): void
    {
        Schema::table('accounts_bank_statements', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts_bank_statements', 'reconciliation_override_by_id')) {
                $table->foreignId('reconciliation_override_by_id')->nullable()
                    ->after('validation_errors')
                    ->constrained('users', 'id', 'abs_reconciliation_override_by_fk')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('accounts_bank_statements', 'reconciliation_override_at')) {
                $table->timestamp('reconciliation_override_at')->nullable()->after('reconciliation_override_by_id');
            }

            if (! Schema::hasColumn('accounts_bank_statements', 'reconciliation_override_reason')) {
                $table->text('reconciliation_override_reason')->nullable()->after('reconciliation_override_at');
            }

            if (! Schema::hasColumn('accounts_bank_statements', 'reconciliation_override_errors')) {
                $table->json('reconciliation_override_errors')->nullable()->after('reconciliation_override_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts_bank_statements', function (Blueprint $table) {
            if (Schema::hasColumn('accounts_bank_statements', 'reconciliation_override_by_id')) {
                $table->dropForeign('abs_reconciliation_override_by_fk');
                $table->dropColumn('reconciliation_override_by_id');
            }

            foreach (['reconciliation_override_at', 'reconciliation_override_reason', 'reconciliation_override_errors'] as $column) {
                if (Schema::hasColumn('accounts_bank_statements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
