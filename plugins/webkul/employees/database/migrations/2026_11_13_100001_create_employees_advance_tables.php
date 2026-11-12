<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business advances: money an employee asks for before spending it on company
 * work (an ordinary Employee Request of the "Business Advance" type), and the
 * settlement of that money afterwards.
 *
 * - employees_requests.advance_request_id: on a settlement request, the
 *   advance it settles. Restricted on delete: an advance with a settlement is
 *   part of the audit trail.
 * - employees_advance_entries: what has been taken off an advance, one row per
 *   settlement (an approved Advance Settlement request) or per cash handed back.
 *   The outstanding amount of an advance is its amount minus these rows, so
 *   nothing is overwritten. Each settlement can be applied once (unique key),
 *   and every row names the journal entry that posted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees_requests', 'advance_request_id')) {
            Schema::table('employees_requests', function (Blueprint $table): void {
                $table->foreignId('advance_request_id')->nullable()->after('request_type_id')
                    ->constrained('employees_requests')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('employees_advance_entries')) {
            Schema::create('employees_advance_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('advance_request_id')->constrained('employees_requests')->restrictOnDelete();
                $table->foreignId('source_request_id')->nullable()->constrained('employees_requests')->restrictOnDelete();
                $table->string('type', 20);
                $table->decimal('amount', 20, 4);
                $table->date('entry_date');
                $table->foreignId('accounting_move_id')->nullable()->constrained('accounts_account_moves')->nullOnDelete();
                $table->string('reference')->nullable();
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['type', 'source_request_id'], 'employees_advance_entries_source_unique');
                $table->index(['advance_request_id', 'type'], 'employees_advance_entries_advance_type_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees_advance_entries');

        if (Schema::hasColumn('employees_requests', 'advance_request_id')) {
            Schema::table('employees_requests', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('advance_request_id');
            });
        }
    }
};
