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

            DB::table('accounts_account_moves')->where('id', $move->id)->update([
                'state'         => MoveState::POSTED->value,
                'review_status' => 'posted',
                // Drop the "Draft " prefix the entry was created with, so a
                // posted entry is not labelled as a draft in the ledger.
                'name'          => $mapping->map_reference,
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
