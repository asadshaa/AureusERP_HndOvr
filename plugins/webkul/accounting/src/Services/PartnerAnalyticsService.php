<?php

namespace Webkul\Accounting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;

final class PartnerAnalyticsService
{
    public function __construct(
        private readonly AgedPartnerBalanceService $agedBalances,
    ) {}

    /**
     * Document analytics for the period plus, from the aged ledger balance
     * as of the period end, 0–30/31–60/61–90/90+ ageing buckets and DSO
     * (customers) or DPO (vendors).
     *
     * @return array<string, mixed>
     */
    public function summary(int $companyId, string $partyType, string $dateFrom, string $dateTo): array
    {
        if (! in_array($partyType, ['customer', 'vendor'], true)) {
            throw new InvalidArgumentException('Party analytics type must be customer or vendor.');
        }

        $from = CarbonImmutable::parse($dateFrom)->startOfDay();
        $to = CarbonImmutable::parse($dateTo)->endOfDay();
        if ($from->isAfter($to)) {
            throw new InvalidArgumentException('Analytics start date must not be after the end date.');
        }

        [$documentType, $refundType] = $partyType === 'customer'
            ? [MoveType::OUT_INVOICE->value, MoveType::OUT_REFUND->value]
            : [MoveType::IN_INVOICE->value, MoveType::IN_REFUND->value];
        $base = $this->documentQuery($companyId, $from->toDateString(), $to->toDateString(), [$documentType, $refundType]);
        $totals = (clone $base)->selectRaw(
            'COUNT(*) AS document_count,
             COUNT(DISTINCT partner_id) AS party_count,
             COALESCE(SUM(CASE WHEN move_type = ? THEN -amount_total ELSE amount_total END), 0) AS document_value,
             COALESCE(SUM(CASE WHEN move_type = ? THEN -amount_residual ELSE amount_residual END), 0) AS outstanding,
             COALESCE(SUM(CASE WHEN invoice_date_due < ? THEN CASE WHEN move_type = ? THEN -amount_residual ELSE amount_residual END ELSE 0 END), 0) AS overdue',
            [$refundType, $refundType, $to->toDateString(), $refundType],
        )->first();
        $top = (clone $base)
            ->join('partners_partners as partners', 'partners.id', '=', 'accounts_account_moves.partner_id')
            ->selectRaw(
                'accounts_account_moves.partner_id, partners.name,
                 COUNT(*) AS document_count,
                 SUM(CASE WHEN move_type = ? THEN -amount_total ELSE amount_total END) AS document_value,
                 SUM(CASE WHEN move_type = ? THEN -amount_residual ELSE amount_residual END) AS outstanding',
                [$refundType, $refundType],
            )
            ->groupBy('accounts_account_moves.partner_id', 'partners.name')
            ->orderByDesc('document_value')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'partner_id'     => (int) $row->partner_id,
                'name'           => (string) $row->name,
                'document_count' => (int) $row->document_count,
                'document_value' => (float) $row->document_value,
                'outstanding'    => (float) $row->outstanding,
            ])
            ->all();
        $trends = (clone $base)
            ->selectRaw(
                "DATE_FORMAT(invoice_date, '%Y-%m') AS period,
                 COUNT(*) AS document_count,
                 SUM(CASE WHEN move_type = ? THEN -amount_total ELSE amount_total END) AS document_value",
                [$refundType],
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row): array => [
                'period'         => (string) $row->period,
                'document_count' => (int) $row->document_count,
                'document_value' => (float) $row->document_value,
            ])
            ->all();
        $payments = DB::table('accounting_bank_transaction_mappings as mappings')
            ->join('accounts_bank_statement_lines as bank_lines', 'bank_lines.id', '=', 'mappings.statement_line_id')
            ->join('accounts_account_moves as documents', 'documents.id', '=', 'mappings.matched_move_id')
            ->where('mappings.company_id', $companyId)
            ->where('mappings.posting_status', 'posted')
            ->whereIn('documents.move_type', [$documentType, $refundType])
            ->whereBetween('bank_lines.transaction_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) AS payment_count, COALESCE(SUM(ABS(bank_lines.company_signed_amount)), 0) AS payment_value, MAX(bank_lines.transaction_date) AS last_payment_date')
            ->first();

        $documentValue = (float) ($totals->document_value ?? 0);
        $outstanding = (float) ($totals->outstanding ?? 0);
        $overdue = (float) ($totals->overdue ?? 0);
        $topValue = (float) ($top[0]['document_value'] ?? 0);

        $aging = $this->aging($companyId, $partyType, $to);
        $periodDays = (int) $from->diffInDays($to->startOfDay()) + 1;
        // Days outstanding = open balance at period end / period document
        // value (credit sales or purchases) x days in the period.
        $daysOutstanding = $documentValue > 0
            ? round(($aging['total'] / $documentValue) * $periodDays, 1)
            : null;

        return [
            'party_type'        => $partyType,
            'date_from'         => $from->toDateString(),
            'date_to'           => $to->toDateString(),
            'party_count'       => (int) ($totals->party_count ?? 0),
            'document_count'    => (int) ($totals->document_count ?? 0),
            'document_value'    => $documentValue,
            'outstanding'       => $outstanding,
            'overdue'           => $overdue,
            'overdue_rate'      => $outstanding > 0 ? round(($overdue / $outstanding) * 100, 2) : 0.0,
            'payment_count'     => (int) ($payments->payment_count ?? 0),
            'payment_value'     => (float) ($payments->payment_value ?? 0),
            'last_payment_date' => $payments->last_payment_date ?? null,
            'top_concentration' => $documentValue > 0 ? round(($topValue / $documentValue) * 100, 2) : 0.0,
            'top_parties'       => $top,
            'trends'            => $trends,
            'aging'             => $aging,
            'aging_as_of'       => $to->toDateString(),
            'dso'               => $partyType === 'customer' ? $daysOutstanding : null,
            'dpo'               => $partyType === 'vendor' ? $daysOutstanding : null,
        ];
    }

    /**
     * Open receivable (customers) or payable (vendors) items as of the period
     * end, bucketed by days past due. Same source and as-of rules as the
     * Aged Receivable / Aged Payable reports, so the totals agree.
     *
     * @return array{current: float, '0_30': float, '31_60': float, '61_90': float, '90_plus': float, total: float}
     */
    private function aging(int $companyId, string $partyType, CarbonImmutable $asOf): array
    {
        $buckets = ['current' => 0.0, '0_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0, 'total' => 0.0];

        $items = $this->agedBalances->openItems(
            $companyId,
            $partyType === 'customer' ? AgedPartnerBalanceService::RECEIVABLE : AgedPartnerBalanceService::PAYABLE,
            $asOf->startOfDay(),
        );

        foreach ($items as $item) {
            $bucket = match (true) {
                $item['days_overdue'] < 0   => 'current',
                $item['days_overdue'] <= 30 => '0_30',
                $item['days_overdue'] <= 60 => '31_60',
                $item['days_overdue'] <= 90 => '61_90',
                default                     => '90_plus',
            };

            $buckets[$bucket] += $item['amount'];
            $buckets['total'] += $item['amount'];
        }

        return array_map(fn (float $amount): float => round($amount, 2) + 0.0, $buckets);
    }

    /** @param array<int, string> $moveTypes */
    private function documentQuery(int $companyId, string $dateFrom, string $dateTo, array $moveTypes): Builder
    {
        return Move::query()
            ->where('accounts_account_moves.company_id', $companyId)
            ->where('state', MoveState::POSTED)
            ->whereIn('move_type', $moveTypes)
            ->whereNotNull('partner_id')
            ->whereBetween('invoice_date', [$dateFrom, $dateTo]);
    }
}
