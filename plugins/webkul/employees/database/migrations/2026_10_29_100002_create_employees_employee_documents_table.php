<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee documents (CNIC copy, contract, degrees, ...). Files live on
     * the private 'local' disk and are only served through a signed,
     * permission-checked download route. Soft deletes keep the audit trail;
     * "Replace" soft-deletes the old row and keeps its file.
     */
    public function up(): void
    {
        Schema::create('employees_employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees_employees')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('employees_document_types')->restrictOnDelete();
            $table->string('title')->nullable();
            $table->string('number', 120)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('verified');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('replaced_by_id')->nullable()->constrained('employees_employee_documents')->nullOnDelete();
            $table->json('expiry_reminders')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id', 'document_type_id'], 'employee_documents_lookup_index');
            $table->index(['company_id', 'expires_on'], 'employee_documents_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_employee_documents');
    }
};
