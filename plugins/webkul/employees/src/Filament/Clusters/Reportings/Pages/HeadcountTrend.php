<?php

namespace Webkul\Employee\Filament\Clusters\Reportings\Pages;

use BackedEnum;
use Filament\Forms\Components\Select;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Webkul\Employee\Services\Reports\HeadcountTrendReport;
use Webkul\Employee\Services\Reports\HrReportExcel;
use Webkul\Employee\Services\Reports\HrReportScope;
use Webkul\Security\Models\User;

/** Employees -> Reportings -> Headcount trend (HeadcountTrendReport). */
class HeadcountTrend extends HrReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = -20;

    protected static ?string $slug = 'headcount-trend';

    protected string $view = 'employees::filament.pages.reports.headcount-trend';

    public static function getNavigationLabel(): string
    {
        return 'Headcount Trend';
    }

    public function getTitle(): string
    {
        return 'Headcount trend';
    }

    protected function defaultFilters(): array
    {
        return [
            'end_month' => HrReportScope::now()->format('Y-m'),
            'months'    => 12,
        ];
    }

    protected function periodFields(): array
    {
        return [
            Select::make('end_month')
                ->label('Up to')
                ->options(fn (): array => $this->monthOptions())
                ->required()
                ->selectablePlaceholder(false)
                ->live(),
            Select::make('months')
                ->label('Months')
                ->options(array_combine(HeadcountTrendReport::PERIODS, array_map(fn (int $months): string => "Last {$months} months", HeadcountTrendReport::PERIODS)))
                ->required()
                ->selectablePlaceholder(false)
                ->live(),
        ];
    }

    protected function buildReport(User $user, int $companyId, ?int $departmentId): array
    {
        $months = (int) ($this->filters['months'] ?? 12);

        return app(HeadcountTrendReport::class)->build(
            $user,
            $companyId,
            (string) ($this->filters['end_month'] ?? ''),
            in_array($months, HeadcountTrendReport::PERIODS, true) ? $months : 12,
            $departmentId,
        );
    }

    protected function workbook(HrReportExcel $excel, array $report): Spreadsheet
    {
        return $excel->headcount($report);
    }

    protected function workbookName(HrReportExcel $excel, array $report): string
    {
        $months = $report['months'];

        return $excel->fileName('Headcount', $report, $months[0]['month'].'-to-'.$months[count($months) - 1]['month']);
    }

    /** @return array<string, string> */
    protected function monthOptions(): array
    {
        $current = HrReportScope::now()->startOfMonth();
        $options = [];

        for ($offset = 0; $offset < 24; $offset++) {
            $month = $current->subMonthsNoOverflow($offset);
            $options[$month->format('Y-m')] = $month->format('F Y');
        }

        return $options;
    }
}
