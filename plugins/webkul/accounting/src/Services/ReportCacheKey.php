<?php

namespace Webkul\Accounting\Services;

use Webkul\Accounting\Data\ReportColumnSpec;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Data\ReportPeriod;
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Models\ReportTemplate;

/**
 * Builds a deterministic cache key for a report run.
 *
 * The key incorporates the template identity and its version and updated_at, so
 * any edit to the template (layout, lines, formulas — all of which touch the
 * template's updated_at via the designer save flow) naturally yields a new key
 * and invalidates stale results. Period and company scope are included so
 * different views of the same template cache independently.
 *
 * The viewer's authorised companies are part of the key (they decide which
 * entity columns/lines are available), so a run cached for one user can never
 * be served to a user with fewer companies. A ledger stamp (LedgerVersion)
 * rotates the key as soon as the ledger changes, so new postings and
 * reversals show immediately instead of after the cache TTL.
 */
class ReportCacheKey
{
    /**
     * @param  array<int, ReportPeriod>  $periods
     */
    public static function for(ReportTemplate $template, array $periods, ReportContext $context, bool $cumulative): string
    {
        $periodPart = implode('|', array_map(
            fn (ReportPeriod $p) => $p->key.':'.$p->startDate->toDateString().':'.$p->endDate->toDateString(),
            $periods,
        ));

        $companyPart = implode(',', $context->companyIds);

        $stamp = optional($template->updated_at)->getTimestamp() ?? 0;

        $raw = implode(';', [
            'tpl='.$template->id,
            'ver='.$template->version,
            'upd='.$stamp,
            'cum='.($cumulative ? '1' : '0'),
            'posted='.($context->postedOnly ? '1' : '0'),
            'companies='.$companyPart,
            'authorized='.self::authorizedPart($context),
            'original_currency='.($context->originalCurrencyId ?? '-'),
            'periods='.$periodPart,
        ]);

        return 'accounting:report:'.md5($raw);
    }

    /**
     * Column-based variant used by the Stage 3.5 engine: the key covers every
     * resolved column (period, scope, consolidation) plus the default basis, so
     * two runs differing in any column input cache independently. Template
     * edits (lines, formulas, bindings, columns, manual inputs) all touch the
     * template's updated_at and therefore rotate the key automatically.
     *
     * @param  array<int, ReportColumnSpec>  $columns
     */
    public static function forColumns(ReportTemplate $template, array $columns, ReportContext $context, ValueBasis $defaultBasis, string $ledgerStamp = ''): string
    {
        $columnPart = implode('|', array_map(
            function (ReportColumnSpec $column) {
                $period = $column->period;

                return implode(':', [
                    $column->key,
                    $period?->startDate->toDateString() ?? '-',
                    $period?->endDate->toDateString() ?? '-',
                    $column->companyIds !== null ? implode('.', $column->companyIds) : '-',
                    $column->isConsolidated ? '1' : '0',
                ]);
            },
            $columns,
        ));

        $companyPart = implode(',', $context->companyIds);

        $stamp = optional($template->updated_at)->getTimestamp() ?? 0;

        $raw = implode(';', [
            'tpl='.$template->id,
            'ver='.$template->version,
            'upd='.$stamp,
            'basis='.$defaultBasis->value,
            'posted='.($context->postedOnly ? '1' : '0'),
            'companies='.$companyPart,
            'authorized='.self::authorizedPart($context),
            'original_currency='.($context->originalCurrencyId ?? '-'),
            'columns='.$columnPart,
            'ledger='.$ledgerStamp,
        ]);

        return 'accounting:report:'.md5($raw);
    }

    protected static function authorizedPart(ReportContext $context): string
    {
        if ($context->authorizedCompanyIds === null) {
            return '*';
        }

        $authorized = $context->authorizedCompanyIds;
        sort($authorized);

        return implode(',', $authorized);
    }
}
