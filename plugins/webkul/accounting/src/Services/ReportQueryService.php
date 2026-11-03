<?php

namespace Webkul\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Webkul\Accounting\Data\ReportColumnSpec;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportLineValue;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Enums\LayoutType;
use Webkul\Accounting\Enums\LineType;
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Models\ReportTemplate;

/**
 * Public entry point for producing a rendered report.
 *
 * Responsibilities:
 *   - resolve the template's column set (explicit definitions, or the
 *     layout_type defaults for templates without any)
 *   - derive the default value basis from the layout (per-line overrides win)
 *   - run the calculation engine
 *   - cache the (pure) result and invalidate it when the template changes
 *     or the ledger of any company the run reads changes (LedgerVersion)
 *
 * The UI (Stage 4 designer preview) and the report/export pages (Stage 5) both
 * call this service, so no ledger or formula logic ever lives in a Filament
 * page.
 */
class ReportQueryService
{
    /**
     * Default number of seconds a computed report is cached.
     */
    public int $cacheTtlSeconds = 900;

    public function __construct(
        protected ReportCalculationEngine $engine,
        protected ReportColumnResolver $columns,
        protected LedgerVersion $ledgerVersion,
    ) {}

    /**
     * Produce the report values for a template and year, scoped by context.
     *
     * @return Collection<int, ReportLineValue>
     */
    public function getReport(ReportTemplate $template, int $year, ReportContext $context, bool $useCache = true): Collection
    {
        $columns = $this->columnsFor($template, $year, $context);
        $defaultBasis = $this->defaultBasisFor($template);

        if (! $useCache) {
            return $this->engine->calculateForColumns($template, $columns, $context, $defaultBasis);
        }

        $key = ReportCacheKey::forColumns($template, $columns, $context, $defaultBasis, $this->ledgerStamp($template, $columns, $context));

        /** @var array<int, array<string, mixed>> $cached */
        $cached = Cache::remember($key, $this->cacheTtlSeconds, function () use ($template, $columns, $context, $defaultBasis) {
            return $this->engine
                ->calculateForColumns($template, $columns, $context, $defaultBasis)
                ->map(fn (ReportLineValue $v) => $v->toArray())
                ->all();
        });

        return $this->hydrate($cached);
    }

    /**
     * Uncached convenience used by the designer preview.
     *
     * @return Collection<int, ReportLineValue>
     */
    public function previewReport(ReportTemplate $template, int $year, ReportContext $context): Collection
    {
        return $this->getReport($template, $year, $context, useCache: false);
    }

    /**
     * The resolved column specs for a run — what a page or export iterates to
     * render the horizontal axis (including spacer columns).
     *
     * @return array<int, ReportColumnSpec>
     */
    public function columnsFor(ReportTemplate $template, int $year, ReportContext $context): array
    {
        return $this->columns->resolve($template, $year, $context);
    }

    /**
     * Proactively dropping a cached run is normally unnecessary — the cache key
     * includes the template's updated_at, so any saved change yields a new key
     * automatically. This remains for callers who want to force a drop.
     */
    public function forget(ReportTemplate $template, int $year, ReportContext $context): void
    {
        $columns = $this->columnsFor($template, $year, $context);

        Cache::forget(ReportCacheKey::forColumns(
            $template,
            $columns,
            $context,
            $this->defaultBasisFor($template),
            $this->ledgerStamp($template, $columns, $context),
        ));
    }

    /**
     * The ledger version of every company the run can read: the run scope
     * plus the (authorised) entity overrides of its columns and lines. An
     * unscoped, unrestricted run reads every company and stamps globally.
     *
     * @param  array<int, ReportColumnSpec>  $columns
     */
    protected function ledgerStamp(ReportTemplate $template, array $columns, ReportContext $context): string
    {
        if (! $context->hasCompanyScope()) {
            return $this->ledgerVersion->stamp([], $context->postedOnly);
        }

        $companyIds = $context->companyIds;

        foreach ($columns as $column) {
            if (! $column->isSpacer()) {
                $companyIds = [...$companyIds, ...($column->contextFor($context)?->companyIds ?? [])];
            }
        }

        $lineCompanyIds = $template->lines()->whereNotNull('company_id')->distinct()->pluck('company_id');

        foreach ($lineCompanyIds as $lineCompanyId) {
            $companyIds = [...$companyIds, ...($context->narrowTo([(int) $lineCompanyId])?->companyIds ?? [])];
        }

        if ($companyIds === []) {
            return 'none';
        }

        return $this->ledgerVersion->stamp($companyIds, $context->postedOnly);
    }

    /**
     * Derive the period columns from the template layout.
     *
     * Retained for callers that reason in raw periods; the engine itself now
     * consumes resolved columns (see columnsFor()).
     *
     * @return array<int, ReportPeriod>
     */
    public function periodsFor(ReportTemplate $template, int $year): array
    {
        $layout = $template->layout_type instanceof LayoutType
            ? $template->layout_type
            : LayoutType::from((string) $template->layout_type);

        if ($layout === LayoutType::MONTHLY_MATRIX) {
            $months = ReportPeriod::monthsOfYear($year);
            $months[] = ReportPeriod::fullYear($year);

            return $months;
        }

        // period_total: a single full-year column.
        return [ReportPeriod::fullYear($year)];
    }

    /**
     * The report-level default basis, derived from the layout: a period_total
     * layout reads point-in-time closing balances (balance-sheet style), a
     * monthly_matrix reads period movements (P&L style). Any line can override
     * this via its own value_basis, which is how a cashflow statement mixes
     * opening-balance, movement and closing-balance rows in one report.
     */
    public function defaultBasisFor(ReportTemplate $template): ValueBasis
    {
        $layout = $template->layout_type instanceof LayoutType
            ? $template->layout_type
            : LayoutType::from((string) $template->layout_type);

        return $layout === LayoutType::PERIOD_TOTAL
            ? ValueBasis::CLOSING_BALANCE
            : ValueBasis::MOVEMENT;
    }

    /**
     * Rebuild ReportLineValue objects from a cached array payload.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, ReportLineValue>
     */
    protected function hydrate(array $rows): Collection
    {
        return collect($rows)->map(function (array $row) {
            return new ReportLineValue(
                lineId: (int) $row['line_id'],
                parentId: $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                lineType: LineType::from((string) $row['line_type']),
                caption: $row['caption'] !== null ? (string) $row['caption'] : null,
                code: $row['code'] !== null ? (string) $row['code'] : null,
                isVisible: (bool) $row['is_visible'],
                isBold: (bool) $row['is_bold'],
                indentLevel: (int) $row['indent_level'],
                sort: (int) $row['sort'],
                values: array_map(fn ($value): ?float => $value === null ? null : (float) $value, $row['values'] ?? []),
                isCheck: (bool) ($row['is_check'] ?? false),
            );
        })->values();
    }
}
