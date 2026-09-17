<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 8 ("IMPLEMENTATION SECTION 8 -- CLAIMS & REIMBURSEMENTS") extends
 * the existing generic EmployeeRequest, rather than a parallel Claim model.
 * `amount` (pre-existing) keeps its existing meaning -- the payable figure
 * EmployeeRequestService::createAccountingDraft() posts to Accounting -- and
 * now doubles as "Net Payment" for a claim, so the accounting handoff needs
 * no changes at all. `billed_amount` is new: the gross figure before
 * deductions. Bank fields are separate, named columns (not folded into the
 * existing free-form `payload` JSON) specifically so they can be gated by a
 * real Filament field-level permission check, the same way the rest of the
 * app already protects sensitive employee data -- a JSON blob can't be
 * partially hidden field-by-field the way named columns can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees_requests', function (Blueprint $table): void {
            $table->decimal('billed_amount', 20, 4)->nullable()->after('amount');
            $table->decimal('tax_deduction_rate', 7, 4)->nullable()->after('billed_amount');
            $table->decimal('income_tax_deduction', 20, 4)->nullable()->after('tax_deduction_rate');
            $table->decimal('sales_tax_deduction', 20, 4)->nullable()->after('income_tax_deduction');
            $table->string('nature_of_expense')->nullable()->after('sales_tax_deduction');
            $table->string('account_title')->nullable()->after('nature_of_expense');
            $table->string('iban')->nullable()->after('account_title');
            $table->string('bank_name')->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('employees_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'billed_amount', 'tax_deduction_rate', 'income_tax_deduction', 'sales_tax_deduction',
                'nature_of_expense', 'account_title', 'iban', 'bank_name',
            ]);
        });
    }
};
