<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per company holding the CURRENT lock state -- everything
     * dated on or before locked_through_date is closed to new postings.
     * A null locked_through_date means the company has nothing locked.
     */
    public function up(): void
    {
        Schema::create('accounts_period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->date('locked_through_date')->nullable();
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_period_locks');
    }
};
