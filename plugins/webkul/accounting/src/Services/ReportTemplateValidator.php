<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Collection;
use RuntimeException;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Data\ReportLineValue;
use Webkul\Accounting\Data\ValidationIssue;
use Webkul\Accounting\Enums\ColumnType;
use Webkul\Accounting\Enums\FormulaOperandType;
use Webkul\Accounting\Enums\FormulaPurpose;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\Formula\CycleDetector;

/**
 * Validates a report template's structural integrity before it is previewed,
 * published or exported. Returns every problem found (never throws), so the
 * designer UI can list them all at once.
 *
 * Runtime control totals ("Check" rows expected to be zero) are validated
 * separately via checkViolations(), since they need computed results.
 */
class ReportTemplateValidator
{
    public function __construct(
        protected ReportValueProviderRegistry $providers,
    ) {}

    /**
     * The account coverage checks (unbound accounts with balances) use the
     * template's company, else the given company (e.g. the viewer's active
     * company for a global template); without either they are skipped.
     *
     * @return Collection<int, ValidationIssue>
     */
    public function validate(ReportTemplate $template, ?int $companyId = null): Collection
    {
        /** @var Collection<int, ReportLine> $lines */
        $lines = $template->lines()
            ->with(['accountBindings', 'formulas'])
            ->orderBy('sort')
            ->get();

        $columns = $template->columns()->get();

        $issues = collect();

        $this->validateCycles($lines, $issues);
        $this->validateLineSources($lines, $issues);
        $this->validateFormulas($template, $lines, $issues);
        $this->validateDuplicateSorts($lines, $columns, $issues);
        $this->validateColumns($columns, $issues);
        $this->validateGlobalCodeUniqueness($template, $issues);
        $this->validateAccountCoverage($lines, $template->company_id ?? $companyId, $issues);

        return $issues->values();
    }

    /**
     * Control-total validation on computed results: every line flagged
     * `is_check` must evaluate to (approximately) zero in every column.
     *
     * @param  Collection<int, ReportLineValue>  $results
     * @return Collection<int, ValidationIssue>
     */
    public function checkViolations(Collection $results, float $tolerance = 0.01): Collection
    {
        $issues = collect();

        foreach ($results as $result) {
            if (! $result->isCheck) {
                continue;
            }

            foreach ($result->values as $columnKey => $value) {
                if (abs($value) > $tolerance) {
                    $issues->push(ValidationIssue::error(
                        'check_row_violation',
                        __('accounting::validation.check-row-violation', [
                            'line'   => $result->caption ?? "#{$result->lineId}",
                            'column' => $columnKey,
                            'value'  => $value,
                        ]),
                        $result->lineId,
                    ));
                }
            }
        }

        return $issues->values();
    }

    /**
     * @param  Collection<int, ReportLine>  $lines
     * @param  Collection<int, ValidationIssue>  $issues
     */
    protected function validateCycles(Collection $lines, Collection $issues): void
    {
        try {
            CycleDetector::forLines($lines)->assertAcyclic();
        } catch (RuntimeException $exception) {
            $issues->push(ValidationIssue::error(
                'formula_cycle',
                __('accounting::validation.formula-cycle', ['chain' => $exception->getMessage()]),
            ));
        }
    }

    /**
     * @param  Collection<int, ReportLine>  $lines
     * @param  Collection<int, ValidationIssue>  $issues
     */
    protected function validateLineSources(Collection $lines, Collection $issues): void
    {
        foreach ($lines as $line) {
            $source = $line->effectiveValueSource();
            $label = $this->lineLabel($line);

            if ($source === ValueSource::LEDGER && $line->accountBindings->isEmpty()) {
                $issues->push(ValidationIssue::error(
                    'missing_account_bindings',
                    __('accounting::validation.missing-account-bindings', ['line' => $label]),
                    (int) $line->id,
                ));
            }

            if ($source === ValueSource::FORMULA && ! $this->hasFormulasFor($line, FormulaPurpose::VALUE)) {
                $issues->push(ValidationIssue::error(
                    'missing_formulas',
                    __('accounting::validation.missing-formulas', ['line' => $label]),
                    (int) $line->id,
                ));
            }

            if ($source === ValueSource::EXTERNAL) {
                if ($line->external_provider === null || $line->external_provider === '') {
                    $issues->push(ValidationIssue::error(
                        'missing_external_provider',
                        __('accounting::validation.missing-external-provider', ['line' => $label]),
                        (int) $line->id,
                    ));
                } elseif (! $this->providers->has((string) $line->external_provider)) {
                    $issues->push(ValidationIssue::error(
                        'unregistered_external_provider',
                        __('accounting::validation.unregistered-external-provider', [
                            'line'     => $label,
                            'provider' => $line->external_provider,
                        ]),
                        (int) $line->id,
                    ));
                }
            }

            if ($line->is_check && $source === null) {
                $issues->push(ValidationIssue::warning(
                    'check_line_carries_no_values',
                    __('accounting::validation.check-line-carries-no-values', ['line' => $label]),
                    (int) $line->id,
                ));
            }

            if ($line->dimension_type !== null || $line->dimension_id !== null) {
                $issues->push(ValidationIssue::warning(
                    'dimension_not_applied',
                    __('accounting::validation.dimension-not-applied', ['line' => $label]),
                    (int) $line->id,
                ));
            }
        }
    }

    /**
     * @param  Collection<int, ReportLine>  $lines
     * @param  Collection<int, ValidationIssue>  $issues
     */
    protected function validateFormulas(ReportTemplate $template, Collection $lines, Collection $issues): void
    {
        $linesById = $lines->keyBy(fn (ReportLine $line) => (int) $line->id);

        $referencedIds = $lines
            ->flatMap(fn (ReportLine $line) => $line->formulas->pluck('operand_line_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $linesById->has($id))
            ->values();

        // One query resolves whether out-of-template references exist at all
        // (cross-template) or point at nothing (dangling).
        $foreignLines = $referencedIds->isEmpty()
            ? collect()
            : ReportLine::query()->whereIn('id', $referencedIds)->get()->keyBy('id');

        foreach ($lines as $line) {
            $label = $this->lineLabel($line);

            foreach ($line->formulas as $formula) {
                $operandType = $formula->operand_type instanceof FormulaOperandType
                    ? $formula->operand_type
                    : FormulaOperandType::from((string) $formula->operand_type);

                if ($operandType === FormulaOperandType::CONSTANT) {
                    if ($formula->operand_constant === null) {
                        $issues->push(ValidationIssue::error(
                            'operand_constant_missing_value',
                            __('accounting::validation.operand-constant-missing-value', ['line' => $label]),
                            (int) $line->id,
                        ));
                    }

                    continue;
                }

                if ($formula->operand_line_id === null) {
                    $issues->push(ValidationIssue::error(
                        'operand_line_missing_id',
                        __('accounting::validation.operand-line-missing-id', ['line' => $label]),
                        (int) $line->id,
                    ));

                    continue;
                }

                $operandLineId = (int) $formula->operand_line_id;

                /** @var ReportLine|null $target */
                $target = $linesById->get($operandLineId);

                if ($target === null) {
                    $foreign = $foreignLines->get($operandLineId);

                    $issues->push($foreign !== null
                        ? ValidationIssue::error(
                            'cross_template_operand',
                            __('accounting::validation.cross-template-operand', [
                                'line'   => $label,
                                'target' => $foreign->caption ?? "#{$operandLineId}",
                            ]),
                            (int) $line->id,
                        )
                        : ValidationIssue::error(
                            'missing_operand_line',
                            __('accounting::validation.missing-operand-line', [
                                'line'   => $label,
                                'target' => "#{$operandLineId}",
                            ]),
                            (int) $line->id,
                        ));

                    continue;
                }

                if ($target->effectiveValueSource() === null) {
                    $issues->push(ValidationIssue::error(
                        'operand_not_computable',
                        __('accounting::validation.operand-not-computable', [
                            'line'   => $label,
                            'target' => $this->lineLabel($target),
                        ]),
                        (int) $line->id,
                    ));
                }
            }
        }
    }

    /**
     * @param  Collection<int, ReportLine>  $lines
     * @param  Collection<int, ReportColumn>  $columns
     * @param  Collection<int, ValidationIssue>  $issues
     */
    protected function validateDuplicateSorts(Collection $lines, Collection $columns, Collection $issues): void
    {
        $duplicateLineSorts = $lines
            ->groupBy(fn (ReportLine $line) => (int) $line->sort)
            ->filter(fn (Collection $group) => $group->count() > 1);

        foreach ($duplicateLineSorts as $sort => $group) {
            $issues->push(ValidationIssue::warning(
                'duplicate_line_sort',
                __('accounting::validation.duplicate-line-sort', [
                    'sort'  => $sort,
                    'lines' => $group->map(fn (ReportLine $l) => $this->lineLabel($l))->implode(', '),
                ]),
            ));
        }

        $duplicateColumnSorts = $columns
            ->groupBy(fn (ReportColumn $column) => (int) $column->sort)
            ->filter(fn (Collection $group) => $group->count() > 1);

        foreach ($duplicateColumnSorts as $sort => $group) {
            $issues->push(ValidationIssue::warning(
                'duplicate_column_sort',
                __('accounting::validation.duplicate-column-sort', ['sort' => $sort]),
            ));
        }
    }

    /**
     * @param  Collection<int, ReportColumn>  $columns
     * @param  Collection<int, ValidationIssue>  $issues
     */
    protected function validateColumns(Collection $columns, Collection $issues): void
    {
        foreach ($columns as $column) {
            $type = $column->column_type instanceof ColumnType
                ? $column->column_type
                : ColumnType::from((string) $column->column_type);

            $invalid = match ($type) {
                ColumnType::MONTH => $column->start_month === null
                    || $column->start_month < 1
                    || $column->start_month > 12,
                ColumnType::RANGE => $column->start_month === null
                    || $column->end_month === null
                    || $column->start_month < 1
                    || $column->start_month > 12
                    || $column->end_month < 1
                    || $column->end_month > 12
                    || $column->end_month < $column->start_month,
                default => false,
            };

            if ($invalid) {
                $issues->push(ValidationIssue::error(
                    'invalid_column_definition',
                    __('accounting::validation.invalid-column-definition', [
                        'column' => $column->label ?? "#{$column->id}",
                    ]),
                    null,
                    (int) $column->id,
                ));
            }
        }
    }

    /**
     * MySQL treats NULLs as distinct in unique indexes, so the schema-level
     * unique(company_id, code, version) does not protect global templates.
     *
     * @param  Collection<int, ValidationIssue>  $issues
     */
    protected function validateGlobalCodeUniqueness(ReportTemplate $template, Collection $issues): void
    {
        if ($template->company_id !== null || ! $template->exists) {
            return;
        }

        $duplicateExists = ReportTemplate::query()
            ->whereNull('company_id')
            ->where('code', $template->code)
            ->where('version', $template->version)
            ->whereKeyNot($template->id)
            ->exists();

        if ($duplicateExists) {
            $issues->push(ValidationIssue::error(
                'duplicate_global_code',
                __('accounting::validation.duplicate-global-code', [
                    'code'    => $template->code,
                    'version' => $template->version,
                ]),
            ));
        }
    }

    /**
     * Accounts bound to two lines are counted twice; accounts with a posted
     * balance in the company that are bound to no line are silently left out.
     * Only the statement kinds the template already covers are checked (a
     * P&L template is not asked to bind balance-sheet accounts).
     *
     * @param  Collection<int, ReportLine>  $lines
     * @param  Collection<int, ValidationIssue>  $issues
     */
    protected function validateAccountCoverage(Collection $lines, ?int $companyId, Collection $issues): void
    {
        $bindings = $lines->flatMap(fn (ReportLine $line) => $line->accountBindings
            ->map(fn ($binding): array => ['account_id' => (int) $binding->account_id, 'line' => $line]));

        if ($bindings->isEmpty()) {
            return;
        }

        $boundIds = $bindings->pluck('account_id')->unique()->values();
        $accounts = Account::query()->whereIn('id', $boundIds)->get(['id', 'code', 'name', 'account_type'])->keyBy('id');

        foreach ($bindings->groupBy('account_id')->filter(fn (Collection $group) => $group->count() > 1) as $accountId => $group) {
            $account = $accounts->get($accountId);

            $issues->push(ValidationIssue::warning(
                'account_bound_twice',
                __('accounting::validation.account-bound-twice', [
                    'account' => $account ? trim("{$account->code} {$account->name}") : "#{$accountId}",
                    'lines'   => $group->map(fn (array $binding) => $this->lineLabel($binding['line']))->implode(', '),
                ]),
                (int) $group->first()['line']->id,
            ));
        }

        if ($companyId === null) {
            return;
        }

        $profitLoss = array_merge(array_keys(AccountType::income()), array_keys(AccountType::expenses()));
        $balanceSheet = array_merge(array_keys(AccountType::assets()), array_keys(AccountType::liabilities()), array_keys(AccountType::equity()));
        $boundTypes = $accounts->map(fn (Account $account) => $account->account_type instanceof AccountType ? $account->account_type->value : $account->account_type);

        $coveredTypes = [
            ...($boundTypes->intersect($profitLoss)->isNotEmpty() ? $profitLoss : []),
            ...($boundTypes->intersect($balanceSheet)->isNotEmpty() ? $balanceSheet : []),
        ];

        if ($coveredTypes === []) {
            return;
        }

        $unbound = MoveLine::query()
            ->join('accounts_account_moves', 'accounts_account_moves.id', '=', 'accounts_account_move_lines.move_id')
            ->join('accounts_accounts', 'accounts_accounts.id', '=', 'accounts_account_move_lines.account_id')
            ->where('accounts_account_moves.company_id', $companyId)
            ->where('accounts_account_moves.state', MoveState::POSTED)
            ->whereIn('accounts_accounts.account_type', $coveredTypes)
            ->whereNotIn('accounts_account_move_lines.account_id', $boundIds)
            ->groupBy('accounts_accounts.id', 'accounts_accounts.code', 'accounts_accounts.name')
            ->havingRaw('ABS(SUM(accounts_account_move_lines.debit - accounts_account_move_lines.credit)) >= 0.005')
            ->orderBy('accounts_accounts.code')
            ->get(['accounts_accounts.code', 'accounts_accounts.name']);

        if ($unbound->isNotEmpty()) {
            $issues->push(ValidationIssue::warning(
                'unbound_account_with_balance',
                __('accounting::validation.unbound-account-with-balance', [
                    'count'    => $unbound->count(),
                    'accounts' => $unbound->take(10)->map(fn ($row) => trim("{$row->code} {$row->name}"))->implode(', ')
                        .($unbound->count() > 10 ? ', ...' : ''),
                ]),
            ));
        }
    }

    protected function hasFormulasFor(ReportLine $line, FormulaPurpose $purpose): bool
    {
        return $line->formulas->contains(function (ReportLineFormula $formula) use ($purpose) {
            $formulaPurpose = $formula->purpose;

            if ($formulaPurpose === null) {
                return $purpose === FormulaPurpose::VALUE;
            }

            $formulaPurpose = $formulaPurpose instanceof FormulaPurpose
                ? $formulaPurpose
                : FormulaPurpose::from((string) $formulaPurpose);

            return $formulaPurpose === $purpose;
        });
    }

    protected function lineLabel(ReportLine $line): string
    {
        return $line->caption ?? "#{$line->id}";
    }
}
