<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `reference` (SKU) and `barcode` had no uniqueness at all -- a
     * duplicate SKU/barcode breaks inventory counts, POS scanning, and
     * price-list lookups, which all assume one product per identifier.
     * `reference` is scoped per company (different companies may run
     * their own independent SKU numbering), but `barcode` is a physical,
     * real-world identifier meant to resolve to exactly one product
     * regardless of company, so it's globally unique. Confirmed live:
     * all 4 real products currently have both fields NULL, so this is a
     * forward-looking guard, not a correction of existing data.
     */
    public function up(): void
    {
        $duplicateReferenceGroups = DB::table('products_products')
            ->select('company_id', 'reference')
            ->whereNotNull('reference')
            ->where('reference', '!=', '')
            ->groupBy('company_id', 'reference')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicateReferenceGroups as $group) {
            $ids = DB::table('products_products')
                ->where('company_id', $group->company_id)
                ->where('reference', $group->reference)
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->slice(1) as $duplicateId) {
                DB::table('products_products')
                    ->where('id', $duplicateId)
                    ->update(['reference' => "{$group->reference}-DUPLICATE-{$duplicateId}"]);
            }
        }

        $duplicateBarcodes = DB::table('products_products')
            ->select('barcode')
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->groupBy('barcode')
            ->havingRaw('count(*) > 1')
            ->pluck('barcode');

        foreach ($duplicateBarcodes as $barcode) {
            $ids = DB::table('products_products')
                ->where('barcode', $barcode)
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->slice(1) as $duplicateId) {
                DB::table('products_products')
                    ->where('id', $duplicateId)
                    ->update(['barcode' => "{$barcode}-DUPLICATE-{$duplicateId}"]);
            }
        }

        Schema::table('products_products', function (Blueprint $table) {
            $table->unique(['company_id', 'reference']);
            $table->unique('barcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products_products', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'reference']);
            $table->dropUnique(['barcode']);
        });
    }
};
