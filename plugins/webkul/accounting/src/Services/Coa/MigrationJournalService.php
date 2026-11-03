<?php

namespace Webkul\Accounting\Services\Coa;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Services\PeriodLockService;
use Webkul\Accounting\Data\Coa\CoaRow;
use Webkul\Accounting\Models\CoaImportBatch;
use Webkul\Support\Models\Company;

/**
 * Turns imported CoA sheet balances into three balanced, posted migration
 * journals (Opening, Movement, Adjustment) through the normal ledger tables.
 *
 * Notes:
 *  - Lines are inserted raw (DB::table) on purpose: the MoveLine `saving` hook
 *    recomputes account_id from the journal default and would corrupt a
 *    multi-account journal. A balance migration is a controlled bulk post, so
 *    every column is set explicitly here.
 *  - Each journal must balance (Σdebit = Σcredit) or the whole import rolls
 *    back — no artificial balancing account is ever invented.
 *  - Idempotent: if a migration journal of a given kind already exists for
 *    the company -- from an earlier CoA import, or an opening-balance
 *    configurable import (which also tags coa_migration_kind = 'opening') --
 *    it is not created again. That skip is never silent: skippedJournals()
 *    names the kind, the existing entry and why, and CoaImportService stores
 *    it on the batch.
 *  - Only postable leaf accounts are used; group accounts are never posted to.
 *  - Subject to PeriodLockService like every other posting chokepoint: a
 *    closed period is closed for migration journals too, because a balance
 *    loaded into it after sign-off changes numbers that were already
 *    reported. The initial historical migration -- which by design writes
 *    balances that predate any lock -- passes overridePeriodLock explicitly
 *    (CoaImportService reads it from the import options, which are stored on
 *    the batch), rather than this service ignoring the lock for everyone.
 *  - Records posted_by (the authenticated user, when there is one) and
 *    posted_at, like every other posting path.
 */
class MigrationJournalService
{
    protected float $tolerance = 0.005;

    /**
     * @var array<int, array{kind: string, existing_move_id: int, existing_move_name: ?string, existing_source: string, reason: string}>
     */
    protected array $skippedJournals = [];

    /**
     * @param  array<int, CoaRow>  $rows
     * @return int number of journals created
     */
    public function createForBatch(
        CoaImportBatch $batch,
        array $rows,
        Company $company,
        int $currencyId,
        ?string $openingDate,
        ?string $movementDate,
        ?string $adjustmentDate,
        bool $overridePeriodLock = false,
    ): int {
        $this->skippedJournals = [];

        if (! $openingDate || ! $movementDate || ! $adjustmentDate) {
            throw new RuntimeException('Migration journals require opening, movement and adjustment dates.');
        }
        if ($openingDate >= $movementDate) {
            throw new RuntimeException('The opening journal date must be before the movement period date.');
        }
        if ($adjustmentDate < $movementDate) {
            throw new RuntimeException('The adjustment date cannot be before the movement date.');
        }

        $journalId = $this->migrationJournalId($company);
        $accountIdByCode = $this->postableAccountIdsByCode($company, $rows);

        $created = 0;

        $created += $this->createJournal(
            $batch, $company, $journalId, $currencyId, 'opening', $openingDate, 'Opening Balances (migration)',
            $this->lines($rows, $accountIdByCode, fn ($r) => [$r->openingDebit, $r->openingCredit]),
            $overridePeriodLock,
        );

        $created += $this->createJournal(
            $batch, $company, $journalId, $currencyId, 'movement', $movementDate, 'Period Movement (migration)',
            $this->lines($rows, $accountIdByCode, fn ($r) => [$r->movementDebit, $r->movementCredit]),
            $overridePeriodLock,
        );

        $created += $this->createJournal(
            $batch, $company, $journalId, $currencyId, 'adjustment', $adjustmentDate, 'Adjustments (migration)',
            $this->lines($rows, $accountIdByCode, fn ($r) => [$r->adjustmentDebit, $r->adjustmentCredit]),
            $overridePeriodLock,
        );

        return $created;
    }

    /**
     * The journals the last createForBatch() call did not create because one
     * of that kind already existed for the company.
     *
     * @return array<int, array{kind: string, existing_move_id: int, existing_move_name: ?string, existing_source: string, reason: string}>
     */
    public function skippedJournals(): array
    {
        return $this->skippedJournals;
    }

    /**
     * @param  array<int, array{account_id: int, debit: float, credit: float}>  $lines
     */
    protected function createJournal(
        CoaImportBatch $batch,
        Company $company,
        int $journalId,
        int $currencyId,
        string $kind,
        string $date,
        string $name,
        array $lines,
        bool $overridePeriodLock = false,
    ): int {
        if ($lines === []) {
            return 0;
        }

        // Idempotency: never create a second migration journal of this kind for
        // the company -- but report it, and which entry already holds the slot.
        $existing = DB::table('accounts_account_moves')
            ->where('company_id', $company->id)
            ->where('coa_migration_kind', $kind)
            ->orderBy('id')
            ->first(['id', 'name', 'accounting_source_type', 'accounting_source_id', 'coa_import_batch_id']);

        if ($existing) {
            $source = match (true) {
                $existing->coa_import_batch_id !== null    => "CoA import batch #{$existing->coa_import_batch_id}",
                $existing->accounting_source_type !== null => class_basename((string) $existing->accounting_source_type)." #{$existing->accounting_source_id}",
                default                                    => 'an earlier migration',
            };

            $this->skippedJournals[] = [
                'kind'               => $kind,
                'existing_move_id'   => (int) $existing->id,
                'existing_move_name' => $existing->name,
                'existing_source'    => $source,
                'reason'             => "A {$kind} migration journal already exists for this company ({$existing->name}, from {$source}), so this import did not post its {$kind} balances. Reverse that entry first if these balances should replace it.",
            ];

            return 0;
        }

        if (! $overridePeriodLock) {
            app(PeriodLockService::class)->assertNotLocked((int) $company->id, $date);
        }

        $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);

        if (abs($totalDebit - $totalCredit) > $this->tolerance) {
            throw new RuntimeException(
                "Migration journal '{$kind}' is unbalanced: debit {$totalDebit} != credit {$totalCredit}. Import rolled back."
            );
        }

        $now = now();

        $moveId = DB::table('accounts_account_moves')->insertGetId([
            'journal_id'          => $journalId,
            'company_id'          => $company->id,
            'currency_id'         => $currencyId,
            'date'                => $date,
            'name'                => $name,
            'move_type'           => MoveType::ENTRY->value,
            'state'               => MoveState::POSTED->value,
            'coa_migration_kind'  => $kind,
            'coa_import_batch_id' => $batch->id,
            'posted_by_id'        => Auth::id(),
            'posted_at'           => $now,
            'posted_before'       => true,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        $sort = 0;
        $rowsToInsert = [];
        foreach ($lines as $line) {
            $rowsToInsert[] = [
                'move_id'      => $moveId,
                'journal_id'   => $journalId,
                'account_id'   => $line['account_id'],
                'company_id'   => $company->id,
                'currency_id'  => $currencyId,
                'date'         => $date,
                'debit'        => $line['debit'],
                'credit'       => $line['credit'],
                'balance'      => round($line['debit'] - $line['credit'], 2),
                'parent_state' => MoveState::POSTED->value,
                'name'         => $name,
                'sort'         => $sort++,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        DB::table('accounts_account_move_lines')->insert($rowsToInsert);

        return 1;
    }

    /**
     * @param  array<int, CoaRow>  $rows
     * @param  array<string, int>  $accountIdByCode
     * @param  callable  $amounts  fn(CoaRow) => [debit, credit]
     * @return array<int, array{account_id: int, debit: float, credit: float}>
     */
    protected function lines(array $rows, array $accountIdByCode, callable $amounts): array
    {
        $lines = [];

        foreach ($rows as $row) {
            [$debit, $credit] = $amounts($row);

            if (abs($debit) < $this->tolerance && abs($credit) < $this->tolerance) {
                continue;
            }

            $accountId = $accountIdByCode[$row->code] ?? null;
            if ($accountId === null) {
                continue; // non-postable/unmatched code (e.g. group) — skip
            }

            $lines[] = [
                'account_id' => $accountId,
                'debit'      => round((float) $debit, 2),
                'credit'     => round((float) $credit, 2),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, CoaRow>  $rows
     * @return array<string, int>
     */
    protected function postableAccountIdsByCode(Company $company, array $rows): array
    {
        $codes = array_values(array_filter(array_map(fn ($r) => $r->code, $rows)));

        return Account::query()
            ->postable()
            ->whereIn('code', $codes)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->pluck('id', 'code')
            ->all();
    }

    protected function migrationJournalId(Company $company): int
    {
        $journal = Journal::query()
            ->where('company_id', $company->id)
            ->where('type', 'general')
            ->orderBy('id')
            ->first()
            ?? Journal::query()->where('company_id', $company->id)->orderBy('id')->first();

        if (! $journal) {
            throw new RuntimeException('No journal available for migration entries.');
        }

        return $journal->id;
    }
}
