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
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\BalanceSheetExport;
use Webkul\Accounting\Services\Currency\FinancialStatementCurrencyService;
use Webkul\Accounting\Services\ReportCompletenessService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BalanceSheet extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms, NormalizeDateFilter;

    protected string $view = 'accounting::filament.clusters.reporting.pages.balance-sheet';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?int $navigationSort = 91;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_balance_sheet';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/reporting.pages.balance-sheet.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/reporting.pages.balance-sheet.navigation.title');
    }

    public function getTitle(): string
    {
        return __('accounting::filament/clusters/reporting.pages.balance-sheet.navigation.title');
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
                ->label(__('accounting::filament/clusters/reporting.pages.balance-sheet.actions.export-excel'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->action(function () {
                    $data = $this->balanceSheetData;
                    $date = $this->parseDateRange() ? Carbon::parse($this->parseDateRange()[1]) : now();

                    return Excel::download(
                        new BalanceSheetExport($data, $date),
                        'balance-sheet-'.$date->format('Y-m-d').'.xlsx'
                    );
                }),
            Action::make('pdf')
                ->label(__('accounting::filament/clusters/reporting.pages.balance-sheet.actions.export-pdf'))
                ->icon('heroicon-o-document-text')
                ->color('danger')
                ->action(function () {
                    $data = $this->balanceSheetData;
                    $pdf = Pdf::loadView('accounting::filament.clusters.reporting.pages.pdfs.balance-sheet', [
                        'data' => $data,
                    ]);

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, 'balance-sheet-'.now()->format('Y-m-d').'.pdf');
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
                        ->label(__('accounting::filament/clusters/reporting.pages.balance-sheet.filters.date-range'))
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
                        ->label(__('accounting::filament/clusters/reporting.pages.balance-sheet.filters.journals'))
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
    public function balanceSheetData(): array
    {
        $dateRange = $this->parseDateRange();
        $date = $dateRange ? Carbon::parse($dateRange[1]) : now();

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
            ->whereDate('accounts_account_move_lines.date', '<=', $date)
            ->groupBy('accounts_account_move_lines.account_id');

        if (! empty($journalIds)) {
            $query->whereIn('accounts_account_moves.journal_id', $journalIds);
        }

        $balances = $query->get()->keyBy('account_id');

        $accounts = Account::query()
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->whereIn('account_type', array_merge(
                array_keys(AccountType::assets()),
                array_keys(AccountType::liabilities()),
                array_keys(AccountType::equity())
            ))
            ->get()
            ->keyBy('id');

        $company = Company::query()->with('currency')->findOrFail($companyId);
        $currencyMode = $this->authorizedCurrencyMode();
        $currencyService = app(FinancialStatementCurrencyService::class);

        if ($currencyMode === ReportCurrencyMode::Original->value) {
            $balanceBundles = $currencyService->originalBalances($company, null, $date->toDateString(), $journalIds);
            $currentYearStart = $date->copy()->startOfYear();
            $currentEarningsBundles = $currencyService->originalBalances(
                $company,
                $currentYearStart->toDateString(),
                $date->toDateString(),
                $journalIds,
            );
            $previousEarningsBundles = $currencyService->originalBalances(
                $company,
                null,
                $currentYearStart->copy()->subDay()->toDateString(),
                $journalIds,
            );
            $profitLossAccountIds = Account::query()
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
                ->whereIn('account_type', array_merge(array_keys(AccountType::income()), array_keys(AccountType::expenses())))
                ->pluck('id');
            $currencyCodes = collect(array_keys($balanceBundles))
                ->merge(array_keys($currentEarningsBundles))
                ->merge(array_keys($previousEarningsBundles))
                ->unique()
                ->sort()
                ->values();
            $reports = [];
            foreach ($currencyCodes as $currencyCode) {
                $currentEarnings = -1 * $profitLossAccountIds->sum(
                    fn (int $accountId): float => (float) ($currentEarningsBundles[$currencyCode][$accountId]->balance ?? 0),
                );
                $previousEarnings = -1 * $profitLossAccountIds->sum(
                    fn (int $accountId): float => (float) ($previousEarningsBundles[$currencyCode][$accountId]->balance ?? 0),
                );
                $reports[$currencyCode] = $this->assembleBalanceSheet(
                    $accounts,
                    $balanceBundles[$currencyCode] ?? collect(),
                    $date,
                    $companyId,
                    $journalIds,
                    $currentEarnings,
                    $previousEarnings,
                );
            }

            $statement = $reports === []
                ? $this->assembleBalanceSheet($accounts, collect(), $date, $companyId, $journalIds, 0, 0)
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

        $statement = $this->assembleBalanceSheet($accounts, $balances, $date, $companyId, $journalIds);
        $currencyCode = (string) ($company->currency?->code ?: $company->currency?->name ?: 'Company currency');
        $warnings = [];
        $conversionStatus = 'complete';
        $rateBasis = 'Posted company-currency debit and credit fields; no translation.';

        if ($currencyMode === ReportCurrencyMode::Reporting->value) {
            $targetCurrency = Currency::query()->findOrFail((int) ($this->data['reporting_currency_id'] ?? 0));
            abort_unless($company->enabledCurrencies()->where('currencies.id', $targetCurrency->id)->wherePivot('reporting_enabled', true)->exists(), 422);
            $closing = $currencyService->reportingClosingRate($company, $targetCurrency, $date->toDateString());
            $warnings = $closing['warnings'];
            $conversionStatus = $closing['status'];
            $rateBasis = $closing['rate_basis'];
            if ($closing['rate'] !== null) {
                $statement = $currencyService->scaleStatement($statement, $closing['rate']);
                $currencyCode = (string) ($targetCurrency->code ?: $targetCurrency->name);
            } else {
                $warnings[] = "Amounts remain displayed in {$currencyCode}; no reporting-currency values were invented.";
            }
        }

        return array_merge($statement, [
            'reports'           => [$currencyCode => $statement],
            'currency_mode'     => $currencyMode,
            'currency'          => $currencyCode,
            'conversion_status' => $conversionStatus,
            'rate_basis'        => $rateBasis,
            'warnings'          => array_values(array_unique($warnings)),
        ]);
    }

    protected function assembleBalanceSheet(
        $accounts,
        $balances,
        Carbon $date,
        int $companyId,
        array $journalIds,
        ?float $currentYearEarnings = null,
        ?float $previousYearsEarnings = null,
    ): array {
        $assets = $this->buildAssetSection($accounts, $balances);
        $liabilities = $this->buildLiabilitySection($accounts, $balances);
        $equity = $this->buildEquitySection(
            $accounts,
            $balances,
            $date,
            $companyId,
            $journalIds,
            $currentYearEarnings,
            $previousYearsEarnings,
        );

        return [
            'sections' => [
                [
                    'title'       => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.assets.title'),
                    'subsections' => [
                        [
                            'title'       => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.assets.subsections.current-assets.title'),
                            'accounts'    => $assets['current_assets'],
                            'total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.assets.subsections.current-assets.total-label'),
                            'total'       => $assets['total_current'],
                        ],
                        [
                            'title'       => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.assets.subsections.fixed-assets.title'),
                            'accounts'    => $assets['fixed_assets'],
                            'total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.assets.subsections.fixed-assets.total-label'),
                            'total'       => $assets['total_fixed'],
                        ],
                        [
                            'title'       => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.assets.subsections.non-current-assets.title'),
                            'accounts'    => $assets['non_current_assets'],
                            'total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.assets.subsections.non-current-assets.total-label'),
                            'total'       => $assets['total_non_current'],
                        ],
                    ],
                    'total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.assets.total-label'),
                    'total'       => $assets['total'],
                ],
                [
                    'title'       => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.liabilities.title'),
                    'subsections' => [
                        [
                            'title'       => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.liabilities.subsections.current-liabilities.title'),
                            'accounts'    => $liabilities['current_liabilities'],
                            'total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.liabilities.subsections.current-liabilities.total-label'),
                            'total'       => $liabilities['total_current'],
                        ],
                        [
                            'title'       => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.liabilities.subsections.non-current-liabilities.title'),
                            'accounts'    => $liabilities['non_current_liabilities'],
                            'total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.liabilities.subsections.non-current-liabilities.total-label'),
                            'total'       => $liabilities['total_non_current'],
                        ],
                    ],
                    'total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.liabilities.total-label'),
                    'total'       => $liabilities['total'],
                ],
                [
                    'title'       => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.equity.title'),
                    'subsections' => [
                        [
                            'title'    => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.equity.subsections.unallocated-earnings.title'),
                            'accounts' => [
                                [
                                    'code'    => '',
                                    'name'    => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.equity.subsections.unallocated-earnings.current-year'),
                                    'balance' => $equity['current_year_earnings'],
                                ],
                                [
                                    'code'    => '',
                                    'name'    => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.equity.subsections.unallocated-earnings.previous-years'),
                                    'balance' => $equity['previous_years_earnings'],
                                ],
                            ],
                            'total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.equity.subsections.unallocated-earnings.total-label'),
                            'total'       => $equity['total_unallocated'],
                        ],
                        [
                            'title'         => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.equity.subsections.retained-earnings.title'),
                            'accounts'      => array_merge($equity['equity_accounts'], $equity['retained_accounts']),
                            'total_label'   => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.equity.subsections.retained-earnings.total-label'),
                            'total'         => $equity['total_equity'] + $equity['total_retained'],
                            'show_if_empty' => false,
                        ],
                    ],
                    'total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.sections.equity.total-label'),
                    'total'       => $equity['total'],
                ],
            ],
            'grand_total_label' => __('accounting::filament/clusters/reporting.pages.balance-sheet.content.grand-total-label'),
            'grand_total'       => $liabilities['total'] + $equity['total'],
            'date'              => $date,
        ];
    }

    #[Computed]
    public function completeness(): array
    {
        $dateRange = $this->parseDateRange();
        $date = $dateRange ? Carbon::parse($dateRange[1]) : now();

        return app(ReportCompletenessService::class)->assess(
            Auth::user()->default_company_id,
            $date->copy()->startOfYear()->toDateString(),
            $date->toDateString(),
        );
    }

    protected function buildAssetSection($accounts, $balances): array
    {
        $currentAssets = $this->getAccountsByTypes($accounts, $balances, [
            AccountType::ASSET_CASH->value,
            AccountType::ASSET_RECEIVABLE->value,
            AccountType::ASSET_CURRENT->value,
            AccountType::ASSET_PREPAYMENTS->value,
        ]);

        $fixedAssets = $this->getAccountsByTypes($accounts, $balances, [
            AccountType::ASSET_FIXED->value,
        ]);

        $nonCurrentAssets = $this->getAccountsByTypes($accounts, $balances, [
            AccountType::ASSET_NON_CURRENT->value,
        ]);

        $totalCurrent = collect($currentAssets)->sum('balance');
        $totalFixed = collect($fixedAssets)->sum('balance');
        $totalNonCurrent = collect($nonCurrentAssets)->sum('balance');

        return [
            'current_assets'     => $currentAssets,
            'fixed_assets'       => $fixedAssets,
            'non_current_assets' => $nonCurrentAssets,
            'total_current'      => $totalCurrent,
            'total_fixed'        => $totalFixed,
            'total_non_current'  => $totalNonCurrent,
            'total'              => $totalCurrent + $totalFixed + $totalNonCurrent,
        ];
    }

    protected function buildLiabilitySection($accounts, $balances): array
    {
        $currentLiabilities = $this->getAccountsByTypes($accounts, $balances, [
            AccountType::LIABILITY_CURRENT->value,
            AccountType::LIABILITY_PAYABLE->value,
            AccountType::LIABILITY_CREDIT_CARD->value,
        ], true);

        $nonCurrentLiabilities = $this->getAccountsByTypes($accounts, $balances, [
            AccountType::LIABILITY_NON_CURRENT->value,
        ], true);

        $totalCurrent = collect($currentLiabilities)->sum('balance');
        $totalNonCurrent = collect($nonCurrentLiabilities)->sum('balance');

        return [
            'current_liabilities'     => $currentLiabilities,
            'non_current_liabilities' => $nonCurrentLiabilities,
            'total_current'           => $totalCurrent,
            'total_non_current'       => $totalNonCurrent,
            'total'                   => $totalCurrent + $totalNonCurrent,
        ];
    }

    protected function buildEquitySection(
        $accounts,
        $balances,
        $date,
        $companyId,
        $journalIds,
        ?float $currentYearEarnings = null,
        ?float $previousYearsEarnings = null,
    ): array {
        $currentYearStart = $date->copy()->startOfYear();

        $currentYearEarnings ??= $this->calculateEarnings($companyId, $currentYearStart, $date, $journalIds);

        $previousYearsEarnings ??= $this->calculateEarnings($companyId, null, $currentYearStart->copy()->subDay(), $journalIds);

        $equityAccounts = $this->getAccountsByTypes($accounts, $balances, [
            AccountType::EQUITY->value,
        ], true);

        $retainedEarningsAccounts = $this->getAccountsByTypes($accounts, $balances, [
            AccountType::EQUITY_UNAFFECTED->value,
        ], true);

        $totalEquity = collect($equityAccounts)->sum('balance');
        $totalRetained = collect($retainedEarningsAccounts)->sum('balance');

        $totalUnallocated = $currentYearEarnings + $previousYearsEarnings;

        return [
            'current_year_earnings'   => $currentYearEarnings,
            'previous_years_earnings' => $previousYearsEarnings,
            'total_unallocated'       => $totalUnallocated,
            'equity_accounts'         => $equityAccounts,
            'retained_accounts'       => $retainedEarningsAccounts,
            'total_equity'            => $totalEquity,
            'total_retained'          => $totalRetained,
            // Was abs($totalUnallocated) -- flipped a net loss's negative
            // unallocated-earnings figure to positive, which then made
            // Total Equity overstate by 2x the loss and broke the balance
            // sheet equation (Assets != Liabilities + Equity) by that same
            // amount. Confirmed live: a real PKR 5,000 net loss correctly
            // showed as -5,000 in "Total Unallocated Earnings" two lines
            // above this, but "Total EQUITY" showed +5,000 -- the signed
            // value must flow through unchanged for the equation to hold.
            'total'                   => $totalUnallocated + $totalEquity + $totalRetained,
        ];
    }

    protected function calculateEarnings($companyId, $startDate, $endDate, $journalIds): float
    {
        $query = MoveLine::query()
            ->select(DB::raw('SUM(accounts_account_move_lines.debit - accounts_account_move_lines.credit) as balance'))
            ->join('accounts_account_moves', 'accounts_account_moves.id', '=', 'accounts_account_move_lines.move_id')
            ->join('accounts_accounts', 'accounts_accounts.id', '=', 'accounts_account_move_lines.account_id')
            ->where('accounts_account_moves.company_id', $companyId)
            ->where('accounts_account_moves.state', MoveState::POSTED)
            ->whereIn('accounts_accounts.account_type', array_merge(
                array_keys(AccountType::income()),
                array_keys(AccountType::expenses())
            ))
            ->whereDate('accounts_account_move_lines.date', '<=', $endDate);

        if ($startDate) {
            $query->whereDate('accounts_account_move_lines.date', '>=', $startDate);
        }

        if (! empty($journalIds)) {
            $query->whereIn('accounts_account_moves.journal_id', $journalIds);
        }

        $result = $query->first();

        return -1 * ($result->balance ?? 0);
    }

    protected function getAccountsByTypes($accounts, $balances, array $types, bool $flipSign = false): array
    {
        return $accounts->filter(function ($account) use ($types, $balances) {
            $accountType = $account->account_type instanceof AccountType
                ? $account->account_type->value
                : $account->account_type;

            if (! in_array($accountType, $types)) {
                return false;
            }

            return isset($balances[$account->id]);
        })->map(function ($account) use ($balances, $flipSign) {
            $balance = $balances[$account->id]->balance ?? 0;

            return [
                'id'      => $account->id,
                'code'    => $account->code,
                'name'    => $account->name,
                'balance' => $flipSign ? -1 * $balance : $balance,
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
