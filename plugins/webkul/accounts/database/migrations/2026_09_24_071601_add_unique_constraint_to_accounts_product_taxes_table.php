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
        // The pivot table had no uniqueness guard at all, which let the same
        // product_id/tax_id pair be attached repeatedly (found live: one
        // product had the same tax linked 7 times, showing as 7 duplicate
        // tax chips on every invoice/bill line for that product). Dedupe
        // first so the unique index can actually be created.
        // Pure pivot table, no surrogate id column -- dedupe via a temp
        // table rather than a self-join on a non-existent id.
        DB::statement('CREATE TEMPORARY TABLE tmp_accounts_product_taxes_dedup AS SELECT DISTINCT product_id, tax_id FROM accounts_product_taxes');
        DB::statement('TRUNCATE TABLE accounts_product_taxes');
        DB::statement('INSERT INTO accounts_product_taxes (product_id, tax_id) SELECT product_id, tax_id FROM tmp_accounts_product_taxes_dedup');
        DB::statement('DROP TEMPORARY TABLE tmp_accounts_product_taxes_dedup');

        Schema::table('accounts_product_taxes', function (Blueprint $table) {
            $table->unique(['product_id', 'tax_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts_product_taxes', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'tax_id']);
        });
    }
};
