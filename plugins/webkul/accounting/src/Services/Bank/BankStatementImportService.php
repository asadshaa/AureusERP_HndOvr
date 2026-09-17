<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Services\Currency\CompanyCurrencyService;
use Webkul\Accounting\Services\Currency\ExchangeRateService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankStatementImportService
{
    public function __construct(
        protected BankStatementParserRegistry $parsers,
        protected BankStatementValidationService $validator,
        protected CompanyCurrencyService $companyCurrencies,
        protected ExchangeRateService $exchangeRates,
    ) {}

    public function import(
        string $path,
        Company $company,
        Journal $journal,
        Account $bankGlAccount,
        Currency $currency,
        ?string $parserKey = null,
        ?string $sheetName = null,
        ?string $originalFilename = null,
        ?bool $currencyWasOverridden = null,
    ): BankStatement {
        $this->assertTarget($company, $journal, $bankGlAccount, $currency);

        $fileHash = hash_file('sha256', $path);
        if ($fileHash === false) {
            throw new RuntimeException('Unable to fingerprint the bank statement file.');
        }

        $parser = $this->parsers->resolve($path, $parserKey, $sheetName);
        $normalized = $parser->parse($path, $sheetName);

        if (BankStatement::query()
            ->where('company_id', $company->id)
            ->where('bank_account_number', $normalized->bankAccountNumber)
            ->where('currency_id', $currency->id)
            ->whereDate('statement_start_date', $normalized->statementStartDate)
            ->whereDate('statement_end_date', $normalized->statementEndDate)
            ->where('file_hash', $fileHash)
            ->where('parser', $normalized->parser)
            ->exists()
        ) {
            throw new RuntimeException('This bank statement source, account, currency and period have already been imported for the company.');
        }

        $errors = $this->validator->validate($normalized);
        $selectedCurrencyCode = mb_strtoupper((string) ($currency->code ?: $currency->name));
        $detectedCurrencyCode = mb_strtoupper(trim($normalized->currency));
        $currencyWasOverridden ??= $detectedCurrencyCode !== '' && $detectedCurrencyCode !== $selectedCurrencyCode;

        $overlap = BankStatement::query()
            ->where('company_id', $company->id)
            ->where('bank_account_number', $normalized->bankAccountNumber)
            ->where('currency_id', $currency->id)
            ->whereDate('statement_start_date', '<=', $normalized->statementEndDate)
            ->whereDate('statement_end_date', '>=', $normalized->statementStartDate)
            ->exists();

        if ($overlap) {
            $errors[] = [
                'code'       => 'overlapping_period',
                'message'    => 'Another statement for this bank account overlaps the imported period.',
                'source_row' => null,
            ];
        }

        $conversions = $this->prepareConversions($normalized, $company, $currency);

        return DB::transaction(function () use (
            $normalized,
            $errors,
            $company,
            $journal,
            $bankGlAccount,
            $currency,
            $fileHash,
            $originalFilename,
            $path,
            $currencyWasOverridden,
            $detectedCurrencyCode,
            $conversions,
        ): BankStatement {
            $status = $errors === []
                ? BankImportStatus::Validated
                : BankImportStatus::ReconciliationFailed;

            $statement = BankStatement::query()->create([
                'company_id'              => $company->id,
                'journal_id'              => $journal->id,
                'currency_id'             => $currency->id,
                'company_currency_id'     => $company->currency_id,
                'bank_gl_account_id'      => $bankGlAccount->id,
                'name'                    => "{$normalized->bank} {$normalized->statementStartDate} to {$normalized->statementEndDate}",
                'reference'               => $normalized->bankAccountNumber,
                'bank_name'               => $normalized->bank,
                'bank_account_number'     => $normalized->bankAccountNumber,
                'account_title'           => $normalized->accountTitle,
                'date'                    => $normalized->statementEndDate,
                'statement_start_date'    => $normalized->statementStartDate,
                'statement_end_date'      => $normalized->statementEndDate,
                'opening_balance'         => $normalized->openingBalance,
                'total_debits'            => $normalized->totalDebits,
                'total_credits'           => $normalized->totalCredits,
                'closing_balance'         => $normalized->closingBalance,
                'detected_currency_code'  => $detectedCurrencyCode ?: null,
                'currency_was_overridden' => $currencyWasOverridden,
                'company_opening_balance' => $conversions['company_opening_balance'],
                'company_total_debits'    => $conversions['company_total_debits'],
                'company_total_credits'   => $conversions['company_total_credits'],
                'company_closing_balance' => $conversions['company_closing_balance'],
                'conversion_status'       => $conversions['status'],
                'balance_start'           => $normalized->openingBalance,
                'balance_end'             => $normalized->closingBalance,
                'balance_end_real'        => $normalized->closingBalance,
                'original_filename'       => $originalFilename ?? basename($path),
                'file_hash'               => $fileHash,
                'source_sheet'            => $normalized->sourceSheet,
                'parser'                  => $normalized->parser,
                'import_status'           => $status->value,
                'validation_errors'       => $errors,
                'raw_header'              => $normalized->rawHeader,
                'is_completed'            => false,
            ]);

            $fsTagIndex = static::findFsTagColumnIndex($normalized->rawHeader);

            $seenFingerprints = [];

            foreach ($normalized->transactions as $sort => $transaction) {
                $conversion = $conversions['transactions'][$sort];
                $fingerprint = $transaction->fingerprint($normalized->bankAccountNumber);

                // The validator already flagged a within-file duplicate as a soft,
                // reviewable error (see $errors above) so the statement can still
                // import, tagged ReconciliationFailed. But `bank_statement_lines`
                // has a hard unique constraint on (statement_id,
                // transaction_fingerprint), so inserting the second occurrence of
                // the same fingerprint would throw and roll back everything
                // imported so far. Skip creating a line for it — the duplicate is
                // still visible to the user via the statement's validation_errors.
                if (isset($seenFingerprints[$fingerprint])) {
                    continue;
                }

                $seenFingerprints[$fingerprint] = true;

                $originalSignedAmount = BigDecimal::of($transaction->credit)->minus($transaction->debit)->__toString();
                $line = BankStatementLine::query()->create([
                    'sort'                    => $sort,
                    'journal_id'              => $journal->id,
                    'company_id'              => $company->id,
                    'statement_id'            => $statement->id,
                    'currency_id'             => $currency->id,
                    'account_number'          => $normalized->bankAccountNumber,
                    'transaction_type'        => BigDecimal::of($transaction->credit)->isPositive() ? 'credit' : 'debit',
                    'payment_reference'       => $transaction->reference,
                    'internal_index'          => $fingerprint,
                    'transaction_date'        => $transaction->transactionDate ?: null,
                    'value_date'              => $transaction->valueDate,
                    'description'             => $transaction->description,
                    'reference'               => $transaction->reference,
                    'debit'                   => $transaction->debit,
                    'credit'                  => $transaction->credit,
                    'original_currency_id'    => $currency->id,
                    'company_currency_id'     => $company->currency_id,
                    'original_debit'          => $transaction->debit,
                    'original_credit'         => $transaction->credit,
                    'original_signed_amount'  => $originalSignedAmount,
                    'company_debit'           => $conversion['company_debit'],
                    'company_credit'          => $conversion['company_credit'],
                    'company_signed_amount'   => $conversion['company_signed_amount'],
                    'exchange_rate_id'        => $conversion['exchange_rate_id'],
                    'exchange_rate'           => $conversion['exchange_rate'],
                    'rate_date'               => $conversion['rate_date'],
                    'rate_source'             => $conversion['rate_source'],
                    'rate_type'               => $conversion['rate_type'],
                    'conversion_status'       => $conversion['conversion_status'],
                    'running_balance'         => $transaction->runningBalance,
                    'source_row'              => $transaction->sourceRow,
                    'raw_row'                 => $transaction->rawRow,
                    'transaction_fingerprint' => $fingerprint,
                    'import_status'           => $status->value,
                    'transaction_details'     => $transaction->rawRow,
                    'amount'                  => $conversion['company_signed_amount'] ?? '0',
                    'amount_currency'         => $originalSignedAmount,
                    'amount_residual'         => $conversion['company_signed_amount'] ?? '0',
                    'is_reconciled'           => false,
                ]);

                $fsTagCode = $fsTagIndex !== false
                    ? trim((string) ($transaction->rawRow[$fsTagIndex] ?? ''))
                    : '';

                $fsTagService = app(FsTagService::class);
                $fsTag = $fsTagCode !== ''
                    ? $fsTagService->resolve($company->id, $fsTagCode)
                    : null;

                BankTransactionMapping::query()->create([
                    'company_id'           => $company->id,
                    'statement_line_id'    => $line->id,
                    'bank_gl_account_id'   => $bankGlAccount->id,
                    'original_currency_id' => $currency->id,
                    'company_currency_id'  => $company->currency_id,
                    'exchange_rate_id'     => $conversion['exchange_rate_id'],
                    'exchange_rate'        => $conversion['exchange_rate'],
                    'rate_date'            => $conversion['rate_date'],
                    'rate_source'          => $conversion['rate_source'],
                    'rate_type'            => $conversion['rate_type'],
                    'conversion_status'    => $conversion['conversion_status'],

                    'fs_tag_id'            => $fsTag?->id,
                    'match_type'           => $fsTag ? 'fs_tag' : null,

                    // The raw text the user actually typed is kept even when it
                    // doesn't resolve, and paired with a plain-language reason, so
                    // the review grid can show *why* a tag wasn't applied instead
                    // of an unhelpful blank cell that reads the same as "nothing
                    // was entered".
                    'fs_tag_raw_code'      => $fsTagCode !== '' ? $fsTagCode : null,
                    'fs_tag_issue'         => $fsTag ? null : $fsTagService->diagnose($company->id, $fsTagCode),

                    'review_status'        => $fsTagCode !== '' && ! $fsTag
                        ? BankReviewStatus::NeedsReview
                        : BankReviewStatus::Unmapped,

                    'posting_status'       => BankPostingStatus::NotPosted,
                ]);
            }

            return $statement->fresh(['lines.mapping']);
        });
    }

    /**
     * Locate the "FS Tag" column in a parsed statement's raw header rows,
     * tolerating case, surrounding whitespace, and separator differences
     * (e.g. "fs tag", "FS_Tag", "FS-TAG", "FSTag" all match). Returns the
     * column index, or false if no header row contains anything recognizable
     * as an FS Tag column.
     */
    public static function findFsTagColumnIndex(array $rawHeader): int|false
    {
        foreach ($rawHeader as $headerRow) {
            if (! is_array($headerRow)) {
                continue;
            }

            foreach ($headerRow as $index => $cell) {
                $normalized = preg_replace('/[\s_-]+/', '', mb_strtoupper(trim((string) $cell)));

                if ($normalized === 'FSTAG') {
                    return $index;
                }
            }
        }

        return false;
    }

    protected function assertTarget(Company $company, Journal $journal, Account $bankGlAccount, Currency $currency): void
    {
        if (! $this->companyCurrencies->isTransactionCurrencyEnabled($company, (int) $currency->id)) {
            throw new RuntimeException('The selected currency is not enabled for this company.');
        }
        if ($journal->company_id !== $company->id) {
            throw new RuntimeException('The selected journal belongs to another company.');
        }

        if ($journal->type !== JournalType::BANK) {
            throw new RuntimeException('Bank statements can only be imported into a bank journal.');
        }

        if ($journal->currency_id && (int) $journal->currency_id !== (int) $currency->id) {
            throw new RuntimeException('The bank journal currency does not match the selected statement currency.');
        }

        if ($bankGlAccount->deprecated || $bankGlAccount->is_group) {
            throw new RuntimeException('The bank GL must be an active postable account.');
        }

        if (! $bankGlAccount->companies()->where('companies.id', $company->id)->exists()) {
            throw new RuntimeException('The bank GL account belongs to another company.');
        }

        if ($bankGlAccount->currency_id === null && (int) $currency->id !== (int) $company->currency_id) {
            throw new RuntimeException('A foreign-currency bank GL must have its currency set explicitly.');
        }

        if ($bankGlAccount->currency_id && (int) $bankGlAccount->currency_id !== (int) $currency->id) {
            throw new RuntimeException('The bank GL currency does not match the selected statement currency.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareConversions($statement, Company $company, Currency $currency): array
    {
        $transactions = [];
        $companyDebits = BigDecimal::zero();
        $companyCredits = BigDecimal::zero();
        $complete = true;

        foreach ($statement->transactions as $transaction) {
            $date = $transaction->transactionDate ?: ($transaction->valueDate ?: $statement->statementEndDate);

            try {
                $rate = $this->exchangeRates->resolveForBankTransaction($company, $currency, $company->currency, $date);
                $companyDebit = $this->exchangeRates->convert($transaction->debit, $rate);
                $companyCredit = $this->exchangeRates->convert($transaction->credit, $rate);
                $companySigned = BigDecimal::of($companyCredit)->minus($companyDebit)->__toString();
                $companyDebits = $companyDebits->plus($companyDebit);
                $companyCredits = $companyCredits->plus($companyCredit);
                $transactions[] = [
                    'company_debit'         => $companyDebit,
                    'company_credit'        => $companyCredit,
                    'company_signed_amount' => $companySigned,
                    'exchange_rate_id'      => $rate->recordId,
                    'exchange_rate'         => $rate->rate,
                    'rate_date'             => $rate->effectiveDate,
                    'rate_source'           => $rate->source,
                    'rate_type'             => $rate->type,
                    'conversion_status'     => ConversionStatus::Complete->value,
                ];
            } catch (MissingExchangeRateException) {
                $complete = false;
                $transactions[] = [
                    'company_debit'         => null,
                    'company_credit'        => null,
                    'company_signed_amount' => null,
                    'exchange_rate_id'      => null,
                    'exchange_rate'         => null,
                    'rate_date'             => $date,
                    'rate_source'           => null,
                    'rate_type'             => 'transaction',
                    'conversion_status'     => ConversionStatus::MissingRate->value,
                ];
            }
        }

        $opening = $this->convertBoundary($statement->openingBalance, $statement->statementStartDate, $company, $currency);
        $closing = $this->convertBoundary($statement->closingBalance, $statement->statementEndDate, $company, $currency);
        $complete = $complete && $opening !== null && $closing !== null;

        return [
            'transactions'            => $transactions,
            'company_opening_balance' => $opening,
            'company_total_debits'    => $complete ? $companyDebits->__toString() : null,
            'company_total_credits'   => $complete ? $companyCredits->__toString() : null,
            'company_closing_balance' => $closing,
            'status'                  => $complete ? ConversionStatus::Complete->value : ConversionStatus::MissingRate->value,
        ];
    }

    private function convertBoundary(string $amount, string $date, Company $company, Currency $currency): ?string
    {
        try {
            $rate = $this->exchangeRates->resolveForBankTransaction($company, $currency, $company->currency, $date);

            return $this->exchangeRates->convert($amount, $rate);
        } catch (MissingExchangeRateException) {
            return null;
        }
    }
}
