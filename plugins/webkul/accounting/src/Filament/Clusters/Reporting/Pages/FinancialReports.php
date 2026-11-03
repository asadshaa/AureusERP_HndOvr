<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;
use Webkul\Accounting\Data\ReportColumnSpec;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Enums\CurrencyMode;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\ReportSpreadsheetExport;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportQueryService;
use Webkul\Accounting\Services\ReportTemplateValidator;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Renders any configured report template — the configurable replacement for
 * the hardcoded statement pages. Layout (rows, columns, spacers, bolding,
 * check rows) comes entirely from the template definition.
 */
class FinancialReports extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.reporting.pages.financial-reports';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_financial_reports';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/reporting/pages/financial-reports.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/reporting/pages/financial-reports.navigation.title');
    }

    public function getTitle(): string
    {
        return __('accounting::filament/clusters/reporting/pages/financial-reports.navigation.title');
    }

    /**
     * Every non-archived report is its own sidebar entry under "Reports", so
     * finance users reach any statement in one click; the plain "Financial
     * Reports" entry remains as the picker view.
     *
     * @return array<int, NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! static::canAccess()) {
            return [];
        }

        $items = [
            NavigationItem::make(static::getNavigationLabel())
                ->group(static::getNavigationGroup())
                ->icon(static::$navigationIcon)
                ->sort(1)
                ->url(static::getUrl())
                ->isActiveWhen(fn (): bool => request()->url() === static::getUrl()
                    && ! request()->filled('template')),
        ];

        try {
            $templates = ReportTemplate::query()
                ->where('status', '!=', TemplateStatus::ARCHIVED->value)
                ->where(function ($query): void {
                    $query->whereNull('company_id')
                        ->orWhereIn('company_id', static::authorizedCompanyIds());
                })
                ->orderBy('sort')
                ->get(['id', 'name']);
        } catch (\Throwable) {
            // Navigation must never break the panel (e.g. before migrations run).
            return $items;
        }

        foreach ($templates as $index => $template) {
            $items[] = NavigationItem::make('financial-report-'.$template->id)
                ->label($template->name)
                ->group(static::getNavigationGroup())
                ->icon('heroicon-o-document-text')
                ->sort(2 + $index)
                ->url(static::getUrl(['template' => $template->id]))
                ->isActiveWhen(fn (): bool => request()->url() === static::getUrl()
                    && request()->integer('template') === $template->id);
        }

        return $items;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label(__('accounting::filament/clusters/reporting/pages/financial-reports.actions.export-excel'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->visible(fn () => $this->reportData !== null)
                ->action(function () {
                    $report = $this->reportData;

                    return Excel::download(
                        new ReportSpreadsheetExport(
                            $report['template'],
                            $report['columns'],
                            $report['rows'],
                            $report['usd'],
                            $report['year'],
                        ),
                        Str::slug($report['template']->name).'-'.$report['year'].'.xlsx',
                    );
                }),
            Action::make('exportPdf')
                ->label(__('accounting::filament/clusters/reporting/pages/financial-reports.actions.export-pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('danger')
                ->visible(fn () => $this->reportData !== null)
                ->action(function () {
                    $report = $this->reportData;

                    $dataColumns = array_filter($report['columns'], fn ($column) => ! $column->isSpacer());

                    $pdf = Pdf::loadView('accounting::filament.clusters.reporting.pages.pdfs.financial-report', [
                        'template'     => $report['template'],
                        'year'         => $report['year'],
                        'columns'      => $report['columns'],
                        'rows'         => $report['rows'],
                        'companyLabel' => $this->companyScopeLabel(),
                        'generatedAt'  => now(),
                        'subLabel'     => fn ($column) => $this->columnSubLabel($column),
                        'formatValue'  => fn ($value) => $this->formatValue($value, $report['usd']),
                    ])->setPaper('a4', count($dataColumns) > 6 ? 'landscape' : 'portrait');

                    $filename = Str::slug($report['template']->name).'-'.$report['year'].'.pdf';

                    return response()->streamDownload(fn () => print ($pdf->output()), $filename);
                }),
        ];
    }

    protected function companyScopeLabel(): string
    {
        $ids = array_values(array_intersect(
            array_map('intval', $this->data['companies'] ?? []),
            static::authorizedCompanyIds(),
        ));

        if ($ids === []) {
            return __('accounting::filament/clusters/reporting/pages/financial-reports.filters.companies-placeholder');
        }

        return Company::query()->whereIn('id', $ids)->pluck('name')->implode(', ');
    }

    public function mount(): void
    {
        $this->form->fill([
            'template_id' => request()->integer('template') ?: ReportTemplate::query()
                ->where('status', '!=', TemplateStatus::ARCHIVED->value)
                ->where(function ($query): void {
                    $query->whereNull('company_id')
                        ->orWhereIn('company_id', static::authorizedCompanyIds());
                })
                ->orderBy('sort')
                ->value('id'),
            'year'      => request()->integer('year') ?: now()->year,
            'companies' => array_filter([Auth::user()?->default_company_id]),
        ]);
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make()
                ->columns([
                    'default' => 1,
                    'sm'      => 3,
                ])
                ->schema([
                    Select::make('template_id')
                        ->label(__('accounting::filament/clusters/reporting/pages/financial-reports.filters.report'))
                        ->options(fn () => ReportTemplate::query()
                            ->where('status', '!=', TemplateStatus::ARCHIVED->value)
                            ->where(function ($query): void {
                                $query->whereNull('company_id')
                                    ->orWhereIn('company_id', static::authorizedCompanyIds());
                            })
                            ->orderBy('sort')
                            ->pluck('name', 'id'))
                        ->live()
                        ->searchable(),
                    Select::make('year')
                        ->label(__('accounting::filament/clusters/reporting/pages/financial-reports.filters.year'))
                        ->options(array_combine(
                            range(now()->year - 5, now()->year + 1),
                            range(now()->year - 5, now()->year + 1),
                        ))
                        ->live(),
                    Select::make('companies')
                        ->label(__('accounting::filament/clusters/reporting/pages/financial-reports.filters.companies'))
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => Company::query()->whereKey(static::authorizedCompanyIds())
                            ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                        ->getSearchResultsUsing(fn (string $search): array => Company::query()
                            ->whereKey(static::authorizedCompanyIds())
                            ->where('name', 'like', "%{$search}%")
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values): array => Company::query()
                            ->whereKey(static::authorizedCompanyIds())
                            ->whereKey($values)
                            ->pluck('name', 'id')
                            ->all())
                        ->live()
                        ->placeholder(__('accounting::filament/clusters/reporting/pages/financial-reports.filters.companies-placeholder')),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    #[Computed]
    public function reportData(): ?array
    {
        $templateId = $this->data['template_id'] ?? null;

        if (! $templateId) {
            return null;
        }

        $template = ReportTemplate::query()
            ->where(function ($query): void {
                $query->whereNull('company_id')
                    ->orWhereIn('company_id', static::authorizedCompanyIds());
            })
            ->find($templateId);

        if (! $template) {
            return null;
        }

        $year = (int) ($this->data['year'] ?? now()->year);
        $authorizedCompanyIds = static::authorizedCompanyIds();
        $requestedCompanyIds = array_values(array_intersect(
            array_map('intval', $this->data['companies'] ?? []),
            $authorizedCompanyIds,
        ));
        if ($requestedCompanyIds === []) {
            return null;
        }
        // Entity columns/lines of the template can only narrow to companies
        // the viewer belongs to; any other entity renders as "not available".
        $context = ReportContext::forCompanies($requestedCompanyIds)->restrictedTo($authorizedCompanyIds);

        $service = app(ReportQueryService::class);

        $columns = $service->columnsFor($template, $year, $context);
        $rows = $service->getReport($template, $year, $context);

        $issues = app(ReportTemplateValidator::class)->validate($template);

        return [
            'template'    => $template,
            'year'        => $year,
            'columns'     => $columns,
            'rows'        => $rows,
            'usd'         => in_array($template->currency_mode, [CurrencyMode::USD_ONLY, CurrencyMode::LEDGER_AND_USD], true),
            'issues'      => $issues,
            'unavailable' => collect($columns)
                ->reject(fn (ReportColumnSpec $column): bool => $column->isAvailableIn($context))
                ->map(fn (ReportColumnSpec $column): string => $column->key)
                ->values()
                ->all(),
        ];
    }

    public function columnSubLabel(ReportColumnSpec $column): string
    {
        $period = $column->period;

        if ($period === null) {
            return '';
        }

        if ($period->startDate->isSameDay($period->startDate->copy()->startOfYear())
            && $period->endDate->isSameDay($period->endDate->copy()->endOfYear())) {
            return (string) $period->endDate->year;
        }

        if ($period->startDate->isSameMonth($period->endDate)) {
            return $period->endDate->format("M'y");
        }

        return $period->startDate->format('M').'-'.$period->endDate->format("M'y");
    }

    /**
     * A null value is "not available" (an entity the viewer cannot access),
     * which is distinct from a zero ("-").
     */
    public function formatValue(?float $value, bool $usd): string
    {
        if ($value === null) {
            return __('accounting::filament/clusters/reporting/pages/financial-reports.content.not-available');
        }

        if (abs($value) < 0.005) {
            return '-';
        }

        $formatted = number_format(abs($value), 0);

        if ($usd) {
            $formatted = '$'.$formatted;
        }

        return $value < 0 ? "({$formatted})" : $formatted;
    }

    /**
     * The companies the signed-in user belongs to (CurrentCompany membership
     * rules: allowed companies plus the default one, archived companies
     * excluded, every active company for a group owner).
     *
     * @return array<int, int>
     */
    protected static function authorizedCompanyIds(): array
    {
        return CurrentCompany::membershipIds();
    }
}
