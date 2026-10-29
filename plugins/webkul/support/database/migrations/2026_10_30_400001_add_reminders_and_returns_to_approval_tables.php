<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR parity plan 2.7 -- approval reminders and "return for changes".
 *
 *  - workflows: per-workflow reminder settings. Empty numbers fall back to
 *    config('approvals.reminders.*') (24 h, every 24 h, 3 reminders).
 *  - requests: submission_round (1 on the first submission, +1 on every
 *    resubmission after a return) and current_step_started_at (when the
 *    current step began waiting -- the "waiting for Xh" clock).
 *  - decisions: submission_round, so a resubmitted request starts its steps
 *    with no approvals counted from the previous round.
 *
 * Additive only. current_step_started_at is backfilled for open requests from
 * the last decision (the step they are on began then) or the submission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_approval_workflows', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_approval_workflows', 'reminders_enabled')) {
                $table->boolean('reminders_enabled')->default(true)->after('is_active');
            }
            if (! Schema::hasColumn('support_approval_workflows', 'remind_after_hours')) {
                $table->unsignedSmallInteger('remind_after_hours')->nullable()->after('reminders_enabled');
            }
            if (! Schema::hasColumn('support_approval_workflows', 'remind_every_hours')) {
                $table->unsignedSmallInteger('remind_every_hours')->nullable()->after('remind_after_hours');
            }
            if (! Schema::hasColumn('support_approval_workflows', 'max_reminders')) {
                $table->unsignedTinyInteger('max_reminders')->nullable()->after('remind_every_hours');
            }
        });

        Schema::table('support_approval_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_approval_requests', 'submission_round')) {
                $table->unsignedInteger('submission_round')->default(1)->after('current_step_sequence');
            }
            if (! Schema::hasColumn('support_approval_requests', 'current_step_started_at')) {
                $table->timestamp('current_step_started_at')->nullable()->after('submitted_at');
            }
        });

        Schema::table('support_approval_decisions', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_approval_decisions', 'submission_round')) {
                $table->unsignedInteger('submission_round')->default(1)->after('step_id');
            }
        });

        DB::table('support_approval_requests')
            ->whereNull('current_step_started_at')
            ->update([
                'current_step_started_at' => DB::raw('COALESCE((SELECT MAX(d.decided_at) FROM support_approval_decisions d WHERE d.request_id = support_approval_requests.id), support_approval_requests.submitted_at, support_approval_requests.created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('support_approval_decisions', function (Blueprint $table): void {
            $table->dropColumn('submission_round');
        });

        Schema::table('support_approval_requests', function (Blueprint $table): void {
            $table->dropColumn(['submission_round', 'current_step_started_at']);
        });

        Schema::table('support_approval_workflows', function (Blueprint $table): void {
            $table->dropColumn(['reminders_enabled', 'remind_after_hours', 'remind_every_hours', 'max_reminders']);
        });
    }
};
