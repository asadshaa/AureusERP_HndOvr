<?php

namespace Webkul\Accounting\Data;

/**
 * A fully resolved report column: the unit of the horizontal axis of a report.
 *
 * Column definitions (accounting_report_columns) are relative — "month 6",
 * "full year", "prior year" — and are resolved against a run year and base
 * context into these immutable specs. Templates without column definitions get
 * a default set derived from their layout_type, which preserves the original
 * Stage 3 behaviour (twelve months + total, or a single full-year column).
 */
final class ReportColumnSpec
{
    /**
     * @param  array<int, int>|null  $companyIds  entity scope override (null = use the run context)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?ReportPeriod $period,
        public readonly ?array $companyIds = null,
        public readonly bool $isConsolidated = false,
    ) {}

    public static function spacer(string $key): self
    {
        return new self($key, '', null);
    }

    public function isSpacer(): bool
    {
        return $this->period === null;
    }

    /**
     * The context this column's values are computed under. An entity override
     * narrows the scope to that entity — but only within the companies the
     * viewer is authorised for; null means the column is not available to
     * this viewer and must never carry figures. A consolidated column (and a
     * plain column) uses the full run scope.
     */
    public function contextFor(ReportContext $base): ?ReportContext
    {
        if ($this->companyIds !== null && $this->companyIds !== []) {
            return $base->narrowTo($this->companyIds);
        }

        return $base;
    }

    public function isAvailableIn(ReportContext $base): bool
    {
        return $this->isSpacer() || $this->contextFor($base) !== null;
    }

    public function toArray(): array
    {
        return [
            'key'             => $this->key,
            'label'           => $this->label,
            'period'          => $this->period?->toArray(),
            'company_ids'     => $this->companyIds,
            'is_consolidated' => $this->isConsolidated,
        ];
    }
}
