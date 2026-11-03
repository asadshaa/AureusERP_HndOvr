<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting an account used to cascade to its journal items, silently
     * removing posted ledger history. The foreign key now restricts the
     * delete, so an account with journal items can no longer be removed.
     * Column attributes (nullable bigint unsigned) are unchanged. Aborts if
     * any journal item points at a missing account, since the key could not
     * be recreated over orphan rows.
     */
    public function up(): void
    {
        $this->recreateForeignKey('restrict');
    }

    public function down(): void
    {
        $this->recreateForeignKey('cascade');
    }

    private function recreateForeignKey(string $onDelete): void
    {
        $orphans = DB::table('accounts_account_move_lines')
            ->whereNotNull('account_id')
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('accounts_accounts')
                ->whereColumn('accounts_accounts.id', 'accounts_account_move_lines.account_id'))
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException("{$orphans} journal item(s) reference a missing account; fix them before changing accounts_account_move_lines_account_id_foreign.");
        }

        Schema::table('accounts_account_move_lines', function (Blueprint $table) use ($onDelete) {
            $table->dropForeign('accounts_account_move_lines_account_id_foreign');

            $table->foreign('account_id', 'accounts_account_move_lines_account_id_foreign')
                ->references('id')
                ->on('accounts_accounts')
                ->onDelete($onDelete);
        });
    }
};
