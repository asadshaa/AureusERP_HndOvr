<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;
use Malzariey\FilamentDaterangepickerFilter\Fields\DateRangePicker;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Enums\ReportCurrencyMode;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Concerns\NormalizeDateFilter;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\ProfitAndLossExport;
use Webkul\Accounting\Services\Currency\FinancialStatementCurrencyService;
use Webkul\Accounting\Services\ReportCompletenessService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class ProfitLoss extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms, NormalizeDateFilter;

    protected string $view = 'accounting::filament.clusters.reporting.pages.profit-loss';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 92;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_profit_loss';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/reporting.pages.profit-loss.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/reporting.pages.profit-loss.navigation.title');
    }

    public function getTitle(): string
    {
        return __('accounting::filament/clusters/reporting.pages.profit-loss.navigation.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'currency_mode'         => ReportCurrencyMode::Company->value,
            'reporting_currency_id' => null,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label(__('accounting::filament/clusters/reporting.pages.profit-loss.actions.export-excel'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->action(function () {
                    $data = $this->profitLossData;
                    $dateFrom = Carbon::parse($data['date_from']);
                    $dateTo = Carbon::parse($data['date_to']);
                    $filename = 'profit-loss-'.$dateFrom->format('Y-m-d').'-to-'.$dateTo->format('Y-m-d').'.xlsx';

                    return Excel::download(new ProfitAndLossExport($data, $dateFrom, $dateTo), $filename);
                }),
            Action::make('pdf')
                ->label(__('accounting::filament/clusters/reporting.pages.profit-loss.actions.export-pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('danger')
                ->action(function () {
                    $data = $this->profitLossData;
                    $dateFrom = Carbon::parse($data['date_from']);
                    $dateTo = Carbon::parse($data['date_to']);
                    $filename = 'profit-loss-'.$dateFrom->format('Y-m-d').'-to-'.$dateTo->format('Y-m-d').'.pdf';

                    $pdf = Pdf::loadView('accounting::filament.clusters.reporting.pages.pdfs.profit-loss', ['data' => $data]);

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, $filename);
                }),
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make()
                ->columns([
                    'default' => 1,
                    'sm'      => 2,
                ])
                ->schema([
                    DateRangePicker::make('date_range')
                        ->label(__('accounting::filament/clusters/reporting.pages.profit-loss.filters.date-range'))
                        ->suffixIcon('heroicon-o-calendar')
                        ->defaultThisMonth()
                        ->ranges([
                            'Today'        => [now()->startOfDay(), now()->endOfDay()],
                            'Yesterday'    => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
                            'This Month'   => [now()->startOfMonth(), now()->endOfMonth()],
                            'Last Month'   => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
                            'This Quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
                            'Last Quarter' => [now()->subQuarter()->startOfQuarter(), now()->subQuarter()->endOfQuarter()],
                            'This Year'    => [now()->startOfYear(), now()->endOfYear()],
                            'Last Year'    => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
                        ])
                        ->alwaysShowCalendar()
                        ->live()
                        ->afterStateUpdated(fn () => null),
                    Select::make('journals')
                        ->label(__('accounting::filament/clusters/reporting.pages.profit-loss.filters.journals'))
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => Journal::query()->where('company_id', Auth::user()?->default_company_id)
                            ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                        ->getSearchResultsUsing(fn (string $search): array => Journal::query()
                            ->where('company_id', Auth::user()?->default_company_id)
                            ->where('name', 'like', "%{$search}%")
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values): array => Journal::query()
                            ->where('company_id', Auth::user()?->default_company_id)
                            ->whereKey($values)
                            ->pluck('name', 'id')
                            ->all())
                        ->live()
                        ->afterStateUpdated(fn () => null),
                    Select::make('currency_mode')
                        ->label('Currency mode')
                        ->options(ReportCurrencyMode::options())
                        ->default(ReportCurrencyMode::Company->value)
                        ->live(),
                    Select::make('reporting_currency_id')
                        ->label('Reporting currency')
                        ->visible(fn (Get $get): bool => $get('currency_mode') === ReportCurrencyMode::Reporting->value)
                        ->options(fn () => Currency::query()
                            ->whereHas('enabledCompanies', fn ($query) => $query
                                ->where('companies.id', Auth::user()?->default_company_id)
                                ->where('accounting_company_currencies.reporting_enabled', true))
                            ->orderBy('display_order')->get()
                            ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name]))
                        ->required(fn (Get $get): bool => $get('currency_mode') === ReportCurrencyMode::Reporting->value)
                        ->searchable()
                        ->live(),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    #[Computed]
    public function profitLossData(): array
    {
        $dateRange = $this->parseDateRange();
        $dateFrom = $dateRange ? Carbon::parse($dateRange[0]) : now()->startOfMonth();
        $dateTo = $dateRange ? Carbon::parse($dateRange[1]) : now()->endOfMonth();

        $companyId = Auth::user()->default_company_id;
        $journalIds = $this->data['journals'] ?? [];

        $query = MoveLine::query()
            ->select([
                'accounts_account_move_lines.account_id',
                DB::raw('SUM(accounts_account_move_lines.debit) as total_debit'),
                DB::raw('SUM(accounts_account_move_lines.credit) as total_credit'),
                DB::raw('SUM(accounts_account_move_lines.balance) as balance'),
            ])
            ->join('accounts_account_moves', 'accounts_account_moves.id', '=', 'accounts_account_move_lines.move_id')
            ->where('accounts_account_moves.company_id', $companyId)
            ->where('accounts_account_moves.state', MoveState::POSTED)
            ->whereBetween('accounts_account_moves.date', [$dateFrom, $dateTo])
            ->groupBy('accounts_account_move_lines.account_id');

        if (! empty($journalIds)) {
            $query->whereIn('accounts_account_moves.journal_id', $journalIds);
        }

        $balances = $query->get()->keyBy('account_id');

        $accounts = Account::query()
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->whereIn('account_type', array_merge(
                array_keys(AccountType::income()),
                array_keys(AccountType::expenses())
            ))
            ->get()
            ->keyBy('id');

        $company = Company::query()->with('currency')->findOrFail($companyId);
        $currencyMode = $this->authorizedCurrencyMode();
        $currencyService = app(FinancialStatementCurrencyService::class);

        if ($currencyMode === ReportCurrencyMode::Original->value) {
            $bundles = $currencyService->originalBalances(
                $company,
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
                $journalIds,
            );
            $reports = [];
            foreach ($bundles as $currencyCode => $currencyBalances) {
                $reports[$currencyCode] = $this->assembleProfitLoss($accounts, $currencyBalances, $dateFrom, $dateTo);
            }
            $statement = $reports === []
                ? $this->assembleProfitLoss($accounts, collect(), $dateFrom, $dateTo)
                : reset($reports);
            if ($reports === []) {
                $emptyCurrency = (string) ($company->currency?->code ?: $company->currency?->name ?: 'Company currency');
                $reports[$emptyCurrency] = $statement;
            }

            return array_merge($statement, [
                'reports'           => $reports,
                'currency_mode'     => $currencyMode,
                'currency'          => count($reports) === 1 ? array_key_first($reports) : 'Multiple currencies',
                'conversion_status' => 'complete',
                'rate_basis'        => 'Stored original debit and credit amounts, grouped separately by currency.',
                'warnings'          => [],
            ]);
        }

        $currencyCode = (string) ($company->currency?->code ?: $company->currency?->name ?: 'Company currency');
        $conversionStatus = 'complete';
        $rateBasis = 'Posted company-currency debit and credit fields; no translation.';
        $warnings = [];

        if ($currencyMode === ReportCurrencyMode::Reporting->value) {
            $targetCurrency = Currency::query()->findOrFail((int) ($this->data['reporting_currency_id'] ?? 0));
            abort_unless($company->enabledCurrencies()->where('currencies.id', $targetCurrency->id)->wherePivot('reporting_enabled', true)->exists(), 422);
            $translation = $currencyService->reportingMovementBalances(
                $company,
                $targetCurrency,
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
                $journalIds,
            );
            $balances = $translation['balances'];
            $conversionStatus = $translation['status'];
            $rateBasis = $translation['rate_basis'];
            $warnings = $translation['warnings'];
            $currencyCode = (string) ($targetCurrency->code ?: $targetCurrency->name);
        }

        $statement = $this->assembleProfitLoss($accounts, $balances, $dateFrom, $dateTo);

        return array_merge($statement, [
            'reports'           => [$currencyCode => $statement],
            'currency_mode'     => $currencyMode,
            'currency'          => $currencyCode,
            'conversion_status' => $conversionStatus,
            'rate_basis'        => $rateBasis,
            'warnings'          => $warnings,
        ]);
    }

    protected function assembleProfitLoss($accounts, $balances, Carbon $dateFrom, Carbon $dateTo): array
    {
        $revenue = $this->buildRevenueSection($accounts, $balances);
        $expenses = $this->buildExpenseSection($accounts, $balances);
        $netIncome = $revenue['total'] - $expenses['total'];

        return [
            'sections' => [
                [
                    'title'         => __('accounting::filament/clusters/reporting.pages.profit-loss.content.sections.revenue.title'),
                    'accounts'      => $revenue['accounts'],
                    'total_label'   => __('accounting::filament/clusters/reporting.pages.profit-loss.content.sections.revenue.total-label'),
                    'total'         => $revenue['total'],
                    'empty_message' => __('accounting::filament/clusters/reporting.pages.profit-loss.content.sections.revenue.empty-message'),
                ],
                [
                    'title'         => __('accounting::filament/clusters/reporting.pages.profit-loss.content.sections.expenses.title'),
                    'accounts'      => $expenses['accounts'],
                    'total_label'   => __('accounting::filament/clusters/reporting.pages.profit-loss.content.sections.expenses.total-label'),
                    'total'         => $expenses['total'],
                    'is_expense'    => true,
                    'empty_message' => __('accounting::filament/clusters/reporting.pages.profit-loss.content.sections.expenses.empty-message'),
                ],
            ],
            'net_income' => $netIncome,
            'is_profit'  => $netIncome >= 0,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
        ];
    }

    #[Computed]
    public function completeness(): array
    {
        $dateRange = $this->parseDateRange();
        $dateFrom = $dateRange ? Carbon::parse($dateRange[0]) : now()->startOfMonth();
        $dateTo = $dateRange ? Carbon::parse($dateRange[1]) : now()->endOfMonth();

        return app(ReportCompletenessService::class)->assess(
            Auth::user()->default_company_id,
            $dateFrom->toDateString(),
            $dateTo->toDateString(),
        );
    }

    /**
     * Revenue is credit-normal: each account shows -(debit - credit), so a
     * debit balance (e.g. sales returns) reduces revenue instead of adding.
     */
    protected function buildRevenueSection($accounts, $balances): array
    {
        $incomeAccounts = $this->getAccountsByTypes($accounts, $balances, [
            AccountType::INCOME->value,
            AccountType::INCOME_OTHER->value,
        ], -1);

        return [
            'accounts' => $incomeAccounts,
            'total'    => (float) collect($incomeAccounts)->sum('balance'),
        ];
    }

    /**
     * Expenses are debit-normal: each account shows (debit - credit), so a
     * credit balance (a contra-expense, reimbursement or reversal) reduces
     * expenses. Net income = revenue - expenses = -(sum of P&L balances).
     */
    protected function buildExpenseSection($accounts, $balances): array
    {
        $expenseAccounts = $this->getAccountsByTypes($accounts, $balances, [
            AccountType::EXPENSE->value,
            AccountType::EXPENSE_DIRECT_COST->value,
            AccountType::EXPENSE_DEPRECIATION->value,
        ], 1);

        return [
            'accounts' => $expenseAccounts,
            'total'    => (float) collect($expenseAccounts)->sum('balance'),
        ];
    }

    /**
     * @param  int  $normalSign  1 for debit-normal accounts, -1 for credit-normal
     */
    protected function getAccountsByTypes($accounts, $balances, array $types, int $normalSign = 1): array
    {
        return $accounts->filter(function ($account) use ($types, $balances) {
            $accountType = $account->account_type instanceof AccountType
                ? $account->account_type->value
                : $account->account_type;

            if (! in_array($accountType, $types)) {
                return false;
            }

            return isset($balances[$account->id]);
        })->map(function ($account) use ($balances, $normalSign) {
            $balance = (float) ($balances[$account->id]->balance ?? 0);

            return [
                'id'      => $account->id,
                'code'    => $account->code,
                'name'    => $account->name,
                'balance' => $normalSign === -1 ? 0.0 - $balance : $balance,
            ];
        })->sortBy('code')->values()->all();
    }

    private function authorizedCurrencyMode(): string
    {
        $mode = $this->data['currency_mode'] ?? ReportCurrencyMode::Company->value;
        if ($mode !== ReportCurrencyMode::Company->value) {
            abort_unless(Auth::user()?->can(AccountingPermissions::ViewMultiCurrencyReports), 403);
        }

        return $mode;
    }
}
