<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move;
use Webkul\Account\Services\PeriodLockService;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\FsTag;
use Webkul\Security\Models\User;

class BankJournalService
{
    public function createDraft(BankTransactionMapping $mapping): Move
    {
        return DB::transaction(function () use ($mapping): Move {
            $mapping = BankTransactionMapping::query()
                ->with(['statementLine.statement', 'matchedMove', 'transferMatch.outgoingLine.mapping', 'transferMatch.incomingLine.mapping'])
                ->lockForUpdate()
                ->findOrFail($mapping->id);

            if ($mapping->move_id) {
                return Move::query()->findOrFail($mapping->move_id);
            }

            $line = $mapping->statementLine;
            $statement = $line->statement;
            if ($statement->import_status !== BankImportStatus::Validated->value) {
                throw new RuntimeException('Only reconciled, validated statements can generate journal entries.');
            }
            if ($line->conversion_status !== ConversionStatus::Complete->value || $line->company_signed_amount === null) {
                throw new RuntimeException('Posting is blocked until an approved exchange rate completes the company-currency conversion.');
            }

            $isApprovedTransfer = $mapping->transferMatch
                && $mapping->transferMatch->status === 'approved'
                && $mapping->transferMatch->outgoing_statement_line_id === $line->id;

            if ($mapping->review_status !== BankReviewStatus::Approved && ! $isApprovedTransfer) {
                throw new RuntimeException('The bank mapping must be approved before a draft journal is generated.');
            }

            [$debitAccountId, $creditAccountId, $companyAmount, $originalAmount] = $isApprovedTransfer
                ? $this->transferAccounts($mapping)
                : $this->mappedAccounts($mapping);

            if (! BigDecimal::of($companyAmount)->isPositive() || ! $debitAccountId || ! $creditAccountId || $debitAccountId === $creditAccountId) {
                throw new RuntimeException('The journal mapping is incomplete or would not create a valid balanced entry.');
            }

            if (! $isApprovedTransfer && $mapping->fs_tag_id !== null
                && (int) FsTag::query()->whereKey($mapping->fs_tag_id)->value('account_id') !== (int) $mapping->offset_account_id) {
                throw new RuntimeException('The mapping\'s FS Tag is configured for a different GL account than its offset GL; re-review the mapping before generating the journal.');
            }

            $now = now();
            $moveId = DB::table('accounts_account_moves')->insertGetId([
                'journal_id'             => $statement->journal_id,
                'company_id'             => $mapping->company_id,
                'creator_id'             => $mapping->reviewer_id,
                'partner_id'             => $mapping->matchedMove?->partner_id,
                'commercial_partner_id'  => $mapping->matchedMove?->commercial_partner_id,
                'currency_id'            => $statement->currency_id,
                'original_currency_id'   => $line->original_currency_id,
                'company_currency_id'    => $line->company_currency_id,
                'exchange_rate_id'       => $line->exchange_rate_id,
                'exchange_rate'          => $line->exchange_rate,
                'rate_date'              => $line->rate_date,
                'rate_source'            => $line->rate_source,
                'rate_type'              => $line->rate_type,
                'conversion_status'      => $line->conversion_status,
                'statement_line_id'      => $line->id,
                'date'                   => $line->transaction_date,
                'name'                   => 'Draft '.$mapping->map_reference,
                'reference'              => $line->reference,
                'move_type'              => MoveType::ENTRY->value,
                'state'                  => MoveState::DRAFT->value,
                'accounting_source_type' => 'bank_mapping',
                'accounting_source_id'   => $mapping->id,
                'bank_statement_id'      => $statement->id,
                'bank_mapping_id'        => $mapping->id,
                'cash_flow_category'     => $mapping->cash_flow_category,
                'tax_treatment'          => $mapping->tax_treatment,
                'review_status'          => $isApprovedTransfer ? 'approved_transfer' : 'approved',
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);

            $this->insertLines(
                moveId: $moveId,
                statementId: $statement->id,
                statementLineId: $line->id,
                journalId: $statement->journal_id,
                companyId: $mapping->company_id,
                partnerId: $mapping->matchedMove?->partner_id,
                originalCurrencyId: (int) $line->original_currency_id,
                companyCurrencyId: (int) $line->company_currency_id,
                date: (string) $line->transaction_date?->toDateString(),
                description: (string) $line->description,
                reference: (string) $line->reference,
                debitAccountId: $debitAccountId,
                creditAccountId: $creditAccountId,
                originalAmount: $originalAmount,
                companyAmount: $companyAmount,
                exchangeRateId: $line->exchange_rate_id,
                exchangeRate: (string) $line->exchange_rate,
                rateDate: (string) $line->rate_date?->toDateString(),
                rateSource: (string) $line->rate_source,
                rateType: (string) $line->rate_type,
                offsetAccountId: $mapping->offset_account_id,
                fsTagId: $mapping->fs_tag_id,
            );

            $mapping->update([
                'move_id'        => $moveId,
                'posting_status' => BankPostingStatus::Draft,
            ]);

            if ($isApprovedTransfer) {
                $mapping->transferMatch->incomingLine->mapping?->update(['move_id' => $moveId]);
            }

            return Move::query()->with('lines')->findOrFail($moveId);
        });
    }

    public function post(BankTransactionMapping $mapping, User $reviewer): Move
    {
        return DB::transaction(function () use ($mapping, $reviewer): Move {
            $mapping = BankTransactionMapping::query()->with(['statementLine.statement', 'transferMatch.incomingLine.mapping'])->lockForUpdate()->findOrFail($mapping->id);

            if ($mapping->review_status !== BankReviewStatus::Approved && $mapping->review_status !== BankReviewStatus::Posted) {
                throw new RuntimeException('Only approved transaction mappings can be posted.');
            }

            $move = $mapping->move_id ? Move::query()->lockForUpdate()->find($mapping->move_id) : null;
            $move ??= $this->createDraft($mapping);

            if ($move->state === MoveState::POSTED) {
                return $move;
            }

            $accounts = DB::table('accounts_account_move_lines')
                ->join('accounts_accounts', 'accounts_accounts.id', '=', 'accounts_account_move_lines.account_id')
                ->where('accounts_account_move_lines.move_id', $move->id)
                ->select('accounts_accounts.id', 'accounts_accounts.is_group', 'accounts_accounts.deprecated')
                ->get();

            if ($accounts->some(fn ($acc) => $acc->is_group || $acc->deprecated)) {
                throw new RuntimeException('Cannot post journal move referencing a group or deprecated account.');
            }

            app(PeriodLockService::class)->assertNotLocked((int) $move->company_id, $move->date);

            $totals = DB::table('accounts_account_move_lines')
                ->where('move_id', $move->id)
                ->selectRaw('ROUND(SUM(debit), 2) debit, ROUND(SUM(credit), 2) credit')
                ->first();

            if (! $totals
                || BigDecimal::of((string) $totals->debit)->minus((string) $totals->credit)->abs()->isGreaterThan('0.005')
                || ! BigDecimal::of((string) $totals->debit)->isPositive()) {
                throw new RuntimeException('Draft journal is not balanced and cannot be posted.');
            }

            $this->assertClearingAccountsNotOverdrawn($move);

            DB::table('accounts_account_moves')->where('id', $move->id)->update([
                'state'         => MoveState::POSTED->value,
                'review_status' => 'posted',
                // Drop the "Draft " prefix the entry was created with, so a
                // posted entry is not labelled as a draft in the ledger.
                'name'          => $mapping->map_reference,
                // Same posting audit trail confirmMove() and
                // ManualAdjustmentService::post() record.
                'posted_by_id'  => $reviewer->id,
                'posted_at'     => now(),
                'posted_before' => true,
                'updated_at'    => now(),
            ]);
            DB::table('accounts_account_move_lines')->where('move_id', $move->id)->update([
                'parent_state' => MoveState::POSTED->value,
                'updated_at'   => now(),
            ]);

            $mapping->update([
                'review_status'  => BankReviewStatus::Posted,
                'posting_status' => BankPostingStatus::Posted,
                'reviewer_id'    => $reviewer->id,
                'reviewed_at'    => $mapping->reviewed_at ?? now(),
                'posted_at'      => now(),
            ]);

            if ($mapping->transferMatch) {
                $mapping->transferMatch->incomingLine->mapping?->update([
                    'move_id'        => $move->id,
                    'posting_status' => BankPostingStatus::MatchedDoNotPost,
                ]);
            }

            $this->reconcileMatchedObligation($mapping, $move);

            $this->refreshStatementCompletion($mapping->statementLine->statement);

            return $move->fresh('lines');
        });
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: string}
     */
    protected function mappedAccounts(BankTransactionMapping $mapping): array
    {
        $line = $mapping->statementLine;
        $originalAmount = BigDecimal::max(
            (string) ($line->original_debit ?? $line->debit),
            (string) ($line->original_credit ?? $line->credit),
        )->__toString();
        $companyAmount = BigDecimal::max(
            (string) ($line->company_debit ?? '0'),
            (string) ($line->company_credit ?? '0'),
        )->__toString();

        return BigDecimal::of((string) ($line->original_credit ?? $line->credit))->isPositive()
            ? [$mapping->bank_gl_account_id, $mapping->offset_account_id, $companyAmount, $originalAmount]
            : [$mapping->offset_account_id, $mapping->bank_gl_account_id, $companyAmount, $originalAmount];
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: string}
     */
    protected function transferAccounts(BankTransactionMapping $mapping): array
    {
        $match = $mapping->transferMatch;
        if ((int) $match->outgoing_currency_id !== (int) $match->incoming_currency_id) {
            throw new RuntimeException('Cross-currency transfers require an explicit conversion journal.');
        }
        $receivingBankId = $match->incomingLine->mapping?->bank_gl_account_id;
        $sendingBankId = $match->outgoingLine->mapping?->bank_gl_account_id;

        return [
            $receivingBankId,
            $sendingBankId,
            (string) $match->company_amount,
            (string) $match->outgoing_amount,
        ];
    }

    /**
     * A bank-mapping journal is only ever internally balanced by construction
     * (debit always equals credit) -- that alone doesn't catch posting a
     * SECOND, unrelated settlement against a payment-clearing account that a
     * different, already-posted mapping already cleared back to zero. Two
     * such postings, each individually balanced, together silently invent
     * real cash: the real Bank account gains money that never actually
     * arrived, while the clearing account is driven past zero into a
     * balance no legitimate in-transit payment could produce. Confirmed
     * live: a manually-mapped duplicate bank line with its Offset GL set to
     * an already-cleared Outstanding Receipts account posted with zero
     * warnings and put a real, false Rs 100,300 into the Bank GL account.
     *
     * "Clearing account" here is derived from real data, not a hardcoded ID:
     * any account that some Payment has actually used as its
     * outstanding_account_id. Its expected resting side (debit-positive for
     * inbound/receipts, credit-positive for outbound/payments) is likewise
     * derived from which payment_type used it, not assumed from
     * account_type -- both Outstanding Receipts and Outstanding Payments in
     * this app are coded as the same asset_current type despite expecting
     * opposite normal balances.
     */
    protected function assertClearingAccountsNotOverdrawn(Move $move): void
    {
        $lines = DB::table('accounts_account_move_lines')
            ->where('move_id', $move->id)
            ->select('account_id', 'debit', 'credit')
            ->get();

        $clearingAccountIds = $lines->pluck('account_id')->unique()->values();

        if ($clearingAccountIds->isEmpty()) {
            return;
        }

        // Only this company's payments decide whether an account is one of
        // its clearing accounts and on which side it rests: accounts are
        // shared between companies, and another company's payments say
        // nothing about this company's balance (which is also scoped below).
        $expectedSides = DB::table('accounts_account_payments')
            ->where('company_id', $move->company_id)
            ->whereIn('outstanding_account_id', $clearingAccountIds)
            ->whereNotNull('outstanding_account_id')
            ->select('outstanding_account_id', 'payment_type')
            ->distinct()
            ->get()
            ->groupBy('outstanding_account_id')
            ->map(fn ($rows) => $rows->pluck('payment_type')->unique());

        $guardedAccountIds = $expectedSides
            ->filter(fn ($sides) => $sides->count() === 1)
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values();

        if ($guardedAccountIds->isEmpty()) {
            return;
        }

        // Serialize concurrent postings against the same clearing account:
        // without this, two transactions can each read the same pre-posting
        // balance, both pass, and together overdraw it. The account rows are
        // locked in id order (deadlock-safe) for the rest of post()'s
        // transaction, and the balance below is a locking read so it sees
        // what a transaction that held the lock before us committed.
        DB::table('accounts_accounts')->whereIn('id', $guardedAccountIds)->orderBy('id')->lockForUpdate()->pluck('id');

        foreach ($lines as $line) {
            $sides = $expectedSides->get($line->account_id);

            // Not a clearing account (never used as any payment's outstanding
            // account), or used for both directions -- nothing safe to assert.
            if (! $sides || $sides->count() !== 1) {
                continue;
            }

            $expectedInbound = $sides->first() === 'inbound';

            // A clearing account may be shared by several companies; only
            // this company's postings make up its balance.
            $currentBalance = DB::table('accounts_account_move_lines')
                ->join('accounts_account_moves', 'accounts_account_moves.id', '=', 'accounts_account_move_lines.move_id')
                ->where('accounts_account_moves.state', MoveState::POSTED->value)
                ->where('accounts_account_moves.company_id', $move->company_id)
                ->where('accounts_account_move_lines.account_id', $line->account_id)
                ->selectRaw('COALESCE(SUM(accounts_account_move_lines.debit), 0) - COALESCE(SUM(accounts_account_move_lines.credit), 0) as balance')
                ->sharedLock()
                ->value('balance');

            $newBalance = BigDecimal::of((string) ($currentBalance ?? '0'))
                ->plus((string) $line->debit)
                ->minus((string) $line->credit);

            $overdrawn = $expectedInbound
                ? $newBalance->isLessThan('-0.01')
                : $newBalance->isGreaterThan('0.01');

            if ($overdrawn) {
                throw new RuntimeException(
                    'This posting would drive a payment-clearing account past its expected balance '.
                    '(new balance would be '.$newBalance.'), which normally means it is settling a '.
                    'payment that was already cleared by a different, already-posted mapping. '.
                    'Check whether the underlying invoice/bill payment has already been reconciled '.
                    'before posting this line again.'
                );
            }
        }
    }

    protected function insertLines(
        int $moveId,
        int $statementId,
        int $statementLineId,
        int $journalId,
        int $companyId,
        ?int $partnerId,
        int $originalCurrencyId,
        int $companyCurrencyId,
        string $date,
        string $description,
        string $reference,
        int $debitAccountId,
        int $creditAccountId,
        string $originalAmount,
        string $companyAmount,
        ?int $exchangeRateId,
        string $exchangeRate,
        string $rateDate,
        string $rateSource,
        string $rateType,
        ?int $offsetAccountId = null,
        ?int $fsTagId = null,
    ): void {
        $now = now();
        DB::table('accounts_account_move_lines')->insert([
            [
                'move_id'                  => $moveId, 'statement_id' => $statementId, 'statement_line_id' => $statementLineId,
                'journal_id'               => $journalId, 'account_id' => $debitAccountId, 'company_id' => $companyId,
                'fs_tag_id'                => ($offsetAccountId && $debitAccountId === $offsetAccountId) ? $fsTagId : null,
                'partner_id'               => $partnerId,
                'company_currency_id'      => $companyCurrencyId, 'currency_id' => $originalCurrencyId,
                'original_currency_id'     => $originalCurrencyId, 'date' => $date,
                'debit'                    => $companyAmount, 'credit' => 0, 'balance' => $companyAmount,
                'original_debit'           => $originalAmount, 'original_credit' => 0, 'original_signed_amount' => $originalAmount,
                'company_debit'            => $companyAmount, 'company_credit' => 0, 'company_signed_amount' => $companyAmount,
                'amount_currency'          => $originalAmount, 'amount_residual' => $companyAmount,
                'amount_residual_currency' => $originalAmount, 'reconciled' => false,
                'exchange_rate_id'         => $exchangeRateId, 'exchange_rate' => $exchangeRate,
                'rate_date'                => $rateDate, 'rate_source' => $rateSource, 'rate_type' => $rateType,
                'conversion_status'        => ConversionStatus::Complete->value, 'parent_state' => MoveState::DRAFT->value,
                'name'                     => $description, 'reference' => $reference, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'move_id'                  => $moveId, 'statement_id' => $statementId, 'statement_line_id' => $statementLineId,
                'journal_id'               => $journalId, 'account_id' => $creditAccountId, 'company_id' => $companyId,
                'fs_tag_id'                => ($offsetAccountId && $creditAccountId === $offsetAccountId) ? $fsTagId : null,
                'partner_id'               => $partnerId,
                'company_currency_id'      => $companyCurrencyId, 'currency_id' => $originalCurrencyId,
                'original_currency_id'     => $originalCurrencyId, 'date' => $date,
                'debit'                    => 0, 'credit' => $companyAmount, 'balance' => BigDecimal::of($companyAmount)->negated()->__toString(),
                'original_debit'           => 0, 'original_credit' => $originalAmount,
                'original_signed_amount'   => BigDecimal::of($originalAmount)->negated()->__toString(),
                'company_debit'            => 0, 'company_credit' => $companyAmount,
                'company_signed_amount'    => BigDecimal::of($companyAmount)->negated()->__toString(),
                'amount_currency'          => BigDecimal::of($originalAmount)->negated()->__toString(),
                'amount_residual'          => BigDecimal::of($companyAmount)->negated()->__toString(),
                'amount_residual_currency' => BigDecimal::of($originalAmount)->negated()->__toString(),
                'reconciled'               => false,
                'exchange_rate_id'         => $exchangeRateId, 'exchange_rate' => $exchangeRate,
                'rate_date'                => $rateDate, 'rate_source' => $rateSource, 'rate_type' => $rateType,
                'conversion_status'        => ConversionStatus::Complete->value, 'parent_state' => MoveState::DRAFT->value,
                'name'                     => $description, 'reference' => $reference, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    protected function refreshStatementCompletion($statement): void
    {
        $incomplete = BankTransactionMapping::query()
            ->whereHas('statementLine', fn ($query) => $query->where('statement_id', $statement->id))
            ->whereNotIn('posting_status', [
                BankPostingStatus::Posted->value,
                BankPostingStatus::MatchedDoNotPost->value,
                BankPostingStatus::DoNotPost->value,
            ])
            ->exists();

        $statement->update([
            'is_completed'  => ! $incomplete,
            'import_status' => $incomplete ? BankImportStatus::Validated->value : BankImportStatus::Posted->value,
        ]);
    }

    protected function reconcileMatchedObligation(BankTransactionMapping $mapping, Move $bankMove): void
    {
        if ($mapping->match_type !== 'obligation' || ! $mapping->matched_move_id) {
            return;
        }

        $bankMove->refresh()->load('lines.account');
        $obligation = Move::query()
            ->where('company_id', $mapping->company_id)
            ->where('state', MoveState::POSTED)
            ->with('paymentTermLines.account')
            ->findOrFail($mapping->matched_move_id);
        $obligationLine = $obligation->paymentTermLines
            ->first(fn ($line): bool => ! $line->reconciled && (int) $line->account_id === (int) $mapping->offset_account_id);
        $bankLine = $bankMove->lines
            ->first(fn ($line): bool => (int) $line->account_id === (int) $mapping->offset_account_id);

        if (! $obligationLine || ! $bankLine) {
            throw new RuntimeException('The matched obligation no longer has a compatible open receivable/payable line.');
        }

        AccountFacade::reconcile($obligationLine->newCollection([$obligationLine, $bankLine]));
        $obligation->refresh();
        $obligation->computePaymentState();
        $obligation->save();
    }
}
