<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company employee code settings (prefix, number width) and the next
     * number to hand out. A row is created on first use with a prefix taken
     * from the company name; HR changes it in Employees -> Configurations ->
     * Employee codes.
     */
    public function up(): void
    {
        Schema::create('employees_employee_code_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('prefix', 10);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_employee_code_sequences');
    }
};
