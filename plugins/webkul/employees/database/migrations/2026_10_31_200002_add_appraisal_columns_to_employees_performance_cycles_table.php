<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appraisal settings of a performance cycle (HR Parity 3.2): the
     * template it runs (copied into template_snapshot at launch), the
     * self-review and manager-review deadlines, who takes part (all active
     * employees or selected departments), when the employee sees the manager
     * review, and who launched / closed it. Additive; existing cycles keep
     * working as plain overall-rating reviews.
     */
    public function up(): void
    {
        Schema::table('employees_performance_cycles', function (Blueprint $table): void {
            $table->foreignId('template_id')->nullable()->after('creator_id')->constrained('employees_appraisal_templates')->nullOnDelete();
            $table->json('template_snapshot')->nullable()->after('settings');
            $table->date('self_review_due_on')->nullable()->after('ends_on');
            $table->date('manager_review_due_on')->nullable()->after('self_review_due_on');
            $table->string('participant_scope', 20)->default('all')->after('manager_review_due_on');
            $table->json('department_ids')->nullable()->after('participant_scope');
            $table->string('results_visibility', 30)->default('after_close')->after('department_ids');
            $table->timestamp('launched_at')->nullable();
            $table->foreignId('launched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees_performance_cycles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_id');
            $table->dropConstrainedForeignId('launched_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['template_snapshot', 'self_review_due_on', 'manager_review_due_on', 'participant_scope', 'department_ids', 'results_visibility', 'launched_at', 'closed_at']);
        });
    }
};
