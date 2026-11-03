<?php

namespace Webkul\Accounting\Services\Import;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Account\Models\Tax;
use Webkul\Account\Services\PeriodLockService;
use Webkul\Accounting\Data\Bank\NormalizedBankStatement;
use Webkul\Accounting\Data\Bank\NormalizedBankTransaction;
use Webkul\Accounting\Enums\ImportFailurePolicy;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Models\ImportRun;
use Webkul\Accounting\Models\ImportSourceRow;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\Bank\BankStatementParserRegistry;
use Webkul\Accounting\Services\Currency\ExchangeRateService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Accounting\Services\PartyClassificationService;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

final class ImportExecutionService
{
    public function confirm(ImportRun $run, ?int $userId = null, bool $discardDuplicates = false): ImportRun
    {
        return DB::transaction(function () use ($run, $userId, $discardDuplicates): ImportRun {
            $lockedRun = ImportRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedRun->loadMissing('profile');

            if ($lockedRun->status !== 'previewed') {
                throw new RuntimeException('Only a previewed import run can be confirmed.');
            }
            $failurePolicy = ImportFailurePolicy::fromValue($lockedRun->profile->failure_policy);
            if ($lockedRun->failed_rows > 0 && $failurePolicy === ImportFailurePolicy::RejectFile) {
                throw new RuntimeException('Correct all preview errors before confirming the import. This profile rejects the entire file when any row fails validation.');
            }
            if ($lockedRun->duplicate_rows > 0 && ! $discardDuplicates) {
                throw new RuntimeException('Confirm that detected duplicates may be discarded before importing the remaining rows.');
            }
            if ($lockedRun->profile->company_id !== $lockedRun->company_id) {
                throw new RuntimeException('The profile and import run company do not match.');
            }

            $lockedRun->update([
                'status'                  => 'processing',
                'imported_by_id'          => $userId ?? $lockedRun->imported_by_id,
                'confirmed_at'            => now(),
                'duplicates_confirmed_at' => $lockedRun->duplicate_rows > 0 ? now() : null,
            ]);

            $imported = 0;
            if ($lockedRun->profile->entity_type === 'opening_balance') {
                $imported = $this->createOpeningBalanceJournal($lockedRun);
            } elseif ($lockedRun->profile->entity_type === 'journal_entry') {
                $imported = $this->createJournalEntries($lockedRun);
            } elseif ($lockedRun->profile->entity_type === 'bank_statement') {
                $statement = $this->createBankStatement($lockedRun);
                $linesBySourceRow = $statement->lines->keyBy('source_row');
                foreach ($lockedRun->sourceRows()->whereIn('status', ['pass', 'warning'])->get() as $sourceRow) {
                    $line = $linesBySourceRow->get($sourceRow->source_row_number);
                    if (! $line) {
                        throw new RuntimeException("Imported bank line for source row {$sourceRow->source_row_number} was not created.");
                    }
                    $sourceRow->update([
                        'canonical_type' => $line->getMorphClass(),
                        'canonical_id'   => $line->id,
                        'processed_at'   => now(),
                    ]);
                    $values = (array) $sourceRow->transformed_values;
                    $tagCode = trim((string) ($values['fs_tag'] ?? ''));

                    $tag = $tagCode !== ''
                        ? app(FsTagService::class)->resolve($lockedRun->company_id, $tagCode)
                        : null;

                    if ($line->mapping) {
                        $offsetCode = trim((string) ($values['offset_gl_code'] ?? ''));
                        $offsetAccount = $offsetCode === ''
                            ? null
                            : Account::query()
                                ->postable()
                                ->where('deprecated', false)
                                ->whereRaw('UPPER(code) = ?', [mb_strtoupper($offsetCode)])
                                ->whereHas('companies', fn ($query) => $query->where('companies.id', $lockedRun->company_id))
                                ->firstOrFail();

                        $line->mapping->update([
                            'offset_account_id'  => $offsetAccount?->id,
                            'fs_tag_id'          => $tag?->id,
                            'transaction_type'   => filled($values['transaction_type'] ?? null) ? trim((string) $values['transaction_type']) : null,
                            'counterparty'       => filled($values['counterparty'] ?? null) ? trim((string) $values['counterparty']) : null,
                            'supporting_document'=> filled($values['supporting_document'] ?? null) ? trim((string) $values['supporting_document']) : null,
                            'cash_flow_category' => filled($values['cash_flow_category'] ?? null)
                                ? trim((string) $values['cash_flow_category'])
                                : $tag?->cash_flow_category,
                            'tax_treatment'      => filled($values['tax_treatment'] ?? null)
                                ? trim((string) $values['tax_treatment'])
                                : $tag?->tax_treatment,
                            'match_type'             => $offsetAccount ? 'source_gl' : ($tag ? 'fs_tag' : null),
                            'confidence'             => $offsetAccount || $tag ? 1 : 0,
                            'suggestion_explanation' => $offsetAccount || $tag
                                ? 'Mapped from explicitly configured source columns; user approval is still required before posting.'
                                : null,
                            'review_status' => $tagCode !== '' && ! $tag
                                ? 'needs_review'
                                : ($offsetAccount || $tag ? 'suggested' : $line->mapping->review_status),
                        ]);
                    }
                    $imported++;
                }
            } else {
                ImportSourceRow::query()
                    ->where('run_id', $lockedRun->id)
                    ->whereIn('status', ['pass', 'warning'])
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) use ($lockedRun, &$imported): void {
                        foreach ($rows as $row) {
                            $record = $this->createCanonicalRecord($lockedRun, (array) $row->transformed_values);
                            $row->update([
                                'canonical_type' => $record->getMorphClass(),
                                'canonical_id'   => $record->getKey(),
                                'processed_at'   => now(),
                            ]);
                            $imported++;
                        }
                    });
            }

            $status = match (true) {
                $lockedRun->failed_rows > 0 && $failurePolicy === ImportFailurePolicy::NeedsReview => 'completed_with_review',
                $lockedRun->failed_rows > 0                                                        => 'completed_with_rejections',
                default                                                                            => 'completed',
            };

            $lockedRun->update([
                'status'        => $status,
                'imported_rows' => $imported,
                'completed_at'  => now(),
            ]);

            return $lockedRun->fresh(['sourceRows']);
        });
    }

    /** @param array<string, mixed> $values */
    private function createCanonicalRecord(ImportRun $run, array $values): Model
    {
        return match ($run->profile->entity_type) {
            'vendor', 'customer'                        => $this->createParty($run, $values),
            'employee'                                  => $this->createEmployee($run, $values),
            'fs_tag'                                    => $this->createFsTag($run, $values),
            'invoice', 'bill', 'claim', 'miscellaneous' => $this->createDocument($run, $values),
            'bank_statement'                            => throw new RuntimeException('Bank statement rows are imported as one controlled statement batch.'),
            default                                     => throw new RuntimeException("Unsupported import entity [{$run->profile->entity_type}]."),
        };
    }

    private function createOpeningBalanceJournal(ImportRun $run): int
    {
        $rows = $run->sourceRows()->whereIn('status', ['pass', 'warning'])->orderBy('source_row_number')->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        $company = Company::query()->findOrFail($run->company_id);
        $currency = Currency::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper($this->singleValue($rows, 'currency', 'currency'))])->firstOrFail();
        if ((int) $currency->id !== (int) $company->currency_id) {
            throw new RuntimeException('Opening-balance imports currently require the company reporting currency.');
        }
        $journal = $this->generalJournal($run, $this->singleValue($rows, 'journal_code', 'journal code'), $currency);
        $date = $this->singleValue($rows, 'date', 'opening date');
        $lines = $this->importedLedgerLines($run, $rows, $company, $currency, $date);

        if ($lines === []) {
            return 0;
        }

        $this->assertBalanced($lines, 'Opening balance');
        // This import writes a posted journal directly, so it is a posting
        // chokepoint like confirmMove(): a closed period must not receive it.
        app(PeriodLockService::class)->assertNotLocked((int) $company->id, $date);
        $now = now();
        $moveId = DB::table('accounts_account_moves')->insertGetId([
            'journal_id'             => $journal->id,
            'company_id'             => $company->id,
            'currency_id'            => $currency->id,
            'original_currency_id'   => $currency->id,
            'company_currency_id'    => $company->currency_id,
            'date'                   => $date,
            'name'                   => "Opening balances — {$run->reference}",
            'reference'              => $run->reference,
            'move_type'              => MoveType::ENTRY->value,
            'state'                  => MoveState::POSTED->value,
            'coa_migration_kind'     => 'opening',
            'accounting_source_type' => ImportRun::class,
            'accounting_source_id'   => $run->id,
            'review_status'          => 'posted',
            // The user who confirmed the import is the one who posted it.
            'posted_by_id'           => $run->imported_by_id,
            'posted_at'              => $now,
            'posted_before'          => true,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        $this->insertImportedLedgerLines($moveId, $journal, $company, $currency, $date, $lines, MoveState::POSTED);
        $this->linkRowsToMove($rows, $moveId);

        return $rows->count();
    }

    private function createJournalEntries(ImportRun $run): int
    {
        $rows = $run->sourceRows()->whereIn('status', ['pass', 'warning'])->orderBy('source_row_number')->get();
        $imported = 0;

        foreach ($rows->groupBy(fn (ImportSourceRow $row): string => trim((string) $row->transformed_values['journal_entry_id'])) as $entryId => $entryRows) {
            $company = Company::query()->findOrFail($run->company_id);
            $currency = Currency::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper($this->singleValue($entryRows, 'currency', "currency for {$entryId}"))])->firstOrFail();
            $journal = $this->generalJournal($run, $this->singleValue($entryRows, 'journal_code', "journal code for {$entryId}"), $currency);
            $date = $this->singleValue($entryRows, 'date', "date for {$entryId}");
            $lines = $this->importedLedgerLines($run, $entryRows, $company, $currency, $date);
            $this->assertBalanced($lines, "Journal entry {$entryId}");

            $descriptions = $entryRows->map(fn (ImportSourceRow $row): string => trim((string) ($row->transformed_values['description'] ?? '')))->filter()->unique();
            $cashFlowCategories = $entryRows->map(fn (ImportSourceRow $row): string => trim((string) ($row->transformed_values['cash_flow_category'] ?? '')))->filter()->unique();
            $taxTreatments = $entryRows->map(fn (ImportSourceRow $row): string => trim((string) ($row->transformed_values['tax_treatment'] ?? '')))->filter()->unique();
            $now = now();
            $moveId = DB::table('accounts_account_moves')->insertGetId([
                'journal_id'             => $journal->id,
                'company_id'             => $company->id,
                'currency_id'            => $currency->id,
                'original_currency_id'   => $currency->id,
                'company_currency_id'    => $company->currency_id,
                'date'                   => $date,
                'name'                   => "Draft {$entryId}",
                'reference'              => $entryId,
                'narration'              => $descriptions->implode(' | '),
                'move_type'              => MoveType::ENTRY->value,
                'state'                  => MoveState::DRAFT->value,
                'accounting_source_type' => 'configured_journal_group',
                'accounting_source_id'   => $entryRows->first()->id,
                'cash_flow_category'     => $cashFlowCategories->count() === 1 ? $cashFlowCategories->first() : null,
                'tax_treatment'          => $taxTreatments->count() === 1 ? $taxTreatments->first() : null,
                'review_status'          => 'needs_review',
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
            $this->insertImportedLedgerLines($moveId, $journal, $company, $currency, $date, $lines, MoveState::DRAFT);
            $this->linkRowsToMove($entryRows, $moveId);
            $imported += $entryRows->count();
        }

        return $imported;
    }

    private function generalJournal(ImportRun $run, string $code, Currency $currency): Journal
    {
        $journal = Journal::query()
            ->where('company_id', $run->company_id)
            ->where('type', 'general')
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])
            ->firstOrFail();

        if ($journal->currency_id && (int) $journal->currency_id !== (int) $currency->id) {
            throw new RuntimeException('The imported journal currency does not match the source currency.');
        }

        return $journal;
    }

    private function singleValue($rows, string $field, string $label): string
    {
        $values = $rows->map(fn (ImportSourceRow $row): string => trim((string) ($row->transformed_values[$field] ?? '')))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        if ($values->count() !== 1) {
            throw new RuntimeException("All imported rows must use one {$label}.");
        }

        return $values->first();
    }

    private function importedLedgerLines(ImportRun $run, $rows, Company $company, Currency $currency, string $date): array
    {
        $rate = app(ExchangeRateService::class)->resolve($company, $currency, $company->currency, $date);
        $codes = $rows->map(fn (ImportSourceRow $row): string => mb_strtoupper(trim((string) ($row->transformed_values['gl_code'] ?? ''))))->filter()->unique();
        $accounts = Account::query()
            ->postable()
            ->where('deprecated', false)
            ->where(function ($q) use ($codes) {
                $q->whereIn(DB::raw('UPPER(code)'), $codes);
            })
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $run->company_id))
            ->get()
            ->keyBy(fn (Account $account): string => mb_strtoupper((string) $account->code));
        $fsTags = FsTag::query()
            ->where('company_id', $run->company_id)
            ->where('is_active', true)
            ->whereIn('code', $rows->map(fn (ImportSourceRow $row): string => mb_strtoupper(trim((string) ($row->transformed_values['fs_tag'] ?? ''))))->filter())
            ->get()
            ->keyBy(fn (FsTag $tag): string => mb_strtoupper((string) $tag->code));

        return $rows->map(function (ImportSourceRow $row) use ($accounts, $fsTags, $rate): array {
            $values = (array) $row->transformed_values;
            $glCode = mb_strtoupper(trim((string) ($values['gl_code'] ?? '')));
            $account = $accounts->get($glCode) ?? throw new RuntimeException("GL Account [{$values['gl_code']}] is no longer active or available for this company.");
            $tagCode = mb_strtoupper(trim((string) ($values['fs_tag'] ?? '')));
            $fsTag = $tagCode !== ''
                ? ($fsTags->get($tagCode) ?? throw new RuntimeException("FS Tag [{$values['fs_tag']}] is no longer active or available for this company."))
                : null;
            $originalDebit = BigDecimal::of((string) ($values['debit'] ?? '0'))->toScale(4, RoundingMode::HalfUp)->__toString();
            $originalCredit = BigDecimal::of((string) ($values['credit'] ?? '0'))->toScale(4, RoundingMode::HalfUp)->__toString();

            return [
                'source_row_id'  => $row->id,
                'account_id'     => $account->id,
                'fs_tag_id'      => $fsTag?->id,
                'name'           => trim((string) ($values['description'] ?? $values['note'] ?? $values['gl_account'] ?? $values['gl_code'])),
                'reference'      => trim((string) ($values['journal_entry_id'] ?? '')) ?: null,
                'original_debit' => $originalDebit,
                'original_credit'=> $originalCredit,
                'debit'          => app(ExchangeRateService::class)->convert($originalDebit, $rate),
                'credit'         => app(ExchangeRateService::class)->convert($originalCredit, $rate),
                'rate'           => $rate,
            ];
        })->filter(fn (array $line): bool => BigDecimal::of($line['original_debit'])->isPositive()
            || BigDecimal::of($line['original_credit'])->isPositive())
            ->values()
            ->all();
    }

    private function assertBalanced(array $lines, string $label): void
    {
        $debit = collect($lines)->sum(fn (array $line): float => (float) $line['debit']);
        $credit = collect($lines)->sum(fn (array $line): float => (float) $line['credit']);

        if ($debit <= 0 || abs($debit - $credit) > 0.005) {
            throw new RuntimeException("{$label} is unbalanced: debit {$debit} does not equal credit {$credit}.");
        }
    }

    private function insertImportedLedgerLines(
        int $moveId,
        Journal $journal,
        Company $company,
        Currency $currency,
        string $date,
        array $lines,
        MoveState $state,
    ): void {
        $now = now();
        $records = [];
        foreach ($lines as $sort => $line) {
            $rate = $line['rate'];
            $originalSigned = BigDecimal::of($line['original_debit'])->minus($line['original_credit'])->__toString();
            $companySigned = BigDecimal::of($line['debit'])->minus($line['credit'])->__toString();
            $records[] = [
                'move_id'                => $moveId,
                'journal_id'             => $journal->id,
                'account_id'             => $line['account_id'],
                'fs_tag_id'              => $line['fs_tag_id'],
                'company_id'             => $company->id,
                'company_currency_id'    => $company->currency_id,
                'currency_id'            => $currency->id,
                'original_currency_id'   => $currency->id,
                'date'                   => $date,
                'debit'                  => $line['debit'],
                'credit'                 => $line['credit'],
                'balance'                => $companySigned,
                'original_debit'         => $line['original_debit'],
                'original_credit'        => $line['original_credit'],
                'original_signed_amount' => $originalSigned,
                'company_debit'          => $line['debit'],
                'company_credit'         => $line['credit'],
                'company_signed_amount'  => $companySigned,
                'amount_currency'        => $originalSigned,
                'exchange_rate_id'       => $rate->recordId,
                'exchange_rate'          => $rate->rate,
                'rate_date'              => $rate->effectiveDate,
                'rate_source'            => $rate->source,
                'rate_type'              => $rate->type,
                'conversion_status'      => 'complete',
                'parent_state'           => $state->value,
                'is_imported'            => true,
                'name'                   => $line['name'],
                'reference'              => $line['reference'],
                'sort'                   => $sort,
                'created_at'             => $now,
                'updated_at'             => $now,
            ];
        }

        DB::table('accounts_account_move_lines')->insert($records);
    }

    private function linkRowsToMove($rows, int $moveId): void
    {
        foreach ($rows as $row) {
            $row->update([
                'canonical_type' => Move::class,
                'canonical_id'   => $moveId,
                'processed_at'   => now(),
            ]);
        }
    }

    private function createBankStatement(ImportRun $run): BankStatement
    {
        $rows = $run->sourceRows()->whereIn('status', ['pass', 'warning'])->orderBy('source_row_number')->get();
        if ($rows->isEmpty()) {
            throw new RuntimeException('The bank statement import has no passing rows.');
        }

        $values = $rows->map(fn (ImportSourceRow $row): array => (array) $row->transformed_values);
        foreach (['currency', 'bank_account_number', 'journal_code', 'bank_gl_code'] as $field) {
            if ($values->pluck($field)->filter(fn ($value): bool => $value !== null && $value !== '')->map(fn ($value): string => mb_strtoupper(trim((string) $value)))->unique()->count() !== 1) {
                throw new RuntimeException("All bank statement rows must use one {$field}.");
            }
        }

        $first = $values->first();
        $company = Company::query()->findOrFail($run->company_id);
        $currency = Currency::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $first['currency'])])->firstOrFail();
        $journal = Journal::query()->where('company_id', $run->company_id)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $first['journal_code'])])->firstOrFail();
        $bankAccount = Account::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $first['bank_gl_code'])])
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $run->company_id))->firstOrFail();

        $totalDebits = BigDecimal::zero();
        $totalCredits = BigDecimal::zero();
        $dates = [];
        $transactions = [];
        foreach ($rows as $row) {
            $value = (array) $row->transformed_values;
            $debit = BigDecimal::of((string) ($value['debit'] ?? '0'));
            $credit = BigDecimal::of((string) ($value['credit'] ?? '0'));
            $totalDebits = $totalDebits->plus($debit);
            $totalCredits = $totalCredits->plus($credit);
            $dates[] = (string) $value['date'];
            $transactions[] = new NormalizedBankTransaction(
                transactionDate: (string) $value['date'],
                valueDate: filled($value['value_date'] ?? null) ? (string) $value['value_date'] : null,
                description: (string) $value['description'],
                reference: filled($value['reference'] ?? null) ? (string) $value['reference'] : null,
                debit: $debit->__toString(),
                credit: $credit->__toString(),
                runningBalance: filled($value['balance'] ?? null) ? (string) $value['balance'] : null,
                sourceRow: $row->source_row_number,
                rawRow: (array) $row->raw_values,
            );
        }

        sort($dates);
        $opening = BigDecimal::of((string) ($first['opening_balance'] ?? '0'));
        $last = $values->last();
        $closing = filled($last['closing_balance'] ?? null)
            ? BigDecimal::of((string) $last['closing_balance'])
            : (filled($last['balance'] ?? null)
                ? BigDecimal::of((string) $last['balance'])
                : $opening->plus($totalCredits)->minus($totalDebits));
        $parserKey = "profile-run-{$run->id}";
        $normalized = new NormalizedBankStatement(
            bank: (string) ($first['bank_name'] ?? 'Imported bank'),
            bankAccountNumber: (string) $first['bank_account_number'],
            accountTitle: (string) ($first['account_title'] ?? ''),
            currency: (string) $first['currency'],
            statementStartDate: $dates[0],
            statementEndDate: $dates[array_key_last($dates)],
            openingBalance: $opening->__toString(),
            totalDebits: $totalDebits->__toString(),
            totalCredits: $totalCredits->__toString(),
            closingBalance: $closing->__toString(),
            parser: $parserKey,
            sourceSheet: $run->source_sheet,
            rawHeader: (array) ($run->summary['headers'] ?? []),
            transactions: $transactions,
        );
        $path = (string) ($run->summary['staged_path'] ?? '');
        if (! is_file($path)) {
            throw new RuntimeException('The staged source file is no longer available; preview the file again.');
        }

        app(BankStatementParserRegistry::class)->register(new ProfileBankStatementParser($parserKey, $normalized));

        return app(BankStatementImportService::class)->import(
            $path,
            $company,
            $journal,
            $bankAccount,
            $currency,
            $parserKey,
            $run->source_sheet,
            $run->original_filename,
            false,
        );
    }

    /** @param array<string, mixed> $values */
    private function createParty(ImportRun $run, array $values): Partner
    {
        $reference = trim((string) ($values['reference'] ?? ''));
        $identity = $reference !== ''
            ? ['company_id' => $run->company_id, 'reference' => $reference]
            : ['company_id' => $run->company_id, 'name' => trim((string) $values['name']), 'email' => $values['email'] ?? null];

        $party = Partner::query()->firstOrCreate($identity, [
            'account_type'  => 'company',
            'sub_type'      => $run->profile->entity_type,
            'name'          => trim((string) $values['name']),
            'email'         => $values['email'] ?? null,
            'phone'         => $values['phone'] ?? null,
            'mobile'        => $values['mobile'] ?? null,
            'tax_id'        => $values['tax_id'] ?? null,
            'is_active'     => $values['is_active'] ?? true,
            'supplier_rank' => $run->profile->entity_type === 'vendor' ? 1 : 0,
            'customer_rank' => $run->profile->entity_type === 'customer' ? 1 : 0,
        ]);

        if (! empty($values['payment_term'])) {
            $term = PaymentTerm::query()->where('company_id', $run->company_id)->where('name', $values['payment_term'])->first();
            $field = $run->profile->entity_type === 'vendor' ? 'property_supplier_payment_term_id' : 'property_payment_term_id';
            if ($term && ! $party->getAttribute($field)) {
                $party->update([$field => $term->id]);
            }
        }
        $company = Company::query()->findOrFail($run->company_id);
        foreach (['classification', 'sector', 'category'] as $classificationType) {
            app(PartyClassificationService::class)->assign($company, $party, $classificationType, $values[$classificationType] ?? null);
        }

        return $party;
    }

    /** @param array<string, mixed> $values */
    private function createEmployee(ImportRun $run, array $values): Employee
    {
        $employeeId = trim((string) ($values['identification_id'] ?? ''));
        $identity = $employeeId !== ''
            ? ['company_id' => $run->company_id, 'identification_id' => $employeeId]
            : ['company_id' => $run->company_id, 'work_email' => $values['work_email'] ?? null, 'name' => trim((string) $values['name'])];

        $department = empty($values['department']) ? null : Department::query()->firstOrCreate([
            'company_id' => $run->company_id,
            'name'       => trim((string) $values['department']),
        ]);

        return Employee::query()->firstOrCreate($identity, [
            'name'          => trim((string) $values['name']),
            'work_email'    => $values['work_email'] ?? null,
            'work_phone'    => $values['work_phone'] ?? null,
            'mobile_phone'  => $values['mobile_phone'] ?? null,
            'job_title'     => $values['job_title'] ?? null,
            'department_id' => $department?->id,
            'is_active'     => $values['is_active'] ?? true,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function createFsTag(ImportRun $run, array $values): FsTag
    {
        $company = Company::query()->findOrFail($run->company_id);
        $accountId = null;

        if (filled($values['gl_code'] ?? null)) {
            $accountId = Account::query()
                ->postable()
                ->where('deprecated', false)
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim((string) $values['gl_code']))])
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $run->company_id))
                ->value('id');
        }

        return app(FsTagService::class)->create($company, [
            'code'               => trim((string) $values['code']),
            'name'               => trim((string) $values['name']),
            'account_id'         => $accountId,
            'cash_flow_category' => $values['cash_flow_category'] ?? null,
            'tax_treatment'      => $values['tax_treatment'] ?? null,
            'is_active'          => $values['is_active'] ?? true,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function createDocument(ImportRun $run, array $values): Move
    {
        $profileType = $run->profile->entity_type;
        $moveType = match ($profileType) {
            'invoice'       => MoveType::OUT_INVOICE,
            'bill'          => MoveType::IN_INVOICE,
            'claim'         => MoveType::IN_RECEIPT,
            'miscellaneous' => MoveType::ENTRY,
        };

        $currency = Currency::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['currency'])])->firstOrFail();
        $journal = Journal::query()
            ->where('company_id', $run->company_id)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['journal_code'])])
            ->firstOrFail();
        $partner = $profileType === 'miscellaneous' ? null : $this->documentPartner($run, $values);
        $paymentTerm = empty($values['payment_term'])
            ? null
            : PaymentTerm::query()
                ->where('company_id', $run->company_id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $values['payment_term']))])
                ->firstOrFail();
        $company = Company::query()->with('currency')->findOrFail($run->company_id);
        $rate = app(ExchangeRateService::class)->resolve($company, $currency, $company->currency, (string) $values['date']);

        $move = Move::query()->firstOrCreate([
            'company_id' => $run->company_id,
            'reference'  => trim((string) $values['reference']),
            'move_type'  => $moveType,
        ], [
            'journal_id'             => $journal->id,
            'partner_id'             => $partner?->id,
            'currency_id'            => $currency->id,
            'original_currency_id'   => $currency->id,
            'company_currency_id'    => $company->currency_id,
            'invoice_payment_term_id'=> $paymentTerm?->id,
            'date'                   => $values['date'],
            'invoice_date'           => $values['date'],
            'invoice_date_due'       => $values['due_date'] ?? $values['date'],
            'narration'              => $values['description'] ?? null,
            'invoice_source_email'   => $values['customer_email'] ?? null,
            'billing_address'        => $values['billing_address'] ?? null,
            'incoterm_location'      => $values['location'] ?? null,
            'booking_id'             => $values['booking_id'] ?? null,
            'consolidated_number'    => $values['consolidated_number'] ?? null,
            'drop_off'               => $values['drop_off'] ?? null,
            'amount_untaxed'         => $values['amount_untaxed'] ?? $values['amount_total'],
            'amount_tax'             => $values['amount_tax'] ?? '0.0000',
            'amount_total'           => $values['amount_total'],
            'amount_residual'        => $values['amount_total'],
            'state'                  => MoveState::DRAFT,
            'payment_state'          => PaymentState::NOT_PAID,
            'accounting_source_type' => ImportRun::class,
            'accounting_source_id'   => $run->id,
            'review_status'          => 'draft',
            'cash_flow_category'     => $values['cash_flow_category'] ?? null,
            'tax_treatment'          => $values['tax_treatment'] ?? null,
            'exchange_rate_id'       => $rate->recordId,
            'exchange_rate'          => $rate->rate,
            'rate_date'              => $rate->effectiveDate,
            'rate_source'            => $rate->source,
            'rate_type'              => $rate->type,
            'conversion_status'      => 'complete',
        ]);

        if (! $move->wasRecentlyCreated) {
            return $move;
        }

        $debitAccount = Account::query()->postable()->where('deprecated', false)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['debit_gl_code'])])
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $run->company_id))->firstOrFail();
        $creditAccount = Account::query()->postable()->where('deprecated', false)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $values['credit_gl_code'])])
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $run->company_id))->firstOrFail();
        if ($debitAccount->is($creditAccount)) {
            throw new RuntimeException('Document debit and credit GL accounts must be different.');
        }

        $originalAmount = BigDecimal::of((string) $values['amount_total'])->toScale(4, RoundingMode::HalfUp)->__toString();
        if (! BigDecimal::of($originalAmount)->isPositive()) {
            throw new RuntimeException('Document amount must be greater than zero.');
        }
        if (! $move->isInvoice(true)) {
            $this->createMiscellaneousDocumentLines($move, $journal, $company, $currency, $rate, $debitAccount, $creditAccount, $values, $originalAmount);

            return $move->fresh('lines');
        }

        $quantity = BigDecimal::of((string) ($values['quantity'] ?? '1'));
        if (! $quantity->isPositive()) {
            throw new RuntimeException('Document quantity must be greater than zero.');
        }
        $taxPercent = BigDecimal::of((string) ($values['tax_percent'] ?? '0'));
        $untaxedAmount = filled($values['amount_untaxed'] ?? null)
            ? BigDecimal::of((string) $values['amount_untaxed'])
            : (filled($values['amount_tax'] ?? null)
                ? BigDecimal::of($originalAmount)->minus((string) $values['amount_tax'])
                : BigDecimal::of($originalAmount)->dividedBy(
                    BigDecimal::one()->plus($taxPercent->dividedBy('100', 12, RoundingMode::HalfUp)),
                    4,
                    RoundingMode::HalfUp,
                ));
        $priceUnit = filled($values['rate'] ?? null)
            ? BigDecimal::of((string) $values['rate'])
            : $untaxedAmount->dividedBy($quantity, 4, RoundingMode::HalfUp);
        $productAccount = $move->isSaleDocument(true) ? $creditAccount : $debitAccount;
        $counterpartAccount = $move->isSaleDocument(true) ? $debitAccount : $creditAccount;

        $line = MoveLine::query()->create([
            'move_id'                => $move->id,
            'account_id'             => $productAccount->id,
            'partner_id'             => $partner?->id,
            'currency_id'            => $currency->id,
            'original_currency_id'   => $currency->id,
            'company_currency_id'    => $company->currency_id,
            'reference'              => $values['reference'],
            'name'                   => $values['description'] ?? $values['product_service'] ?? $values['reference'],
            'source_product_service' => $values['product_service'] ?? null,
            'source_tax_percent'     => $taxPercent->__toString(),
            'display_type'           => DisplayType::PRODUCT,
            'quantity'               => $quantity->__toString(),
            'price_unit'             => $priceUnit->__toString(),
            'discount'               => 0,
            'is_imported'            => true,
        ]);

        if ($taxPercent->isPositive()) {
            $tax = Tax::query()
                ->where('company_id', $run->company_id)
                ->where('is_active', true)
                ->where('amount', $taxPercent->__toString())
                ->where('type_tax_use', $move->isSaleDocument(true) ? TypeTaxUse::SALE : TypeTaxUse::PURCHASE)
                ->firstOrFail();
            $line->taxes()->sync([$tax->id]);
        }

        $move->updateQuietly([
            'invoice_currency_rate' => BigDecimal::one()->dividedBy($rate->rate, 15, RoundingMode::HalfUp)->__toString(),
        ]);
        $move = AccountFacade::computeAccountMove($move->fresh());

        if (! $move->paymentTermLines->contains('account_id', $counterpartAccount->id)) {
            throw new RuntimeException('The mapped receivable/payable GL does not match the configured party or company counterpart account.');
        }
        if (abs((float) $move->amount_total - (float) $originalAmount) > 0.01) {
            throw new RuntimeException("The imported quantity, rate and tax calculate to {$move->amount_total}, not the supplied total {$originalAmount}.");
        }

        $this->stampImportedDocumentLines($move, $currency, $company, $rate);

        return $move->fresh('lines');
    }

    /** @param array<string, mixed> $values */
    private function documentPartner(ImportRun $run, array $values): Partner
    {
        $query = Partner::query()->where('company_id', $run->company_id);
        if (filled($values['partner_reference'] ?? null)) {
            $query->where('reference', trim((string) $values['partner_reference']));
        } else {
            if (filled($values['customer_name'] ?? null)) {
                $query->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $values['customer_name']))]);
            }
            if (filled($values['customer_email'] ?? null)) {
                $query->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $values['customer_email']))]);
            }
        }

        return $query->sole();
    }

    private function stampImportedDocumentLines(Move $move, Currency $currency, Company $company, $rate): void
    {
        foreach ($move->lines as $line) {
            $originalSigned = BigDecimal::of((string) ($line->amount_currency ?? '0'));
            $companySigned = BigDecimal::of((string) ($line->balance ?? '0'));
            $line->updateQuietly([
                'original_currency_id'   => $currency->id,
                'company_currency_id'    => $company->currency_id,
                'original_debit'         => $originalSigned->isPositive() ? $originalSigned->__toString() : '0.0000',
                'original_credit'        => $originalSigned->isNegative() ? $originalSigned->negated()->__toString() : '0.0000',
                'original_signed_amount' => $originalSigned->__toString(),
                'company_debit'          => $companySigned->isPositive() ? $companySigned->__toString() : '0.0000',
                'company_credit'         => $companySigned->isNegative() ? $companySigned->negated()->__toString() : '0.0000',
                'company_signed_amount'  => $companySigned->__toString(),
                'exchange_rate_id'       => $rate->recordId,
                'exchange_rate'          => $rate->rate,
                'rate_date'              => $rate->effectiveDate,
                'rate_source'            => $rate->source,
                'rate_type'              => $rate->type,
                'conversion_status'      => 'complete',
                'is_imported'            => true,
            ]);
        }
    }

    /** @param array<string, mixed> $values */
    private function createMiscellaneousDocumentLines(
        Move $move,
        Journal $journal,
        Company $company,
        Currency $currency,
        $rate,
        Account $debitAccount,
        Account $creditAccount,
        array $values,
        string $originalAmount,
    ): void {
        $companyAmount = app(ExchangeRateService::class)->convert($originalAmount, $rate);
        $common = [
            'move_id'              => $move->id, 'journal_id' => $journal->id, 'company_id' => $company->id,
            'company_currency_id'  => $company->currency_id, 'currency_id' => $currency->id,
            'original_currency_id' => $currency->id, 'date' => $values['date'], 'invoice_date' => $values['date'],
            'parent_state'         => MoveState::DRAFT, 'reference' => $values['reference'],
            'name'                 => $values['description'] ?? $values['reference'], 'display_type' => DisplayType::PRODUCT,
            'exchange_rate_id'     => $rate->recordId, 'exchange_rate' => $rate->rate, 'rate_date' => $rate->effectiveDate,
            'rate_source'          => $rate->source, 'rate_type' => $rate->type, 'conversion_status' => 'complete', 'is_imported' => true,
        ];
        MoveLine::query()->create($common + [
            'sort'                   => 0, 'account_id' => $debitAccount->id, 'debit' => $companyAmount, 'credit' => '0.0000',
            'balance'                => $companyAmount, 'original_debit' => $originalAmount, 'original_credit' => '0.0000',
            'original_signed_amount' => $originalAmount, 'company_debit' => $companyAmount, 'company_credit' => '0.0000',
            'company_signed_amount'  => $companyAmount, 'amount_currency' => $originalAmount,
        ]);
        MoveLine::query()->create($common + [
            'sort'                  => 1, 'account_id' => $creditAccount->id, 'debit' => '0.0000', 'credit' => $companyAmount,
            'balance'               => BigDecimal::of($companyAmount)->negated()->__toString(), 'original_debit' => '0.0000',
            'original_credit'       => $originalAmount, 'original_signed_amount' => BigDecimal::of($originalAmount)->negated()->__toString(),
            'company_debit'         => '0.0000', 'company_credit' => $companyAmount,
            'company_signed_amount' => BigDecimal::of($companyAmount)->negated()->__toString(),
            'amount_currency'       => BigDecimal::of($originalAmount)->negated()->__toString(),
        ]);
    }

    public function exportRejectedRows(ImportRun $run): string
    {
        $headers = (array) ($run->summary['headers'] ?? []);
        $failedRows = $run->sourceRows()
            ->where('status', 'error')
            ->orderBy('source_row_number')
            ->get();

        if ($failedRows->isEmpty()) {
            if (empty($headers)) {
                return '';
            }

            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, [...$headers, 'rejection_reason']);
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);

            return (string) $csv;
        }

        if (empty($headers)) {
            $firstRaw = (array) ($failedRows->first()?->raw_values ?? []);
            $headers = array_keys($firstRaw);
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [...$headers, 'rejection_reason']);

        foreach ($failedRows as $row) {
            $rawValues = (array) $row->raw_values;
            $line = [];
            foreach ($headers as $header) {
                $line[] = $rawValues[$header] ?? '';
            }

            $messages = collect((array) $row->messages)
                ->map(function ($m): string {
                    if (is_array($m)) {
                        $field = ! empty($m['field']) ? "{$m['field']}: " : '';

                        return $field.($m['message'] ?? '');
                    }

                    return (string) $m;
                })
                ->filter()
                ->implode('; ');

            $line[] = $messages;
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }
}
