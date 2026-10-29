<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appraisal form answers on a performance review (HR Parity 3.2): the
     * self and manager ratings / comments per template item, the weighted
     * final score and its band, HR calibration (rating, reason, who, when),
     * the employee's acknowledgement, who actually did the manager review
     * (the line manager or HR when there is none) and a skipped self review.
     * Next-period goals reuse employees_performance_goals (period = next).
     * Additive; existing reviews keep their overall ratings.
     */
    public function up(): void
    {
        Schema::table('employees_performance_reviews', function (Blueprint $table): void {
            $table->json('self_answers')->nullable()->after('competency_ratings');
            $table->json('manager_answers')->nullable()->after('self_answers');
            $table->decimal('final_rating', 4, 2)->nullable()->after('manager_rating');
            $table->string('final_band', 80)->nullable()->after('final_rating');
            $table->decimal('calibrated_rating', 4, 2)->nullable()->after('final_band');
            $table->text('calibration_reason')->nullable()->after('calibrated_rating');
            $table->foreignId('calibrated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calibrated_at')->nullable();
            $table->foreignId('manager_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('self_skipped_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_comment')->nullable();

            $table->index(['cycle_id', 'status'], 'performance_reviews_cycle_status_index');
            $table->index(['reviewer_id', 'status'], 'performance_reviews_reviewer_status_index');
        });

        Schema::table('employees_performance_goals', function (Blueprint $table): void {
            $table->string('period', 20)->default('current')->after('review_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees_performance_goals', function (Blueprint $table): void {
            $table->dropColumn('period');
        });

        Schema::table('employees_performance_reviews', function (Blueprint $table): void {
            $table->dropIndex('performance_reviews_cycle_status_index');
            $table->dropIndex('performance_reviews_reviewer_status_index');
            $table->dropConstrainedForeignId('calibrated_by');
            $table->dropConstrainedForeignId('manager_reviewed_by');
            $table->dropColumn(['self_answers', 'manager_answers', 'final_rating', 'final_band', 'calibrated_rating', 'calibration_reason', 'calibrated_at', 'self_skipped_at', 'acknowledged_at', 'acknowledgement_comment']);
        });
    }
};
