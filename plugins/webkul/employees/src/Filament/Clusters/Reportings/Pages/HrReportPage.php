<?php

namespace Webkul\Employee\Filament\Clusters\Reportings\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Filament\Clusters\Reportings;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Services\Reports\HrReportExcel;
use Webkul\Employee\Services\Reports\HrReportScope;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Shared shell of the HR reports in Employees -> Reportings, following the
 * Attendance Report page: a filter form (company the user belongs to, the
 * report's period, department with its sub-departments), the report on
 * screen and "Export to Excel". Access needs hr_view_reports; the report
 * services check it again together with the company and the user's HR
 * scope (HrReportScope), so the export is authorised the same way.
 */
abstract class HrReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = Reportings::class;

    /** @var array<string, mixed> */
    public ?array $filters = [];

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return HrReportScope::canView($user) && CurrentCompany::membershipIds($user) !== [];
    }

    /** @return array<string, mixed> the report's own filter defaults */
    abstract protected function defaultFilters(): array;

    /** @return array<int, Component> the report's own filters (period) */
    abstract protected function periodFields(): array;

    /** @return array<string, mixed> */
    abstract protected function buildReport(User $user, int $companyId, ?int $departmentId): array;

    /** @param  array<string, mixed>  $report */
    abstract protected function workbook(HrReportExcel $excel, array $report): Spreadsheet;

    /** @param  array<string, mixed>  $report */
    abstract protected function workbookName(HrReportExcel $excel, array $report): string;

    public function mount(): void
    {
        $this->form->fill([
            'company_id'    => CurrentCompany::id() ?? (CurrentCompany::membershipIds()[0] ?? null),
            'department_id' => null,
            ...$this->defaultFilters(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->components([
            Section::make()
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(fn (): array => Company::query()->whereIn('id', CurrentCompany::membershipIds())->orderBy('name')->pluck('name', 'id')->all())
                        ->required()
                        ->selectablePlaceholder(false)
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('department_id', null)),
                    ...$this->periodFields(),
                    Select::make('department_id')
                        ->label('Department')
                        ->placeholder('All departments')
                        ->helperText('Includes its sub-departments.')
                        ->options(fn (): array => $this->departmentOptions())
                        ->searchable()
                        ->live(),
                ])
                ->columns(['default' => 1, 'md' => 4]),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function report(): ?array
    {
        $user = CurrentCompany::user();
        $companyId = $this->companyId();

        if (! $user instanceof User || $companyId === null) {
            return null;
        }

        return $this->buildReport($user, $companyId, $this->departmentId());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        try {
            return ['report' => $this->report(), 'error' => null];
        } catch (AuthorizationException|InvalidArgumentException $exception) {
            return ['report' => null, 'error' => $exception->getMessage()];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export to Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function (): StreamedResponse {
                    abort_unless(static::canAccess(), 403);

                    try {
                        $report = $this->report();
                    } catch (AuthorizationException) {
                        abort(403);
                    } catch (InvalidArgumentException $exception) {
                        abort(422, $exception->getMessage());
                    }

                    abort_if($report === null, 403);

                    $excel = app(HrReportExcel::class);

                    return $excel->download($this->workbook($excel, $report), $this->workbookName($excel, $report));
                }),
        ];
    }

    /** The selected company, only when the user belongs to it. */
    protected function companyId(): ?int
    {
        $companyId = (int) ($this->filters['company_id'] ?? 0);

        return $companyId > 0 && CurrentCompany::canAccess($companyId) ? $companyId : null;
    }

    protected function departmentId(): ?int
    {
        $departmentId = (int) ($this->filters['department_id'] ?? 0);

        return $departmentId > 0 ? $departmentId : null;
    }

    /** @return array<int, string> */
    protected function departmentOptions(): array
    {
        $companyId = $this->companyId();

        if ($companyId === null) {
            return [];
        }

        return Department::query()
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'complete_name'])
            ->mapWithKeys(fn (Department $department): array => [(int) $department->id => (string) ($department->complete_name ?: $department->name)])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->all();
    }
}
