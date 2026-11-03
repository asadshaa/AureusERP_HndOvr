<?php

namespace Webkul\Accounting\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;

/**
 * Open receivable / payable journal items as they stood at the end of an
 * as-of date — the single source for the Aged Receivable and Aged Payable
 * reports and the Partner Analytics ageing buckets.
 *
 * "As of" means:
 *   - only items whose move is dated on or before the as-of date count;
 *   - each item's residual is its residual at that date: the current
 *     amount_residual with every reconciliation dated after the as-of date
 *     (partial reconcile max_date) added back — a payment received later did
 *     not exist yet;
 *   - ageing (days overdue) is measured from the item's due date (or invoice
 *     date) to the as-of date, not to today.
 *
 * Amounts are returned in the report's direction: positive = owed to us for
 * receivables, positive = owed by us for payables.
 */
class AgedPartnerBalanceService
{
    public const RECEIVABLE = 'receivable';

    public const PAYABLE = 'payable';

    public const BASIS_DUE_DATE = 'due_date';

    public const BASIS_INVOICE_DATE = 'invoice_date';

    /**
     * @param  array{journal_ids?: array<int, int>, partner_ids?: array<int, int>, posted_only?: bool, basis?: string}  $filters
     * @return Collection<int, array{line_id: int, partner_id: int, partner_name: ?string, move_name: ?string, invoice_date: ?string, invoice_date_due: ?string, reference: ?string, journal_name: ?string, amount: float, days_overdue: int}>
     */
    public function openItems(int $companyId, string $side, CarbonInterface $asOf, array $filters = []): Collection
    {
        $accountType = match ($side) {
            self::RECEIVABLE => AccountType::ASSET_RECEIVABLE->value,
            self::PAYABLE    => AccountType::LIABILITY_PAYABLE->value,
            default          => throw new InvalidArgumentException("Unknown aged balance side [{$side}]."),
        };

        $asOfDate = $asOf->toDateString();
        $basis = $filters['basis'] ?? self::BASIS_DUE_DATE;
        $direction = $side === self::RECEIVABLE ? 1.0 : -1.0;

        $query = DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->join('accounts_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->leftJoin('accounts_journals as journals', 'journals.id', '=', 'moves.journal_id')
            ->leftJoin('partners_partners as partners', 'partners.id', '=', 'lines.partner_id')
            ->select([
                'lines.id as line_id',
                'lines.partner_id',
                'partners.name as partner_name',
                'moves.name as move_name',
                'moves.date as move_date',
                'moves.invoice_date',
                'moves.invoice_date_due',
                'lines.date_maturity',
                'moves.reference',
                'journals.name as journal_name',
                'lines.amount_residual',
            ])
            ->selectSub($this->reconciledAfter('debit_move_id', $asOfDate), 'reconciled_after_as_debit')
            ->selectSub($this->reconciledAfter('credit_move_id', $asOfDate), 'reconciled_after_as_credit')
            ->where('accounts.account_type', $accountType)
            ->where('moves.company_id', $companyId)
            ->where('moves.date', '<=', $asOfDate)
            ->whereNotNull('lines.partner_id')
            // Open now, or reconciled only after the as-of date (open then).
            ->where(fn (Builder $open) => $open
                ->where('lines.amount_residual', '!=', 0)
                ->orWhereExists(fn (Builder $later) => $later
                    ->selectRaw('1')
                    ->from('accounts_partial_reconciles as later_partials')
                    ->where(fn (Builder $sides) => $sides
                        ->whereColumn('later_partials.debit_move_id', 'lines.id')
                        ->orWhereColumn('later_partials.credit_move_id', 'lines.id'))
                    ->where('later_partials.max_date', '>', $asOfDate)))
            ->orderBy('partners.name')
            ->orderBy('moves.invoice_date')
            ->orderBy('lines.id');

        if ($filters['posted_only'] ?? true) {
            $query->where('moves.state', MoveState::POSTED->value);
        }

        if (! empty($filters['journal_ids'])) {
            $query->whereIn('moves.journal_id', $filters['journal_ids']);
        }

        if (! empty($filters['partner_ids'])) {
            $query->whereIn('lines.partner_id', $filters['partner_ids']);
        }

        return $query->get()
            ->map(function (object $row) use ($asOf, $basis, $direction): ?array {
                // Residual at the as-of date: a debit item's later
                // reconciliations reduced it since, a credit item's increased it.
                $residual = (float) $row->amount_residual
                    + (float) $row->reconciled_after_as_debit
                    - (float) $row->reconciled_after_as_credit;

                if (abs($residual) < 0.005) {
                    return null;
                }

                return [
                    'line_id'          => (int) $row->line_id,
                    'partner_id'       => (int) $row->partner_id,
                    'partner_name'     => $row->partner_name,
                    'move_name'        => $row->move_name,
                    'invoice_date'     => $row->invoice_date,
                    'invoice_date_due' => $row->invoice_date_due,
                    'reference'        => $row->reference,
                    'journal_name'     => $row->journal_name,
                    'amount'           => round($direction * $residual, 4) + 0.0,
                    'days_overdue'     => $this->daysOverdue($row, $basis, $asOf),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * The ageing bucket for an item: not yet due ("at_date"), then four
     * periods of $period days, then "older".
     */
    public static function bucketFor(int $daysOverdue, int $period): string
    {
        return match (true) {
            $daysOverdue < 0            => 'at_date',
            $daysOverdue <= $period     => 'period_1',
            $daysOverdue <= $period * 2 => 'period_2',
            $daysOverdue <= $period * 3 => 'period_3',
            $daysOverdue <= $period * 4 => 'period_4',
            default                     => 'older',
        };
    }

    /**
     * Days between the item's reference date and the as-of date (negative =
     * not yet due). Items without a due/invoice date (plain journal entries)
     * fall back to the line maturity and then the accounting date.
     */
    protected function daysOverdue(object $row, string $basis, CarbonInterface $asOf): int
    {
        $reference = $basis === self::BASIS_INVOICE_DATE
            ? ($row->invoice_date ?? $row->move_date)
            : ($row->invoice_date_due ?? $row->date_maturity ?? $row->move_date);

        return (int) Carbon::parse($reference)->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false);
    }

    protected function reconciledAfter(string $sideColumn, string $asOfDate): Builder
    {
        return DB::table('accounts_partial_reconciles as partials')
            ->selectRaw('COALESCE(SUM(partials.amount), 0)')
            ->whereColumn("partials.{$sideColumn}", 'lines.id')
            ->where('partials.max_date', '>', $asOfDate);
    }
}
