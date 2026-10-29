<?php

namespace Webkul\Employee\Filament\Clusters\Reportings\Pages;

use BackedEnum;
use Filament\Forms\Components\Select;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Webkul\Employee\Services\Reports\HrReportExcel;
use Webkul\Employee\Services\Reports\LeaveBalanceReport;
use Webkul\Security\Models\User;
use Webkul\TimeOff\Services\LeaveBalanceService;

/** Employees -> Reportings -> Leave balances (LeaveBalanceReport). */
class LeaveBalances extends HrReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = -10;

    protected static ?string $slug = 'leave-balances';

    protected string $view = 'employees::filament.pages.reports.leave-balances';

    public static function getNavigationLabel(): string
    {
        return 'Leave Balances';
    }

    public function getTitle(): string
    {
        return 'Leave balances';
    }

    protected function defaultFilters(): array
    {
        return ['year' => LeaveBalanceService::today()->year];
    }

    protected function periodFields(): array
    {
        return [
            Select::make('year')
                ->label('Year')
                ->options(fn (): array => $this->yearOptions())
                ->required()
                ->selectablePlaceholder(false)
                ->live(),
        ];
    }

    protected function buildReport(User $user, int $companyId, ?int $departmentId): array
    {
        $year = (int) ($this->filters['year'] ?? 0);

        return app(LeaveBalanceReport::class)->build(
            $user,
            $companyId,
            array_key_exists($year, $this->yearOptions()) ? $year : LeaveBalanceService::today()->year,
            $departmentId,
        );
    }

    protected function workbook(HrReportExcel $excel, array $report): Spreadsheet
    {
        return $excel->leaveBalances($report);
    }

    protected function workbookName(HrReportExcel $excel, array $report): string
    {
        return $excel->fileName('Leave-Balances', $report, (string) $report['year']);
    }

    /** @return array<int, string> next year (allocations made ahead) and the five before it */
    protected function yearOptions(): array
    {
        $current = LeaveBalanceService::today()->year;
        $years = range($current + 1, $current - 4);

        return array_combine($years, array_map('strval', $years));
    }
}
