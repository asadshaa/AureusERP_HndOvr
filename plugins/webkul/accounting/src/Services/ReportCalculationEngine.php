<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Collection;
use Webkul\Accounting\Data\MeasureReference;
use Webkul\Accounting\Data\ReportColumnSpec;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportLineValue;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Data\ResolutionContext;
use Webkul\Accounting\Enums\FormulaPurpose;
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineInput;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Repositories\LedgerBalanceRepository;
use Webkul\Accounting\Services\Formula\CycleDetector;
use Webkul\Accounting\Services\Formula\FormulaEvaluator;
use Webkul\Accounting\Services\Resolvers\LedgerMeasureResolver;

/**
 * Computes every line's value, for every column, for one report template.
 *
 * Flow per report:
 *   1. Load lines (ordered) with their bindings and formulas.
 *   2. Assert the computed-line dependency graph is acyclic.
 *   3. Classify lines by their effective value source
 *      (ledger / formula / manual / external / none).
 *   4. Resolve each ledger line's signed account set once.
 *   5. Read ledger balances in bulk, batched by (company scope, value basis)
 *      across all columns, so a full report costs a handful of queries.
 *   6. Per column: resolve ledger sums, manual inputs and external provider
 *      values, then evaluate formula lines in dependency order. In a
 *      consolidated column a line's consolidation-purpose formulas (when
 *      present) override its value formulas.
 *   7. Emit one ReportLineValue per line, values keyed by column key.
 *
 * Nothing about the layout, the columns or the formulas is hardcoded; all of
 * it is read from the template tables. Consolidated columns default to the
 * multi-entity ledger scope — arithmetically the simple sum across entities —
 * and only deviate where a consolidation formula is defined.
 *
 * Entity overrides (a column's or a line's company) are only honoured within
 * the viewer's authorised companies (ReportContext::narrowTo()). A column or
 * line outside them is "not available": its value is null — never a figure —
 * and so is every formula line that depends on it.
 */
class ReportCalculationEngine
{
    protected MeasureResolverRegistry $measureResolvers;

    public function __construct(
        protected LedgerBalanceRepository $ledger,
        protected AccountBindingService $bindings,
        protected FormulaEvaluator $evaluator,
        protected ?ReportValueProviderRegistry $providers = null,
        ?MeasureResolverRegistry $measureResolvers = null,
    ) {
        // Route ledger reads through the resolver seam. When the engine is
        // constructed directly (e.g. in tests) with no registry, fall back to a
        // local registry holding just the ledger resolver, so behaviour is
        // identical whether or not the container-bound singleton is injected.
        $this->measureResolvers = $measureResolvers ?? $this->defaultMeasureResolvers();
    }

    protected function defaultMeasureResolvers(): MeasureResolverRegistry
    {
        $registry = new MeasureResolverRegistry;
        $registry->register(new LedgerMeasureResolver($this->ledger));

        return $registry;
    }

    /**
     * Backward-compatible entry point: one plain column per period, all under
     * the run context, with a single basis for every ledger line (subject to
     * per-line overrides).
     *
     * @param  array<int, ReportPeriod>  $periods
     * @return Collection<int, ReportLineValue> in template line order
     */
    public function calculate(ReportTemplate $template, array $periods, ReportContext $context, bool $cumulative = false): Collection
    {
        $columns = array_map(
            fn (ReportPeriod $period) => new ReportColumnSpec($period->key, $period->label, $period),
            $periods,
        );

        return $this->calculateForColumns(
            $template,
            $columns,
            $context,
            $cumulative ? ValueBasis::CLOSING_BALANCE : ValueBasis::MOVEMENT,
        );
    }

    /**
     * @param  array<int, ReportColumnSpec>  $columns
     * @return Collection<int, ReportLineValue> in template line order
     */
    public function calculateForColumns(ReportTemplate $template, array $columns, ReportContext $context, ValueBasis $defaultBasis): Collection
    {
        /** @var Collection<int, ReportLine> $lines */
        $lines = $template->lines()
            ->with(['accountBindings', 'formulas'])
            ->orderBy('sort')
            ->get();

        CycleDetector::forLines($lines)->assertAcyclic();

        $bySource = $this->classifyBySource($lines);

        $ledgerLines = $bySource[ValueSource::LEDGER->value];
        $formulaLines = $bySource[ValueSource::FORMULA->value];
        $manualLines = $bySource[ValueSource::MANUAL->value];
        $externalLines = $bySource[ValueSource::EXTERNAL->value];

        // Resolve signed account sets for ledger lines once (column-independent).
        $signedAccountsByLine = [];

        foreach ($ledgerLines as $line) {
            $signedAccountsByLine[(int) $line->id] = $this->bindings->resolveSignedAccounts($line);
        }

        $dataColumns = array_values(array_filter($columns, fn (ReportColumnSpec $c) => ! $c->isSpacer()));

        $balances = $this->ledgerBalances($ledgerLines, $signedAccountsByLine, $dataColumns, $context, $defaultBasis);
        $manualValues = $this->manualValues($manualLines, $dataColumns, $context);

        $computedOrder = $this->topologicalOrder($formulaLines, $lines);

        // Per-column value maps: line_id => value (null = not available).
        // A column outside the viewer's companies maps to null as a whole.
        $valuesByColumnLine = [];

        foreach ($dataColumns as $column) {
            if ($column->contextFor($context) === null) {
                $valuesByColumnLine[$column->key] = null;

                continue;
            }

            $lineValues = [];

            foreach ($ledgerLines as $line) {
                $lineValues[(int) $line->id] = $this->applyLineSign(
                    $line,
                    $this->ledgerLineValue($line, $column, $context, $defaultBasis, $signedAccountsByLine, $balances),
                );
            }

            foreach ($manualLines as $line) {
                $lineValues[(int) $line->id] = $this->applyLineSign(
                    $line,
                    $manualValues[(int) $line->id][$column->key] ?? 0.0,
                );
            }

            foreach ($externalLines as $line) {
                $lineValues[(int) $line->id] = $this->applyLineSign(
                    $line,
                    $this->externalLineValue($line, $column, $context),
                );
            }

            foreach ($computedOrder as $line) {
                $purpose = $this->formulaPurposeFor($line, $column);

                $lineValues[(int) $line->id] = $this->referencesUnavailableLine($line, $lineValues, $purpose)
                    ? null
                    : $this->applyLineSign($line, $this->evaluator->evaluate($line, $lineValues, $purpose));
            }

            $valuesByColumnLine[$column->key] = $lineValues;
        }

        // Assemble output in template order.
        return $lines->map(function (ReportLine $line) use ($dataColumns, $valuesByColumnLine) {
            $carries = $line->effectiveValueSource() !== null;

            $values = [];
            if ($carries) {
                foreach ($dataColumns as $column) {
                    $columnValues = $valuesByColumnLine[$column->key];
                    $lineId = (int) $line->id;

                    $values[$column->key] = match (true) {
                        $columnValues === null                     => null,
                        ! array_key_exists($lineId, $columnValues) => 0.0,
                        $columnValues[$lineId] === null            => null,
                        default                                    => (float) $columnValues[$lineId],
                    };
                }
            }

            return ReportLineValue::fromLine($line, $values);
        })->values();
    }

    /**
     * Bucket the template's lines by their effective value source.
     *
     * @param  Collection<int, ReportLine>  $lines
     * @return array<string, Collection<int, ReportLine>>
     */
    protected function classifyBySource(Collection $lines): array
    {
        $bySource = [];

        foreach (ValueSource::cases() as $source) {
            $bySource[$source->value] = collect();
        }

        foreach ($lines as $line) {
            $source = $line->effectiveValueSource();

            if ($source !== null) {
                $bySource[$source->value]->push($line);
            }
        }

        return $bySource;
    }

    /**
     * Read every ledger balance the report needs, batched by (scope, basis) so
     * the number of queries depends on the variety of scopes and bases — not on
     * the number of columns or lines.
     *
     * @param  Collection<int, ReportLine>  $ledgerLines
     * @param  array<int, array<int, int>>  $signedAccountsByLine
     * @param  array<int, ReportColumnSpec>  $dataColumns
     * @return array<string, array<int, array<string, float>>> group key => [account_id][period_key] => balance
     */
    protected function ledgerBalances(Collection $ledgerLines, array $signedAccountsByLine, array $dataColumns, ReportContext $context, ValueBasis $defaultBasis): array
    {
        $groups = [];

        foreach ($dataColumns as $column) {
            if ($column->contextFor($context) === null) {
                continue;
            }

            foreach ($ledgerLines as $line) {
                $lineContext = $this->lineContext($line, $column, $context);

                if ($lineContext === null) {
                    continue;
                }

                $basis = $line->effectiveValueBasis($defaultBasis);
                $groupKey = $this->groupKey($lineContext, $basis);

                $groups[$groupKey] ??= [
                    'context'  => $lineContext,
                    'basis'    => $basis,
                    'periods'  => [],
                    'accounts' => [],
                ];

                $groups[$groupKey]['periods'][$column->period->key] = $column->period;

                foreach (array_keys($signedAccountsByLine[(int) $line->id]) as $accountId) {
                    $groups[$groupKey]['accounts'][$accountId] = true;
                }
            }
        }

        $resolver = $this->measureResolvers->for(MeasureReference::SOURCE_LEDGER);

        $balances = [];

        foreach ($groups as $groupKey => $group) {
            $references = array_map(
                fn ($accountId) => MeasureReference::ledgerAccount((int) $accountId),
                array_keys($group['accounts']),
            );

            $balances[$groupKey] = $resolver->resolve(
                $references,
                array_values($group['periods']),
                new ResolutionContext($group['context'], $group['basis']),
            )->all();
        }

        return $balances;
    }

    protected function ledgerLineValue(ReportLine $line, ReportColumnSpec $column, ReportContext $context, ValueBasis $defaultBasis, array $signedAccountsByLine, array $balances): ?float
    {
        $lineContext = $this->lineContext($line, $column, $context);

        if ($lineContext === null) {
            return null;
        }

        $basis = $line->effectiveValueBasis($defaultBasis);
        $groupKey = $this->groupKey($lineContext, $basis);

        $groupBalances = $balances[$groupKey] ?? [];

        $sum = 0.0;

        foreach ($signedAccountsByLine[(int) $line->id] as $accountId => $sign) {
            $balance = (float) ($groupBalances[$accountId][$column->period->key] ?? 0.0);

            $sum += $balance * ($sign === -1 ? -1.0 : 1.0);
        }

        return $sum;
    }

    /**
     * Manual line values per column: the sum of the line's input entries whose
     * date falls inside the column period and whose company matches the
     * column's scope (entries without a company apply to every scope).
     *
     * @param  Collection<int, ReportLine>  $manualLines
     * @param  array<int, ReportColumnSpec>  $dataColumns
     * @return array<int, array<string, float|null>> [line_id][column_key] => value (null = not available)
     */
    protected function manualValues(Collection $manualLines, array $dataColumns, ReportContext $context): array
    {
        if ($manualLines->isEmpty() || $dataColumns === []) {
            return [];
        }

        $rangeStart = collect($dataColumns)->min(fn (ReportColumnSpec $c) => $c->period->startDate->toDateString());
        $rangeEnd = collect($dataColumns)->max(fn (ReportColumnSpec $c) => $c->period->endDate->toDateString());

        $inputs = ReportLineInput::query()
            ->whereIn('report_line_id', $manualLines->map(fn (ReportLine $l) => (int) $l->id)->all())
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->get()
            ->groupBy('report_line_id');

        $values = [];

        foreach ($manualLines as $line) {
            $lineInputs = $inputs->get((int) $line->id, collect());

            foreach ($dataColumns as $column) {
                $lineContext = $this->lineContext($line, $column, $context);

                if ($lineContext === null) {
                    $values[(int) $line->id][$column->key] = null;

                    continue;
                }

                $scope = $lineContext->companyIds;

                $sum = 0.0;

                foreach ($lineInputs as $input) {
                    $date = $input->date->toDateString();

                    if ($date < $column->period->startDate->toDateString() || $date > $column->period->endDate->toDateString()) {
                        continue;
                    }

                    if ($input->company_id !== null && $lineContext->hasCompanyScope() && ! in_array((int) $input->company_id, $scope, true)) {
                        continue;
                    }

                    $sum += (float) $input->value;
                }

                $values[(int) $line->id][$column->key] = $sum;
            }
        }

        return $values;
    }

    protected function externalLineValue(ReportLine $line, ReportColumnSpec $column, ReportContext $context): ?float
    {
        $lineContext = $this->lineContext($line, $column, $context);

        if ($lineContext === null) {
            return null;
        }

        $registry = $this->providers ?? app(ReportValueProviderRegistry::class);

        return $registry->value(
            (string) $line->external_provider,
            $line,
            $column->period,
            $lineContext,
        );
    }

    /**
     * The context a line is computed under for a column: a line-level company
     * override wins over the column's scope, which wins over the run context.
     * Either override is intersected with the viewer's authorised companies;
     * null means the line is not available in this column.
     */
    protected function lineContext(ReportLine $line, ReportColumnSpec $column, ReportContext $context): ?ReportContext
    {
        if ($line->company_id !== null) {
            return $context->narrowTo([(int) $line->company_id]);
        }

        return $column->contextFor($context);
    }

    /**
     * Whether a formula line depends (for the given purpose) on a line whose
     * value is not available — in which case its own result is unavailable
     * too, rather than a total silently missing an entity.
     *
     * @param  array<int, float|null>  $lineValues
     */
    protected function referencesUnavailableLine(ReportLine $line, array $lineValues, FormulaPurpose $purpose): bool
    {
        foreach ($line->formulas as $formula) {
            if ($this->purposeOf($formula) !== $purpose || $formula->operand_line_id === null) {
                continue;
            }

            $operandLineId = (int) $formula->operand_line_id;

            if (array_key_exists($operandLineId, $lineValues) && $lineValues[$operandLineId] === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * In a consolidated column, a line's consolidation formulas (when defined)
     * replace its value formulas; everywhere else the value formulas apply.
     */
    protected function formulaPurposeFor(ReportLine $line, ReportColumnSpec $column): FormulaPurpose
    {
        if (! $column->isConsolidated) {
            return FormulaPurpose::VALUE;
        }

        $hasConsolidationFormulas = $line->formulas->contains(
            fn ($formula): bool => $this->purposeOf($formula) === FormulaPurpose::CONSOLIDATION,
        );

        return $hasConsolidationFormulas ? FormulaPurpose::CONSOLIDATION : FormulaPurpose::VALUE;
    }

    /**
     * A formula operand's purpose; operands without one count as `value`.
     */
    protected function purposeOf($formula): FormulaPurpose
    {
        $purpose = $formula->purpose;

        if ($purpose === null) {
            return FormulaPurpose::VALUE;
        }

        return $purpose instanceof FormulaPurpose
            ? $purpose
            : FormulaPurpose::from((string) $purpose);
    }

    protected function applyLineSign(ReportLine $line, ?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return $value * ((int) $line->sign === -1 ? -1.0 : 1.0);
    }

    protected function groupKey(ReportContext $context, ValueBasis $basis): string
    {
        return $basis->value.'|'.implode(',', $context->companyIds).'|'.($context->postedOnly ? '1' : '0');
    }

    /**
     * Order formula lines so that any formula line referenced by another is
     * evaluated first. Falls back to `sort` order for independent lines.
     *
     * @param  Collection<int, ReportLine>  $formulaLines
     * @param  Collection<int, ReportLine>  $allLines
     * @return array<int, ReportLine>
     */
    protected function topologicalOrder(Collection $formulaLines, Collection $allLines): array
    {
        $byId = [];
        foreach ($allLines as $line) {
            $byId[(int) $line->id] = $line;
        }

        $computedIds = $formulaLines->map(fn (ReportLine $l) => (int) $l->id)->all();
        $computedSet = array_flip($computedIds);

        $ordered = [];
        $state = []; // 0/absent = unvisited, 1 = visiting, 2 = done

        $visit = function (int $lineId) use (&$visit, &$ordered, &$state, $byId, $computedSet): void {
            if (($state[$lineId] ?? 0) === 2) {
                return;
            }

            $state[$lineId] = 1;

            $line = $byId[$lineId] ?? null;

            if ($line !== null) {
                $line->loadMissing('formulas');

                foreach ($line->formulas as $formula) {
                    $operandLineId = $formula->operand_line_id !== null ? (int) $formula->operand_line_id : null;

                    // Only recurse into operands that are themselves computed.
                    if ($operandLineId !== null && isset($computedSet[$operandLineId])) {
                        $visit($operandLineId);
                    }
                }

                if (isset($computedSet[$lineId])) {
                    $ordered[] = $line;
                }
            }

            $state[$lineId] = 2;
        };

        // Visit in sort order for deterministic output.
        foreach ($formulaLines->sortBy('sort')->values() as $line) {
            $visit((int) $line->id);
        }

        return $ordered;
    }
}
