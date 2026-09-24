<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Same defensive dedup pattern as the accounts_account_moves name
        // constraint: a duplicate journal code (e.g. two "BANK" journals
        // for the same company) corrupts reconciliation/statement-hash
        // matching, which key off the code. Rename any duplicate found
        // rather than delete it -- a Journal is configuration, not a
        // posted transaction, but it may already have real moves posted
        // against it, so deleting is still off the table here.
        $duplicateGroups = DB::table('accounts_journals')
            ->select('company_id', 'code')
            ->whereNotNull('code')
            ->groupBy('company_id', 'code')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $ids = DB::table('accounts_journals')
                ->where('company_id', $group->company_id)
                ->where('code', $group->code)
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->slice(1) as $duplicateId) {
                DB::table('accounts_journals')
                    ->where('id', $duplicateId)
                    ->update(['code' => "{$group->code}-DUP{$duplicateId}"]);
            }
        }

        Schema::table('accounts_journals', function (Blueprint $table) {
            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts_journals', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
        });
    }
};
