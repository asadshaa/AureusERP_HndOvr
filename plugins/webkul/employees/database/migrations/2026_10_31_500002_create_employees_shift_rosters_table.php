<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Employee\Support\DefaultShifts;

/**
 * Rotating roster patterns per company (HR parity plan 3.4), e.g.
 * Morning x5, Off x2, Evening x5, Off x2, Night x5, Off x2. The pattern is
 * an ordered list of steps {shift_id (null = day off), days}; it repeats
 * every cycle_days days from each assignment's start date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees_shift_rosters')) {
            $this->createTable();
        }

        // The sample rotation for every existing company (not assigned to anyone).
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DefaultShifts::provisionPatternForCompany((int) $companyId);
        }
    }

    private function createTable(): void
    {
        Schema::create('employees_shift_rosters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'emp_shift_rosters_company_fk')->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('pattern');
            $table->unsignedSmallInteger('cycle_days');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'emp_shift_rosters_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'emp_shift_rosters_updated_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'emp_shift_rosters_company_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_shift_rosters');
    }
};
