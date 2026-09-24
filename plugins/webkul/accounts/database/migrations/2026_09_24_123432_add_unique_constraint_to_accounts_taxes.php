<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The actual root cause of the earlier duplicate-tax bug this session
     * (accounts_product_taxes had the same tax attached to a product 7
     * times): nothing here ever stopped the same conceptual tax (same
     * company, name, rate and sale/purchase direction) from being created
     * as two, three, or more separate Tax rows in the first place. The
     * product-tax pivot fix closed the symptom (one tax attached
     * repeatedly); this closes the source (the tax itself duplicated).
     * Confirmed live: all 4 real tax rows for the company are currently
     * distinct on this combination, so this is a forward-looking guard,
     * not a correction of existing data.
     */
    public function up(): void
    {
        $duplicateGroups = DB::table('accounts_taxes')
            ->select('company_id', 'name', 'amount', 'type_tax_use', DB::raw('count(*) as c'))
            ->groupBy('company_id', 'name', 'amount', 'type_tax_use')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $ids = DB::table('accounts_taxes')
                ->where('company_id', $group->company_id)
                ->where('name', $group->name)
                ->where('amount', $group->amount)
                ->where('type_tax_use', $group->type_tax_use)
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->slice(1) as $duplicateId) {
                DB::table('accounts_taxes')
                    ->where('id', $duplicateId)
                    ->update(['name' => "{$group->name}-DUPLICATE-{$duplicateId}"]);
            }
        }

        Schema::table('accounts_taxes', function (Blueprint $table) {
            $table->unique(['company_id', 'name', 'amount', 'type_tax_use'], 'accounts_taxes_identity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts_taxes', function (Blueprint $table) {
            $table->dropUnique('accounts_taxes_identity_unique');
        });
    }
};
