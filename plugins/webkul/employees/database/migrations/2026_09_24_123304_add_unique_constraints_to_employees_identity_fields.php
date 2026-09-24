<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Government-issued identity numbers (national ID/CNIC, SSN, SIN,
     * passport) had no uniqueness at all -- the same person could be
     * onboarded twice under two different employee records, silently
     * breaking payroll/leave/attendance, which all assume one employee
     * record per real person. Confirmed live: all 26 real employee rows
     * currently have every one of these fields NULL (none populated
     * yet), so this is purely a forward-looking guard, not a correction
     * of existing data -- and MySQL allows unlimited NULLs in a unique
     * index, so it doesn't block the common case of an employee record
     * with these fields left blank.
     */
    public function up(): void
    {
        foreach (['identification_id', 'ssnid', 'sinid', 'passport_id'] as $column) {
            $duplicateGroups = DB::table('employees_employees')
                ->select('company_id', $column)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy('company_id', $column)
                ->havingRaw('count(*) > 1')
                ->get();

            foreach ($duplicateGroups as $group) {
                $value = $group->$column;

                $ids = DB::table('employees_employees')
                    ->where('company_id', $group->company_id)
                    ->where($column, $value)
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($ids->slice(1) as $duplicateId) {
                    DB::table('employees_employees')
                        ->where('id', $duplicateId)
                        ->update([$column => "{$value}-DUPLICATE-{$duplicateId}"]);
                }
            }
        }

        Schema::table('employees_employees', function (Blueprint $table) {
            $table->unique(['company_id', 'identification_id']);
            $table->unique(['company_id', 'ssnid']);
            $table->unique(['company_id', 'sinid']);
            $table->unique(['company_id', 'passport_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees_employees', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'identification_id']);
            $table->dropUnique(['company_id', 'ssnid']);
            $table->dropUnique(['company_id', 'sinid']);
            $table->dropUnique(['company_id', 'passport_id']);
        });
    }
};
