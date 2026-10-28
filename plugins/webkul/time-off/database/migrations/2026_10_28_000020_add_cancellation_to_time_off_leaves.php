<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withdraw / cancel for Time Off (LeaveCancellationService): who asked for
 * the cancellation of an approved leave and why, and who cancelled it and
 * why. The state itself becomes "cancel" (time_off_leaves.state is a plain
 * string). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_off_leaves', function (Blueprint $table): void {
            if (! Schema::hasColumn('time_off_leaves', 'cancellation_requested_at')) {
                $table->timestamp('cancellation_requested_at')->nullable()->after('rejection_reason');
                $table->foreignId('cancellation_requested_by')->nullable()->after('cancellation_requested_at')->constrained('users')->nullOnDelete();
                $table->text('cancellation_request_reason')->nullable()->after('cancellation_requested_by');
                $table->timestamp('cancelled_at')->nullable()->after('cancellation_request_reason');
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('time_off_leaves', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancellation_requested_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'cancellation_requested_at',
                'cancellation_request_reason',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
