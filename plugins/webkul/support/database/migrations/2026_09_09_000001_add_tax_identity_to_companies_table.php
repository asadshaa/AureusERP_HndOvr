<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `tax_id` already exists and continues to serve as the company's NTN
     * (national tax number) — it is not repurposed here. STRN (Sales Tax
     * Registration Number) is a separate legal identity: a company can have
     * an NTN without being registered for sales tax, and one must never
     * substitute for the other.
     *
     * `is_sales_tax_registered` defaults to false so nothing is assumed about
     * a company's registration status until someone explicitly configures
     * it — matching the business rule that sales tax must never be charged
     * merely because a company happens to have a tax identity on file.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_sales_tax_registered')->default(false)->after('tax_id');
            $table->string('strn')->nullable()->unique()->after('is_sales_tax_registered');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['is_sales_tax_registered', 'strn']);
        });
    }
};
