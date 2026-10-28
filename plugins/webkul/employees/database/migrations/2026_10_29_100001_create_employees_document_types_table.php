<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Employee\Support\DefaultDocumentTypes;

return new class extends Migration
{
    /**
     * Employee document types, per company (CNIC, passport, contract, ...).
     * Every existing company gets the default list; companies created later
     * get it from EmployeeServiceProvider's Company::created hook.
     */
    public function up(): void
    {
        Schema::create('employees_document_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->boolean('has_expiry')->default(false);
            $table->boolean('visible_to_employee')->default(true);
            $table->boolean('required_for_new_hires')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'employees_document_types_company_code_unique');
        });

        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DB::table('employees_document_types')->insertOrIgnore(
                array_map(fn (array $type): array => $type + [
                    'company_id' => $companyId,
                    'is_active'  => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], DefaultDocumentTypes::rows()),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_document_types');
    }
};
