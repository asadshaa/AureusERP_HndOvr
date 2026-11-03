<?php

namespace Webkul\Accounting\Services\Currency;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Exceptions\MissingExchangeRateException;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class FinancialStatementCurrencyService
{
    public function __construct(protected ExchangeRateService $exchangeRates) {}

    /**
     * @param  array<int, int>  $journalIds
     * @return array<string, Collection<int, object>> currency code => balances keyed by account ID
     */
    public function originalBalances(
        Company $company,
        ?string $dateFrom,
        string $dateTo,
        array $journalIds = [],
    ): array {
        $currencyExpression = 'COALESCE(lines.original_currency_id, lines.currency_id, moves.original_currency_id, moves.currency_id, '.(int) $company->currency_id.')';
        $query = DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $company->id)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereDate('moves.date', '<=', $dateTo)
            ->selectRaw("{$currencyExpression} currency_id, lines.account_id, SUM(COALESCE(lines.original_debit, lines.debit, 0) - COALESCE(lines.original_credit, lines.credit, 0)) balance")
            ->groupByRaw("{$currencyExpression}, lines.account_id");

        if ($dateFrom !== null) {
            $query->whereDate('moves.date', '>=', $dateFrom);
        }
        if ($journalIds !== []) {
            $query->whereIn('moves.journal_id', $journalIds);
        }

        $rows = $query->get();
        $currencies = Currency::query()->whereKey($rows->pluck('currency_id')->unique())->get()->keyBy('id');

        return $rows->groupBy('currency_id')->mapWithKeys(function (Collection $balances, int|string $currencyId) use ($currencies): array {
            $currency = $currencies->get((int) $currencyId);
            $label = (string) ($currency?->code ?: $currency?->name ?: "Currency {$currencyId}");

            return [$label => $balances->keyBy('account_id')];
        })->sortKeys()->all();
    }

    /**
     * Translate posted company-currency movements before aggregating accounts.
     *
     * @param  array<int, int>  $journalIds
     * @return array{balances: Collection<int, object>, warnings: array<int, string>, status: string, rate_basis: string}
     */
    public function reportingMovementBalances(
        Company $company,
        Currency $targetCurrency,
        string $dateFrom,
        string $dateTo,
        array $journalIds = [],
    ): array {
        $policy = $company->pnl_translation_policy ?: 'transaction_date';
        $query = DB::table('accounts_account_move_lines as lines')
            ->join('accounts_account_moves as moves', 'moves.id', '=', 'lines.move_id')
            ->where('moves.company_id', $company->id)
            ->where('moves.state', MoveState::POSTED->value)
            ->whereBetween('moves.date', [$dateFrom, $dateTo])
            ->selectRaw('lines.account_id, moves.date, SUM(COALESCE(lines.company_debit, lines.debit, 0) - COALESCE(lines.company_credit, lines.credit, 0)) balance')
            ->groupBy('lines.account_id', 'moves.date');

        if ($journalIds !== []) {
            $query->whereIn('moves.journal_id', $journalIds);
        }

        $balances = [];
        $warnings = [];
        foreach ($query->get() as $row) {
            $rateDate = $policy === 'monthly_average'
                ? Carbon::parse($row->date)->endOfMonth()->toDateString()
                : Carbon::parse($row->date)->toDateString();
            $types = $policy === 'monthly_average'
                ? [ExchangeRateType::MonthlyAverage]
                : [ExchangeRateType::Transaction, ExchangeRateType::Daily];

            try {
                $rate = $this->exchangeRates->resolve(
                    $company,
                    $company->currency,
                    $targetCurrency,
                    $rateDate,
                    $types,
                );
                $converted = BigDecimal::of((string) $row->balance)->multipliedBy($rate->rate);
                $balances[(int) $row->account_id] = ($balances[(int) $row->account_id] ?? BigDecimal::zero())->plus($converted);
            } catch (MissingExchangeRateException $exception) {
                $warnings[$exception->getMessage()] = $exception->getMessage();
            }
        }

        return [
            'balances' => collect($balances)->map(fn (BigDecimal $balance): object => (object) [
                'balance' => $balance->toScale(4, RoundingMode::HalfUp)->__toString(),
            ]),
            'warnings'   => array_values($warnings),
            'status'     => $warnings === [] ? 'complete' : 'incomplete',
            'rate_basis' => $policy === 'monthly_average'
                ? 'Approved monthly-average rate for each transaction month.'
                : 'Approved transaction-date or daily rate for each posted date.',
        ];
    }

    /**
     * @return array{rate: string|null, warnings: array<int, string>, status: string, rate_basis: string}
     */
    public function reportingClosingRate(Company $company, Currency $targetCurrency, string $date): array
    {
        try {
            $rate = $this->exchangeRates->resolve(
                $company,
                $company->currency,
                $targetCurrency,
                $date,
                [ExchangeRateType::PeriodClosing],
                false,
            );

            return [
                'rate'       => $rate->rate,
                'warnings'   => [],
                'status'     => 'complete',
                'rate_basis' => "Approved period-closing rate dated {$rate->effectiveDate}.",
            ];
        } catch (MissingExchangeRateException $exception) {
            return [
                'rate'       => null,
                'warnings'   => [$exception->getMessage()],
                'status'     => 'incomplete',
                'rate_basis' => 'Approved period-closing rate required; missing values are not translated.',
            ];
        }
    }

    public function scaleStatement(array $statement, string $rate): array
    {
        foreach ($statement['sections'] as &$section) {
            foreach ($section['subsections'] ?? [] as &$subsection) {
                foreach ($subsection['accounts'] ?? [] as &$account) {
                    $account['balance'] = $this->scale($account['balance'], $rate);
                }
                if (array_key_exists('total', $subsection)) {
                    $subsection['total'] = $this->scale($subsection['total'], $rate);
                }
            }
            foreach ($section['accounts'] ?? [] as &$account) {
                $account['balance'] = $this->scale($account['balance'], $rate);
            }
            $section['total'] = $this->scale($section['total'], $rate);
        }
        unset($section, $subsection, $account);

        foreach (['grand_total', 'net_income'] as $field) {
            if (array_key_exists($field, $statement)) {
                $statement[$field] = $this->scale($statement[$field], $rate);
            }
        }

        foreach (['assets', 'unclassified', 'liabilities_and_equity', 'difference'] as $field) {
            if (isset($statement['balance_check'][$field])) {
                $statement['balance_check'][$field] = $this->scale($statement['balance_check'][$field], $rate);
            }
        }

        return $statement;
    }

    private function scale(string|int|float $amount, string $rate): float
    {
        return (float) BigDecimal::of((string) $amount)
            ->multipliedBy($rate)
            ->toScale(4, RoundingMode::HalfUp)
            ->__toString();
    }
}
