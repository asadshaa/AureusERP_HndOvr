<?php

namespace Webkul\Accounting\Services\Coa;

use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Data\Coa\CoaRow;
use Webkul\Accounting\Data\Coa\CoaWarning;
use Webkul\Accounting\Models\CoaImportBatch;
use Webkul\Accounting\Models\CoaImportSourceRow;
use Webkul\Support\Models\Company;

/**
 * Imports a planned Chart of Accounts into a single company, transactionally
 * and idempotently:
 *  - classification nodes become non-postable group accounts (parent_id tree),
 *    de-duplicated by their classification path;
 *  - each row becomes a postable leaf account under its deepest group;
 *  - accounts are linked ONLY to the selected company (M2M pivot);
 *  - re-import matches by (company, code) / (company, path) and updates changed
 *    fields only — it never overwrites accounts it did not create and never
 *    creates duplicates;
 *  - the whole run is one transaction: any error rolls everything back.
 */
class CoaImportService
{
    public function __construct(
        protected CoaRowValidator $validator,
        protected CoaHierarchyPlanner $planner,
        protected CoaAccountTypeMapper $typeMapper,
        protected MigrationJournalService $journals,
    ) {}

    /**
     * Dry-run: plan + warnings + a provisional (sheet-derived) trial balance.
     * Writes nothing.
     *
     * @param  array<int, CoaRow>  $rows
     * @return array<string, mixed>
     */
    public function preview(array $rows): array
    {
        $plan = $this->planner->plan($rows);
        $warnings = $this->validator->validate($rows);

        return [
            'groups'       => count($plan['groups']),
            'leaves'       => count($plan['leaves']),
            'warnings'     => $warnings,
            'has_errors'   => collect($warnings)->contains(fn (CoaWarning $w) => $w->isError()),
            'provisional'  => $this->provisionalTotals($rows),
            'plan'         => $plan,
            'type_preview' => collect($rows)->mapWithKeys(fn (CoaRow $r) => [
                $r->code => $this->typeMapper->suggest($r)->value,
            ])->all(),
        ];
    }

    /**
     * Import into $company. $typeOverrides maps account code => AccountType
     * value (editable in the UI); anything not overridden uses the suggestion.
     *
     * @param  array<int, CoaRow>  $rows
     * @param  array<string, string>  $typeOverrides
     * @param  array<string, mixed>  $options
     */
    public function import(
        array $rows,
        Company $company,
        array $typeOverrides = [],
        array $options = [],
        string $mode = 'structure_only',
        ?int $currencyId = null,
        ?string $filename = null,
        ?string $openingDate = null,
        ?string $movementDate = null,
        ?string $adjustmentDate = null,
        ?string $sourceSheet = null,
        ?string $fileHash = null,
        ?int $headerRowNumber = null,
        array $sourceHeaders = [],
        array $metadataRows = [],
    ): CoaImportBatch {
        $warnings = $this->validator->validate($rows);
        $errors = collect($warnings)->filter(fn (CoaWarning $w) => $w->isError())->values();

        if ($errors->isNotEmpty()) {
            throw new \RuntimeException(
                'CoA import blocked by '.$errors->count().' error(s): '
                .$errors->take(5)->map(fn (CoaWarning $w) => $w->message)->implode(' | ')
            );
        }

        $currencyId ??= $company->currency_id;

        return DB::transaction(function () use (
            $rows, $company, $typeOverrides, $options, $warnings,
            $mode, $currencyId, $filename, $openingDate, $movementDate, $adjustmentDate,
            $sourceSheet, $fileHash, $headerRowNumber, $sourceHeaders, $metadataRows
        ) {
            $sourceHeaders = $sourceHeaders ?: ($rows[0]->sourceHeaders ?? []);

            $batch = CoaImportBatch::query()->create([
                'company_id'        => $company->id,
                'currency_id'       => $currencyId,
                'filename'          => $filename,
                'source_sheet'      => $sourceSheet,
                'header_row_number' => $headerRowNumber,
                'file_hash'         => $fileHash,
                'original_headers'  => $sourceHeaders,
                'metadata_rows'     => $metadataRows,
                'mode'              => $mode,
                'status'            => 'pending',
                'opening_date'      => $openingDate,
                'movement_date'     => $movementDate,
                'adjustment_date'   => $adjustmentDate,
                'options'           => array_merge($options, [
                    'warnings'       => array_map(fn (CoaWarning $w) => $w->toArray(), $warnings),
                    'type_overrides' => $typeOverrides,
                ]),
            ]);

            $plan = $this->planner->plan($rows);

            [$linkedLeafByCode, $linkedGroupByPath] = $this->existingAccounts($company->id);

            $groupsCreated = 0;
            $groupIdByLabel = [];

            foreach ($plan['groups'] as $group) {
                $existing = $linkedGroupByPath[$group['path_label']] ?? null;
                $parentId = $group['parent_label'] !== null
                    ? ($groupIdByLabel[$group['parent_label']] ?? null)
                    : null;

                if ($existing) {
                    $existing->update([
                        'parent_id'                  => $parentId,
                        'import_batch_id'            => $batch->id,
                        'source_classification_path' => $group['path_label'],
                    ]);
                    $account = $existing;
                } else {
                    $account = Account::query()->create([
                        'name'                        => $group['name'],
                        'code'                        => null,
                        'account_type'                => $this->groupType($group),
                        'parent_id'                   => $parentId,
                        'currency_id'                 => $currencyId,
                        'is_group'                    => true,
                        'deprecated'                  => false,
                        'source_classification_path'  => $group['path_label'],
                        'import_batch_id'             => $batch->id,
                    ]);
                    $groupsCreated++;
                }

                $account->companies()->syncWithoutDetaching([$company->id]);
                $groupIdByLabel[$group['path_label']] = $account->id;
            }

            $accountsCreated = 0;
            $accountsUpdated = 0;

            foreach ($plan['leaves'] as $leaf) {
                /** @var CoaRow $row */
                $row = $leaf['row'];
                $type = AccountType::tryFrom($typeOverrides[$row->code] ?? '') ?? $this->typeMapper->suggest($row);
                $parentId = $groupIdByLabel[$leaf['parent_group_label']] ?? null;

                $existing = $linkedLeafByCode[$row->code] ?? null;

                if ($existing) {
                    // Never overwrite an unrelated, pre-existing account: only
                    // update accounts this importer created.
                    if ($existing->import_batch_id === null) {
                        continue;
                    }

                    $existing->update([
                        'name'                        => $row->title,
                        'account_type'                => $type,
                        'parent_id'                   => $parentId,
                        'currency_id'                 => $currencyId,
                        'is_group'                    => false,
                        'reconcile'                   => $type->isReconcilable(),
                        'source_classification_path'  => $row->classificationPathLabel(),
                        'import_batch_id'             => $batch->id,
                    ]);
                    $existing->companies()->syncWithoutDetaching([$company->id]);
                    $accountsUpdated++;

                    continue;
                }

                $account = Account::query()->create([
                    'name'                        => $row->title,
                    'code'                        => $row->code,
                    'account_type'                => $type,
                    'parent_id'                   => $parentId,
                    'currency_id'                 => $currencyId,
                    'is_group'                    => false,
                    'deprecated'                  => false,
                    // Receivable/payable/cash accounts must be reconcilable or
                    // their move lines never track an outstanding balance.
                    'reconcile'                   => $type->isReconcilable(),
                    'source_classification_path'  => $row->classificationPathLabel(),
                    'import_batch_id'             => $batch->id,
                ]);
                $account->companies()->syncWithoutDetaching([$company->id]);
                $accountsCreated++;
            }

            $this->persistSourceRows($batch, $company, $rows);

            $journalsCreated = 0;
            $skippedJournals = [];

            if ($mode === 'with_journals') {
                // options['override_period_lock'] is the explicit opt-out for
                // the initial historical migration (see MigrationJournalService);
                // it is kept in the batch options as the audit of that choice.
                $journalsCreated = $this->journals->createForBatch(
                    $batch,
                    $rows,
                    $company,
                    $currencyId,
                    $openingDate,
                    $movementDate,
                    $adjustmentDate,
                    (bool) ($options['override_period_lock'] ?? false),
                );
                $skippedJournals = $this->journals->skippedJournals();
            }

            $batch->update([
                'status'           => 'imported',
                'groups_created'   => $groupsCreated,
                'accounts_created' => $accountsCreated,
                'accounts_updated' => $accountsUpdated,
                'journals_created' => $journalsCreated,
                'options'          => array_merge((array) $batch->options, ['skipped_journals' => $skippedJournals]),
            ]);

            return $batch->fresh();
        });
    }

    /**
     * @param  array<int, CoaRow>  $rows
     */
    protected function persistSourceRows(CoaImportBatch $batch, Company $company, array $rows): void
    {
        $accountIds = Account::query()
            ->postable()
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
            ->whereIn('code', array_values(array_filter(array_map(fn (CoaRow $row) => $row->code, $rows))))
            ->pluck('id', 'code');

        foreach ($rows as $order => $row) {
            $classifications = array_fill(1, 7, null);
            foreach ($row->classificationValues as $header => $value) {
                if (preg_match('/classification\s*(\d+)/i', (string) $header, $matches) === 1) {
                    $number = (int) $matches[1];
                    if ($number >= 1 && $number <= 7) {
                        $classifications[$number] = $value !== '' ? $value : null;
                    }
                }
            }

            CoaImportSourceRow::query()->create([
                'batch_id'              => $batch->id,
                'company_id'            => $company->id,
                'canonical_account_id'  => $accountIds[$row->code] ?? null,
                'row_order'             => $order + 1,
                'source_row_number'     => $row->sheetRow,
                'nature'                => $row->nature,
                'classification_1'      => $classifications[1],
                'classification_2'      => $classifications[2],
                'classification_3'      => $classifications[3],
                'classification_4'      => $classifications[4],
                'classification_5'      => $classifications[5],
                'classification_6'      => $classifications[6],
                'classification_7'      => $classifications[7],
                'code'                  => $row->code,
                'title'                 => $row->title,
                'opening_debit'         => $row->openingDebit,
                'opening_credit'        => $row->openingCredit,
                'movement_debit'        => $row->movementDebit,
                'movement_credit'       => $row->movementCredit,
                'adjustment_debit'      => $row->adjustmentDebit,
                'adjustment_credit'     => $row->adjustmentCredit,
                'closing_debit'         => $row->closingDebit,
                'closing_credit'        => $row->closingCredit,
                'classification_values' => $row->classificationValues,
                'raw_row'               => $row->rawRow,
                'raw_row_by_header'     => $row->rawRowByHeader,
            ]);
        }
    }

    /**
     * Existing accounts already linked to the company, so re-import matches
     * instead of duplicating.
     *
     * @return array{0: array<string, Account>, 1: array<string, Account>}
     */
    protected function existingAccounts(int $companyId): array
    {
        $linked = Account::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->get();

        $leafByCode = [];
        $groupByPath = [];

        foreach ($linked as $account) {
            if ($account->is_group) {
                if ($account->source_classification_path) {
                    $groupByPath[$account->source_classification_path] = $account;
                }
            } elseif ($account->code !== null) {
                $leafByCode[$account->code] = $account;
            }
        }

        return [$leafByCode, $groupByPath];
    }

    protected function groupType(array $group): AccountType
    {
        $class1 = mb_strtolower($group['class1']);
        $nature = mb_strtolower($group['nature']);

        return match (true) {
            str_contains($class1, 'asset')   => AccountType::ASSET_CURRENT,
            str_contains($class1, 'liab')    => AccountType::LIABILITY_CURRENT,
            str_contains($class1, 'equity')  => AccountType::EQUITY,
            str_contains($class1, 'revenue') => AccountType::INCOME,
            str_contains($class1, 'income')  => AccountType::INCOME_OTHER,
            str_contains($class1, 'expense') => AccountType::EXPENSE,
            $nature === 'p&l'                => AccountType::EXPENSE,
            default                          => AccountType::ASSET_CURRENT,
        };
    }

    /**
     * @param  array<int, CoaRow>  $rows
     * @return array<string, float>
     */
    protected function provisionalTotals(array $rows): array
    {
        $t = [
            'opening_debit'    => 0.0, 'opening_credit' => 0.0,
            'movement_debit'   => 0.0, 'movement_credit' => 0.0,
            'adjustment_debit' => 0.0, 'adjustment_credit' => 0.0,
            'closing_debit'    => 0.0, 'closing_credit' => 0.0,
        ];

        foreach ($rows as $r) {
            $t['opening_debit'] += $r->openingDebit;
            $t['opening_credit'] += $r->openingCredit;
            $t['movement_debit'] += $r->movementDebit;
            $t['movement_credit'] += $r->movementCredit;
            $t['adjustment_debit'] += $r->adjustmentDebit;
            $t['adjustment_credit'] += $r->adjustmentCredit;
            $t['closing_debit'] += $r->closingDebit;
            $t['closing_credit'] += $r->closingCredit;
        }

        return $t;
    }
}
