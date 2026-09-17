<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Payment;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankTransactionMapping;

final class BankMatchingPriorityService
{
    public function __construct(
        private readonly BankTransferMatchingService $transfers,
        private readonly BankMappingService $rules,
    ) {}

    /** @return array{obligations: int, payments: int, transfers: int, rules: int} */
    public function run(int $companyId): array
    {
        $obligations = 0;
        $payments = 0;
        $mappings = BankTransactionMapping::query()
            ->with('statementLine')
            ->where('company_id', $companyId)
            ->whereNull('move_id')
            ->whereNull('transfer_match_id')
            ->whereNull('matched_reference')
            ->whereIn('review_status', [BankReviewStatus::Unmapped, BankReviewStatus::Suggested, BankReviewStatus::NeedsReview])
            ->get();

        // Documents and payments claimed by an earlier mapping in this same
        // run must not be offered again to a later one -- otherwise two bank
        // lines can both independently see the same open invoice or payment
        // as their unique match and both get suggested against it.
        $claimedMoveIds = [];
        $claimedPaymentIds = [];

        foreach ($mappings as $mapping) {
            $reference = trim((string) ($mapping->statementLine?->reference ?: $mapping->statementLine?->payment_reference));
            $description = trim((string) $mapping->statementLine?->description);
            if ($reference === '' && $description === '') {
                continue;
            }

            // A bank credit (money in) can only settle a receivable -- someone
            // paying us -- and a bank debit (money out) can only settle a
            // payable -- us paying someone. Without this, an incoming customer
            // payment could get suggested against an outstanding vendor bill.
            $direction = BigDecimal::of((string) ($mapping->statementLine?->original_credit ?? $mapping->statementLine?->credit ?? '0'))->isPositive()
                ? 'credit'
                : 'debit';
            $expectedAccountType = $direction === 'credit' ? 'asset_receivable' : 'liability_payable';

            $moves = Move::query()
                ->where('company_id', $companyId)
                ->where('state', 'posted')
                ->where('amount_residual', '>', 0)
                ->where('currency_id', $mapping->statementLine?->original_currency_id)
                ->whereNotIn('id', $claimedMoveIds)
                ->whereHas('lines.account', fn ($query) => $query->where('account_type', $expectedAccountType))
                ->where(function ($query) use ($reference, $description): void {
                    if ($reference !== '') {
                        $query->where('reference', $reference)
                            ->orWhere('payment_reference', $reference)
                            ->orWhere('name', $reference)
                            ->orWhere('booking_id', $reference)
                            ->orWhere('consolidated_number', $reference);
                    }

                    if ($description !== '') {
                        foreach (['reference', 'payment_reference', 'name', 'booking_id', 'consolidated_number'] as $column) {
                            $query->orWhere(function ($candidate) use ($column, $description): void {
                                $candidate->whereNotNull($column)
                                    ->whereRaw("CHAR_LENGTH({$column}) >= 4")
                                    ->whereRaw("? LIKE CONCAT('%', {$column}, '%')", [$description]);
                            });
                        }
                    }
                })
                ->with(['lines.account'])
                ->get()
                ->filter(fn (Move $move): bool => $this->amountFitsOpenBalance($mapping, (string) $move->amount_residual))
                ->values();
            if ($moves->count() > 1) {
                $mapping->update([
                    'review_status'          => BankReviewStatus::NeedsReview,
                    'confidence'             => 0,
                    'suggestion_explanation' => 'Multiple open documents match the bank identifiers and amount; select the intended obligation manually.',
                ]);

                continue;
            }

            $move = $moves->first();
            if ($move) {
                $accountId = $move->lines->first(fn ($line) => $line->account?->account_type?->value === $expectedAccountType)?->account_id;
                $matchedReference = collect([
                    $move->reference,
                    $move->payment_reference,
                    $move->name,
                    $move->booking_id,
                    $move->consolidated_number,
                ])->filter()->first(fn (string $identifier): bool => $identifier === $reference || str_contains($description, $identifier));
                $mapping->update([
                    'offset_account_id'      => $accountId,
                    'fs_tag_id'              => null,
                    'matched_move_id'        => $move->id,
                    'match_type'             => 'obligation',
                    'matched_reference'      => $matchedReference ?: $reference,
                    'transaction_type'       => 'Open document settlement',
                    'review_status'          => BankReviewStatus::Suggested,
                    'confidence'             => 1,
                    'suggestion_explanation' => 'A unique open document matched by invoice, booking or consolidated reference and compatible amount.',
                ]);
                $obligations++;
                $claimedMoveIds[] = $move->id;

                continue;
            }

            $paymentQuery = Payment::query()
                ->where('company_id', $companyId)
                ->whereNotIn('id', $claimedPaymentIds)
                ->where(fn ($query) => $query->where('payment_reference', $reference)->orWhere('name', $reference)->orWhere('memo', $reference));
            $paymentMatches = $reference === ''
                ? collect()
                : $paymentQuery->get()->filter(fn (Payment $payment): bool => $this->amountMatches($mapping, (string) $payment->amount))->values();
            if ($paymentMatches->count() > 1) {
                $mapping->update([
                    'review_status'          => BankReviewStatus::NeedsReview,
                    'confidence'             => 0,
                    'suggestion_explanation' => 'Multiple registered payments match this bank reference and amount.',
                ]);

                continue;
            }

            $payment = $paymentMatches->first();
            if ($payment && $this->amountMatches($mapping, (string) $payment->amount)) {
                $mapping->update([
                    // Prefer the outstanding (clearing) account: registering a
                    // payment already posted Dr Outstanding / Cr Receivable, so
                    // the receivable is settled. The bank line must clear the
                    // outstanding account, not credit the receivable a second
                    // time. Payments with no outstanding account never produced
                    // a journal entry, so those fall back to the destination.
                    'offset_account_id' => $payment->outstanding_account_id ?: $payment->destination_account_id,
                    'match_type'        => 'payment', 'matched_reference' => $reference,
                    'transaction_type'  => 'Registered payment', 'review_status' => BankReviewStatus::Suggested,
                    'confidence'        => 1, 'suggestion_explanation' => "Exact payment reference {$reference} and amount matched.",
                ]);
                $payments++;
                $claimedPaymentIds[] = $payment->id;
            }
        }

        $transferCount = count($this->transfers->detect($companyId));
        $ruleCount = BankStatement::query()->where('company_id', $companyId)->get()
            ->sum(fn (BankStatement $statement): int => $this->rules->suggestForStatement($statement));

        return ['obligations' => $obligations, 'payments' => $payments, 'transfers' => $transferCount, 'rules' => $ruleCount];
    }

    private function amountMatches(BankTransactionMapping $mapping, string $expected): bool
    {
        $actual = BigDecimal::max(
            (string) ($mapping->statementLine?->original_debit ?? '0'),
            (string) ($mapping->statementLine?->original_credit ?? '0'),
        );

        return $actual->minus($expected)->abs()->isLessThanOrEqualTo('0.01');
    }

    private function amountFitsOpenBalance(BankTransactionMapping $mapping, string $openBalance): bool
    {
        $actual = BigDecimal::max(
            (string) ($mapping->statementLine?->original_debit ?? '0'),
            (string) ($mapping->statementLine?->original_credit ?? '0'),
        );

        return $actual->isPositive()
            && $actual->minus($openBalance)->isLessThanOrEqualTo('0.01');
    }
}
