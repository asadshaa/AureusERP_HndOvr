<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Models\BankTransferMatch;
use Webkul\Security\Models\User;

class BankTransferMatchingService
{
    /**
     * Mapping states a line must not be in to be offered as a transfer leg:
     * already approved/posted, or a reviewer's explicit decision (Rejected,
     * Do not post) that detection must never override.
     */
    private const SETTLED_REVIEW_STATUSES = [
        BankReviewStatus::Approved->value,
        BankReviewStatus::Posted->value,
        BankReviewStatus::Rejected->value,
        BankReviewStatus::DoNotPost->value,
    ];

    private const SETTLED_POSTING_STATUSES = [
        BankPostingStatus::Posted->value,
        BankPostingStatus::DoNotPost->value,
    ];

    /**
     * @return array<int, BankTransferMatch>
     */
    public function detect(int $companyId, int $maximumDateDistance = 3): array
    {
        $debits = BankStatementLine::query()
            ->with(['statement', 'mapping'])
            ->where('company_id', $companyId)
            ->where('debit', '>', 0)
            ->whereDoesntHave('mapping', fn ($query) => $query
                ->whereNotNull('transfer_match_id')
                ->orWhereNotNull('matched_reference')
                ->orWhereNotNull('move_id')
                ->orWhereIn('review_status', self::SETTLED_REVIEW_STATUSES)
                ->orWhereIn('posting_status', self::SETTLED_POSTING_STATUSES)
            )
            ->orderBy('transaction_date')
            ->get();

        $credits = BankStatementLine::query()
            ->with(['statement', 'mapping'])
            ->where('company_id', $companyId)
            ->where('credit', '>', 0)
            ->whereDoesntHave('mapping', fn ($query) => $query
                ->whereNotNull('transfer_match_id')
                ->orWhereNotNull('matched_reference')
                ->orWhereNotNull('move_id')
                ->orWhereIn('review_status', self::SETTLED_REVIEW_STATUSES)
                ->orWhereIn('posting_status', self::SETTLED_POSTING_STATUSES)
            )
            ->orderBy('transaction_date')
            ->get();

        $matches = [];
        $usedCredits = [];

        foreach ($debits as $debit) {
            $candidate = $credits->first(function (BankStatementLine $credit) use ($debit, $maximumDateDistance, $usedCredits): bool {
                if (isset($usedCredits[$credit->id])) {
                    return false;
                }

                return $credit->statement_id !== $debit->statement_id
                    && $credit->statement?->bank_account_number !== $debit->statement?->bank_account_number
                    && (int) $credit->original_currency_id === (int) $debit->original_currency_id
                    && $credit->company_credit !== null
                    && $debit->company_debit !== null
                    && BigDecimal::of((string) ($credit->original_credit ?? $credit->credit))
                        ->minus((string) ($debit->original_debit ?? $debit->debit))->abs()->isLessThanOrEqualTo('0.01')
                    && BigDecimal::of((string) $credit->company_credit)
                        ->minus((string) $debit->company_debit)->abs()->isLessThanOrEqualTo('0.01')
                    && Carbon::parse($credit->transaction_date)->diffInDays(Carbon::parse($debit->transaction_date)) <= $maximumDateDistance
                    && $this->referencesPermitMatch($debit, $credit)
                    && $this->looksLikeTransfer((string) $debit->description, (string) $credit->description);
            });

            if (! $candidate) {
                continue;
            }

            $matches[] = DB::transaction(function () use ($companyId, $debit, $candidate): BankTransferMatch {
                $reference = 'TRF-'.Carbon::parse($debit->transaction_date)->format('md');
                if (BankTransferMatch::query()->where('match_reference', $reference)->exists()) {
                    $reference .= '-'.$debit->id;
                }

                $match = BankTransferMatch::query()->create([
                    'company_id'                  => $companyId,
                    'outgoing_statement_line_id'  => $debit->id,
                    'incoming_statement_line_id'  => $candidate->id,
                    'match_reference'             => $reference,
                    'amount'                      => $debit->debit,
                    'outgoing_currency_id'        => $debit->original_currency_id,
                    'incoming_currency_id'        => $candidate->original_currency_id,
                    'outgoing_amount'             => $debit->original_debit ?? $debit->debit,
                    'incoming_amount'             => $candidate->original_credit ?? $candidate->credit,
                    'company_amount'              => $debit->company_debit,
                    'confidence'                  => 1,
                    'status'                      => 'suggested',
                ]);

                $debit->mapping?->update([
                    'offset_account_id'  => $candidate->mapping?->bank_gl_account_id,
                    'transfer_match_id'  => $match->id,
                    'transaction_type'   => 'Internal transfer',
                    'review_status'      => BankReviewStatus::MatchedTransfer,
                    'posting_status'     => BankPostingStatus::NotPosted,
                    'cash_flow_category' => CashFlowCategory::Transfer->value,
                    'confidence'         => 1,
                ]);

                $candidate->mapping?->update([
                    'offset_account_id'  => $debit->mapping?->bank_gl_account_id,
                    'transfer_match_id'  => $match->id,
                    'transaction_type'   => 'Internal transfer',
                    'review_status'      => BankReviewStatus::MatchedTransfer,
                    'posting_status'     => BankPostingStatus::MatchedDoNotPost,
                    'cash_flow_category' => CashFlowCategory::Transfer->value,
                    'confidence'         => 1,
                ]);

                return $match;
            });

            $usedCredits[$candidate->id] = true;
        }

        return $matches;
    }

    public function approve(BankTransferMatch $match, User $reviewer): BankTransferMatch
    {
        $match->loadMissing(['outgoingLine.mapping', 'incomingLine.mapping']);

        if ((int) $match->outgoing_currency_id !== (int) $match->incoming_currency_id) {
            throw new \RuntimeException('Cross-currency transfers require explicit conversion, FX difference and bank-charge handling.');
        }
        if ($match->company_amount === null) {
            throw new \RuntimeException('A transfer cannot be approved until its company-currency conversion is complete.');
        }

        DB::transaction(function () use ($match, $reviewer): void {
            $reviewedAt = now();
            $match->update([
                'status'      => 'approved',
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => $reviewedAt,
            ]);

            $outgoingMapping = $match->outgoingLine?->mapping;
            $incomingMapping = $match->incomingLine?->mapping;

            if ($outgoingMapping
                && $incomingMapping
                && (int) $outgoingMapping->bank_gl_account_id === (int) $incomingMapping->bank_gl_account_id) {
                foreach ([$outgoingMapping, $incomingMapping] as $mapping) {
                    $mapping->update([
                        'review_status'  => BankReviewStatus::MatchedTransfer,
                        'posting_status' => BankPostingStatus::MatchedDoNotPost,
                        'reviewer_id'    => $reviewer->id,
                        'reviewed_at'    => $reviewedAt,
                        'posted_at'      => $reviewedAt,
                    ]);
                }
            }
        });

        return $match->fresh();
    }

    protected function looksLikeTransfer(string $outgoing, string $incoming): bool
    {
        $combined = mb_strtolower($outgoing.' '.$incoming);
        if (str_contains($combined, 'transfer') || str_contains($combined, 'incoming ibft')) {
            return true;
        }

        similar_text(mb_strtolower($outgoing), mb_strtolower($incoming), $similarity);

        return $similarity >= 30;
    }

    protected function referencesPermitMatch(BankStatementLine $outgoing, BankStatementLine $incoming): bool
    {
        $outgoingReference = mb_strtoupper(trim((string) $outgoing->reference));
        $incomingReference = mb_strtoupper(trim((string) $incoming->reference));
        $hasExplicitTransferReference = str_starts_with($outgoingReference, 'TRF-')
            || str_starts_with($incomingReference, 'TRF-');

        if ($hasExplicitTransferReference) {
            return $outgoingReference !== '' && $outgoingReference === $incomingReference;
        }

        return true;
    }
}
