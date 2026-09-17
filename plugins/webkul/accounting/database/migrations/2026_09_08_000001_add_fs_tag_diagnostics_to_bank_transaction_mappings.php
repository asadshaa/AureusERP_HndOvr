<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_bank_transaction_mappings', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounting_bank_transaction_mappings', 'fs_tag_raw_code')) {
                // The exact text found in the file's "FS Tag" column for this row,
                // kept even when it doesn't resolve to a real tag, so the review
                // screen can show the user precisely what they typed rather than a
                // blank cell indistinguishable from "nothing was entered".
                $table->string('fs_tag_raw_code')->nullable()->after('fs_tag_id');
            }

            if (! Schema::hasColumn('accounting_bank_transaction_mappings', 'fs_tag_issue')) {
                // A short, human-readable reason the FS Tag couldn't be applied
                // (unknown code, belongs to another company, retired/inactive tag).
                // Deliberately separate from `suggestion_explanation`, which already
                // carries an unrelated explanation for bank-matching suggestions.
                $table->string('fs_tag_issue')->nullable()->after('fs_tag_raw_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounting_bank_transaction_mappings', function (Blueprint $table): void {
            foreach (['fs_tag_raw_code', 'fs_tag_issue'] as $column) {
                if (Schema::hasColumn('accounting_bank_transaction_mappings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
