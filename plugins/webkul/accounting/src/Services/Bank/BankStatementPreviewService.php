<?php

namespace Webkul\Accounting\Services\Bank;

use Brick\Math\BigDecimal;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Currency\ExchangeRateService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

// BankStatementImportService is in the same namespace, so its stateless
// findFsTagColumnIndex() helper (called statically below) needs no `use` --
// deliberately not constructor-injecting the whole service for what is
// otherwise a single shared utility method.

class BankStatementPreviewService
{
    public function __construct(
        protected BankStatementParserRegistry $parsers,
        protected BankStatementValidationService $validator,
        protected ExchangeRateService $exchangeRates,
        protected FsTagService $fsTags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        string $path,
        Company $company,
        Currency $currency,
        ?string $parserKey = null,
        ?string $sheetName = null,
        int $rowLimit = 1000,
    ): array {
        $statement = $this->parsers->resolve($path, $parserKey, $sheetName)->parse($path, $sheetName);
        $rows = [];
        $missing = [];

        $fsTagIndex = BankStatementImportService::findFsTagColumnIndex($statement->rawHeader);
        $fsTagCounts = ['recognized' => 0, 'unrecognized' => 0, 'none' => 0];

        foreach (array_slice($statement->transactions, 0, $rowLimit) as $transaction) {
            $date = $transaction->transactionDate ?: ($transaction->valueDate ?: $statement->statementEndDate);
            $originalSigned = BigDecimal::of($transaction->credit)->minus($transaction->debit)->__toString();

            try {
                $rate = $this->exchangeRates->resolveForBankTransaction($company, $currency, $company->currency, $date);
                $companySigned = $this->exchangeRates->convert($originalSigned, $rate);
                $rateValue = $rate->rate;
                $status = 'complete';
            } catch (MissingExchangeRateException $exception) {
                $companySigned = null;
                $rateValue = null;
                $status = 'missing_rate';
                $missing[$exception->getMessage()] = true;
            }

            $fsTagCode = $fsTagIndex !== false
                ? trim((string) ($transaction->rawRow[$fsTagIndex] ?? ''))
                : '';

            if ($fsTagCode === '') {
                $fsTagStatus = 'none';
                $fsTagIssue = null;
                $fsTagCounts['none']++;
            } elseif ($this->fsTags->resolve($company->id, $fsTagCode)) {
                $fsTagStatus = 'recognized';
                $fsTagIssue = null;
                $fsTagCounts['recognized']++;
            } else {
                $fsTagStatus = 'unrecognized';
                $fsTagIssue = $this->fsTags->diagnose($company->id, $fsTagCode);
                $fsTagCounts['unrecognized']++;
            }

            $rows[] = [
                'date'              => $date,
                'description'       => $transaction->description,
                'original_amount'   => $originalSigned,
                'original_currency' => $currency->code ?: $currency->name,
                'exchange_rate'     => $rateValue,
                'company_amount'    => $companySigned,
                'company_currency'  => $company->currency?->code ?: $company->currency?->name,
                'status'            => $status,
                'fs_tag_code'       => $fsTagCode !== '' ? $fsTagCode : null,
                'fs_tag_status'     => $fsTagStatus,
                'fs_tag_issue'      => $fsTagIssue,
            ];
        }

        return [
            'bank'                => $statement->bank,
            'bank_account_number' => $statement->bankAccountNumber,
            'detected_currency'   => $statement->currency,
            'selected_currency'   => $currency->code ?: $currency->name,
            'period'              => "{$statement->statementStartDate} to {$statement->statementEndDate}",
            'source_totals'       => [
                'opening' => $statement->openingBalance,
                'debit'   => $statement->totalDebits,
                'credit'  => $statement->totalCredits,
                'closing' => $statement->closingBalance,
            ],
            'validation_errors' => $this->validator->validate($statement),
            'missing_rates'     => array_keys($missing),
            'rows'              => $rows,
            'row_count'         => count($statement->transactions),
            'truncated'         => count($statement->transactions) > $rowLimit,
            // The "FS Tag" column itself is entirely optional -- most banks
            // don't have one. Only surface this section when the file
            // actually appears to carry tags, so a company not using the
            // feature isn't shown an empty/irrelevant check.
            'fs_tag_column_found'  => $fsTagIndex !== false,
            'fs_tag_summary'       => $fsTagCounts,
            'company_uses_fs_tags' => FsTag::query()->where('company_id', $company->id)->exists(),
        ];
    }
}
