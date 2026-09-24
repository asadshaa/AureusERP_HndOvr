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
        // Defensive dedup before adding the constraint: never delete a
        // posted accounting record to make room for a unique index (see
        // AGENTS.md accounting-integrity rules). Any existing duplicate
        // (company_id, name) pair -- e.g. INV/2026/52 appearing twice --
        // gets every row after the first RENAMED with a
        // "-DUPLICATE-{id}" suffix instead, preserving the record and its
        // full audit trail while freeing the name up for the constraint.
        // Confirmed live: zero duplicates exist in the current database,
        // so this is a no-op guard for any other environment this
        // migration runs against, not a correction of known bad data.
        $duplicateGroups = DB::table('accounts_account_moves')
            ->select('company_id', 'name')
            ->whereNotNull('name')
            ->groupBy('company_id', 'name')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $ids = DB::table('accounts_account_moves')
                ->where('company_id', $group->company_id)
                ->where('name', $group->name)
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->slice(1) as $duplicateId) {
                DB::table('accounts_account_moves')
                    ->where('id', $duplicateId)
                    ->update(['name' => "{$group->name}-DUPLICATE-{$duplicateId}"]);
            }
        }

        Schema::table('accounts_account_moves', function (Blueprint $table) {
            $table->unique(['company_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
        });
    }
};
