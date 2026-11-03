<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\BankStatement;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankMappingRule;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class BankMappingService
{
    public function __construct(protected BankDescriptionNormalizer $normalizer) {}

    public function requiresConfiguredApproval(BankTransactionMapping $mapping): bool
    {
        return app(ApprovalEngine::class)->matchingWorkflow(
            (int) $mapping->company_id,
            'bank_transaction_mapping',
            $this->approvalAmount($mapping),
            ['amount' => (float) ($mapping->statementLine?->company_signed_amount ?? 0)],
        ) !== null;
    }

    public function submit(BankTransactionMapping $mapping, User $requester): ApprovalRequest
    {
        return app(ApprovalEngine::class)->submit(
            $mapping,
            $requester,
            'bank_transaction_mapping',
            $this->approvalAmount($mapping),
            ['amount' => (float) ($mapping->statementLine?->company_signed_amount ?? 0)],
        );
    }

    /**
     * The amount ApprovalEngine compares against a workflow's
     * minimum_amount/maximum_amount: the absolute company-currency amount of
     * the bank line, as a decimal string. The engine never reads the context
     * 'amount' for those thresholds, so passing null there (as before) made
     * every amount-gated bank-mapping workflow silently never apply. The
     * signed context amount is kept unchanged for condition rules already
     * written against it. Null when the company-currency conversion is still
     * missing (no rate yet).
     */
    protected function approvalAmount(BankTransactionMapping $mapping): ?string
    {
        $signed = $mapping->statementLine?->company_signed_amount;

        return $signed === null ? null : BigDecimal::of((string) $signed)->abs()->__toString();
    }

    public function suggestForStatement(BankStatement $statement, float $reviewThreshold = 0.85): int
    {
        $rules = BankMappingRule::query()
            ->where('company_id', $statement->company_id)
            ->where(fn ($query) => $query->whereNull('currency_id')->orWhere('currency_id', $statement->currency_id))
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderByDesc('confidence')
            ->get();

        $updated = 0;
        foreach ($statement->lines()->with('mapping')->get() as $line) {
            $mapping = $line->mapping;
            if (! $mapping || ! in_array($mapping->review_status, [BankReviewStatus::Unmapped, BankReviewStatus::Suggested, BankReviewStatus::NeedsReview], true)) {
                continue;
            }
            if ($mapping->matched_reference !== null || $mapping->transfer_match_id !== null || $mapping->fs_tag_id !== null) {
                continue;
            }

            $rule = $rules->first(fn (BankMappingRule $candidate) => $this->matches($candidate, $statement, $line));
            if (! $rule) {
                continue;
            }

            $confidence = (float) $rule->confidence;
            $mapping->update([
                'offset_account_id'      => $rule->offset_account_id,
                'mapping_rule_id'        => $rule->id,
                'transaction_type'       => $rule->transaction_type,
                'tax_treatment'          => $rule->tax_treatment,
                'cash_flow_category'     => $rule->cash_flow_category,
                'confidence'             => $confidence,
                'suggestion_explanation' => "Matched company, statement currency and rule {$rule->name}.",
                'review_status'          => $confidence >= $reviewThreshold
                    ? BankReviewStatus::Suggested
                    : BankReviewStatus::NeedsReview,
            ]);
            $updated++;
        }

        return $updated;
    }

    public function approve(BankTransactionMapping $mapping, User $reviewer, bool $learn = true): BankTransactionMapping
    {
        if ($mapping->posting_status === BankPostingStatus::Posted || $mapping->review_status === BankReviewStatus::Posted) {
            throw new \RuntimeException('Cannot approve an already posted transaction mapping.');
        }

        if ($mapping->review_status === BankReviewStatus::Approved) {
            return $mapping;
        }

        if ($this->requiresConfiguredApproval($mapping) && ! ApprovalRequest::query()
            ->where('company_id', $mapping->company_id)
            ->where('request_type', 'bank_transaction_mapping')
            ->where('subject_type', $mapping->getMorphClass())
            ->where('subject_id', $mapping->id)
            ->where('status', 'approved')
            ->exists()) {
            throw new \RuntimeException('This transaction mapping requires a completed approval workflow before approval.');
        }

        $mapping->loadMissing(['statementLine.statement', 'bankGlAccount.companies', 'offsetAccount.companies', 'fsTag.account.companies']);
        $companyId = $mapping->company_id;

        if ($mapping->fsTag) {
            if ((int) $mapping->fsTag->company_id !== (int) $companyId || ! $mapping->fsTag->is_active || ! $mapping->fsTag->account) {
                throw new \RuntimeException('The selected FS Tag must be active, company-owned and linked to a GL account.');
            }

            if ($mapping->offset_account_id === null) {
                $mapping->offset_account_id = $mapping->fsTag->account_id;
                $mapping->setRelation('offsetAccount', $mapping->fsTag->account);
                $mapping->match_type ??= 'fs_tag';
            } elseif ((int) $mapping->offset_account_id !== (int) $mapping->fsTag->account_id) {
                // The FS Tag would otherwise be stamped on a GL account it is
                // not configured for, splitting FS reporting from the ledger.
                throw new \RuntimeException(sprintf(
                    'The offset GL %s does not match FS Tag %s, which is configured for GL %s. Clear the offset GL or choose the FS Tag\'s account before approving.',
                    $mapping->offsetAccount?->code ?? "#{$mapping->offset_account_id}",
                    $mapping->fsTag->code,
                    $mapping->fsTag->account->code ?? "#{$mapping->fsTag->account_id}",
                ));
            }
            $mapping->cash_flow_category ??= $mapping->fsTag->cash_flow_category;
            $mapping->tax_treatment ??= $mapping->fsTag->tax_treatment;
        }

        if (! $mapping->bankGlAccount || ! $mapping->offsetAccount) {
            throw new \RuntimeException('Bank GL and either an FS Tag-linked GL or an offset GL are required before approval.');
        }

        foreach ([$mapping->bankGlAccount, $mapping->offsetAccount] as $account) {
            if ($account->is_group || $account->deprecated || ! $account->companies->contains('id', $companyId)) {
                throw new \RuntimeException('Mapping accounts must be active, postable and owned by the selected company.');
            }
        }

        $mapping->update([
            'offset_account_id' => $mapping->offset_account_id,
            'cash_flow_category'=> $mapping->cash_flow_category,
            'tax_treatment'     => $mapping->tax_treatment,
            'match_type'        => $mapping->match_type,
            'review_status'     => BankReviewStatus::Approved,
            'reviewer_id'       => $reviewer->id,
            'reviewed_at'       => now(),
        ]);

        if ($learn) {
            $this->learnFromApproval($mapping);
        }

        return $mapping->fresh();
    }

    protected function learnFromApproval(BankTransactionMapping $mapping): void
    {
        if ($mapping->mapping_rule_id) {
            BankMappingRule::query()->whereKey($mapping->mapping_rule_id)->update([
                'usage_count' => DB::raw('usage_count + 1'),
            ]);

            return;
        }

        $line = $mapping->statementLine;
        $direction = BigDecimal::of((string) $line->original_credit)->isPositive() ? 'credit' : 'debit';
        $amount = BigDecimal::max((string) $line->original_debit, (string) $line->original_credit);
        $description = trim((string) $line->description);
        $normalizedDescription = $this->normalizer->normalize($description);

        $rule = BankMappingRule::query()->firstOrCreate([
            'company_id'             => $mapping->company_id,
            'currency_id'            => $line->original_currency_id,
            'bank_gl_account_id'     => $mapping->bank_gl_account_id,
            'offset_account_id'      => $mapping->offset_account_id,
            'bank_account_number'    => $line->statement?->bank_account_number,
            'description_pattern'    => $description,
            'normalized_description' => $normalizedDescription,
            'direction'              => $direction,
        ], [
            'name'               => 'Learned from '.$mapping->map_reference,
            'minimum_amount'     => $amount->multipliedBy('0.9')->toScale(4, RoundingMode::HalfUp)->__toString(),
            'maximum_amount'     => $amount->multipliedBy('1.1')->toScale(4, RoundingMode::HalfUp)->__toString(),
            'transaction_type'   => $mapping->transaction_type,
            'tax_treatment'      => $mapping->tax_treatment,
            'cash_flow_category' => $mapping->cash_flow_category,
            'confidence'         => 0.9,
            'explanation'        => "Learned from approved mapping {$mapping->map_reference} using normalized description and original-currency amount range.",
            'priority'           => 100,
            'usage_count'        => 1,
            'is_active'          => true,
        ]);

        $mapping->update(['mapping_rule_id' => $rule->id]);
    }

    protected function matches(BankMappingRule $rule, BankStatement $statement, $line): bool
    {
        $amount = BigDecimal::max(
            (string) ($line->original_debit ?? $line->debit),
            (string) ($line->original_credit ?? $line->credit),
        );
        $direction = BigDecimal::of((string) ($line->original_credit ?? $line->credit))->isPositive() ? 'credit' : 'debit';
        $normalizedDescription = $this->normalizer->normalize((string) $line->description);

        return ($rule->bank_account_number === null || mb_strtoupper($rule->bank_account_number) === mb_strtoupper($statement->bank_account_number))
            && ($rule->currency_id === null || (int) $rule->currency_id === (int) $statement->currency_id)
            && ($rule->bank_gl_account_id === null || $rule->bank_gl_account_id === $statement->bank_gl_account_id)
            && ($rule->direction === null || $rule->direction === $direction)
            && ($rule->minimum_amount === null || $amount->isGreaterThanOrEqualTo((string) $rule->minimum_amount))
            && ($rule->maximum_amount === null || $amount->isLessThanOrEqualTo((string) $rule->maximum_amount))
            && ($rule->normalized_description === null || $rule->normalized_description === $normalizedDescription)
            && $this->textMatches($rule->description_pattern, (string) $line->description)
            && $this->textMatches($rule->reference_pattern, (string) $line->reference)
            && $this->textMatches($rule->counterparty_pattern, (string) $line->description);
    }

    protected function textMatches(?string $pattern, string $value): bool
    {
        if ($pattern === null || trim($pattern) === '') {
            return true;
        }

        $pattern = trim($pattern);
        if (str_starts_with($pattern, '/') && @preg_match($pattern, '') !== false) {
            return preg_match($pattern, $value) === 1;
        }

        return str_contains(mb_strtolower($value), mb_strtolower($pattern));
    }
}
