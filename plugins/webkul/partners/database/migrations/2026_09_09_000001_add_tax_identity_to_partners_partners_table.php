<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same tax-identity concept as the companies table (see
     * 2026_09_09_000001_add_tax_identity_to_companies_table.php), applied to
     * customers/vendors. `tax_id` already exists and keeps serving as a
     * partner's NTN; STRN is added separately since a partner can have one
     * without the other. Unlike the companies table, `strn` is NOT unique
     * here — customer/vendor data is entered by many people and imported
     * from external sources, so a hard uniqueness constraint would risk
     * rejecting legitimate records (e.g. a blank/placeholder value re-used
     * across untouched imported rows). It's indexed for lookup instead.
     */
    public function up(): void
    {
        Schema::table('partners_partners', function (Blueprint $table) {
            $table->boolean('is_sales_tax_registered')->default(false)->after('tax_id');
            $table->string('strn')->nullable()->index()->after('is_sales_tax_registered');
        });
    }

    public function down(): void
    {
        Schema::table('partners_partners', function (Blueprint $table) {
            $table->dropColumn(['is_sales_tax_registered', 'strn']);
        });
    }
};
