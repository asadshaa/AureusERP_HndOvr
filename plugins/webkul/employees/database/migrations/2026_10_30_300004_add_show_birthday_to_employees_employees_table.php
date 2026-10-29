<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Birthday privacy: employees are shown in the dashboard's birthdays
     * card (day and month only) unless they, or HR, switch this off.
     */
    public function up(): void
    {
        if (Schema::hasColumn('employees_employees', 'show_birthday')) {
            return;
        }

        Schema::table('employees_employees', function (Blueprint $table): void {
            $table->boolean('show_birthday')->default(true)->after('birthday');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees_employees', 'show_birthday')) {
            return;
        }

        Schema::table('employees_employees', function (Blueprint $table): void {
            $table->dropColumn('show_birthday');
        });
    }
};
