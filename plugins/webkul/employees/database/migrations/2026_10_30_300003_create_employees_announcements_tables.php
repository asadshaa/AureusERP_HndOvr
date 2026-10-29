<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company announcements posted by HR (HR Parity 2.6): audience is the
     * whole company or some departments (sub-departments included), shown
     * between publish_at and expires_at, pinned ones first. One read row per
     * user records when they read it and, when asked, acknowledged it.
     */
    public function up(): void
    {
        Schema::create('employees_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('audience', 20)->default('all');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('requires_acknowledgement')->default(false);
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'publish_at', 'expires_at'], 'employees_announcements_window_index');
        });

        Schema::create('employees_announcement_departments', function (Blueprint $table): void {
            $table->foreignId('announcement_id')->constrained('employees_announcements')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('employees_departments')->cascadeOnDelete();

            $table->primary(['announcement_id', 'department_id'], 'employees_announcement_departments_primary');
        });

        Schema::create('employees_announcement_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained('employees_announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees_employees')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id'], 'employees_announcement_reads_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_announcement_reads');
        Schema::dropIfExists('employees_announcement_departments');
        Schema::dropIfExists('employees_announcements');
    }
};
