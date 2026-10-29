<?php

namespace Webkul\Employee\Filament\Clusters\Reportings\Pages;

use BackedEnum;
use Filament\Forms\Components\DatePicker;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Webkul\Employee\Services\Reports\HrReportExcel;
use Webkul\Employee\Services\Reports\HrReportScope;
use Webkul\Employee\Services\Reports\JoinersLeaversReport;
use Webkul\Security\Models\User;

/** Employees -> Reportings -> Joiners & leavers (JoinersLeaversReport). */
class JoinersLeavers extends HrReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = -30;

    protected static ?string $slug = 'joiners-leavers';

    protected string $view = 'employees::filament.pages.reports.joiners-leavers';

    public static function getNavigationLabel(): string
    {
        return 'Joiners & Leavers';
    }

    public function getTitle(): string
    {
        return 'Joiners & leavers';
    }

    protected function defaultFilters(): array
    {
        $today = HrReportScope::now();

        return [
            'from' => $today->startOfYear()->toDateString(),
            'to'   => $today->toDateString(),
        ];
    }

    protected function periodFields(): array
    {
        return [
            DatePicker::make('from')
                ->label('From')
                ->required()
                ->native(false)
                ->displayFormat('d M Y')
                ->live(),
            DatePicker::make('to')
                ->label('To')
                ->required()
                ->native(false)
                ->displayFormat('d M Y')
                ->live(),
        ];
    }

    protected function buildReport(User $user, int $companyId, ?int $departmentId): array
    {
        $defaults = $this->defaultFilters();

        return app(JoinersLeaversReport::class)->build(
            $user,
            $companyId,
            $this->dateFilter('from') ?? $defaults['from'],
            $this->dateFilter('to') ?? $defaults['to'],
            $departmentId,
        );
    }

    protected function workbook(HrReportExcel $excel, array $report): Spreadsheet
    {
        return $excel->joinersLeavers($report);
    }

    protected function workbookName(HrReportExcel $excel, array $report): string
    {
        return $excel->fileName('Joiners-Leavers', $report, $report['from'].'-to-'.$report['to']);
    }

    protected function dateFilter(string $key): ?string
    {
        $value = substr((string) ($this->filters[$key] ?? ''), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
