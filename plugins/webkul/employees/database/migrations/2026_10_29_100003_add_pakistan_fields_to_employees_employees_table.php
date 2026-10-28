<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pakistan HR fields that had no column yet (emergency contact, joining
     * date and the generic identification_id already exist). Additive and
     * nullable only. CNIC is stored formatted (12345-1234567-1) and unique
     * per company; MySQL allows many NULLs in a unique index.
     */
    public function up(): void
    {
        Schema::table('employees_employees', function (Blueprint $table): void {
            $table->string('cnic', 15)->nullable()->after('identification_id');
            $table->string('ntn', 30)->nullable()->after('cnic');
            $table->string('eobi_number', 40)->nullable()->after('ntn');
            $table->string('blood_group', 5)->nullable()->after('eobi_number');
            $table->date('probation_end_date')->nullable()->after('joining_date');
            $table->date('confirmation_date')->nullable()->after('probation_end_date');

            $table->unique(['company_id', 'cnic'], 'employees_company_cnic_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees_employees', function (Blueprint $table): void {
            $table->dropUnique('employees_company_cnic_unique');
            $table->dropColumn(['cnic', 'ntn', 'eobi_number', 'blood_group', 'probation_end_date', 'confirmation_date']);
        });
    }
};
