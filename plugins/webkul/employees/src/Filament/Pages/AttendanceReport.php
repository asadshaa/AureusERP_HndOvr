<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceExcel;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Models\Company;

/**
 * Attendance -> Attendance Report: HR's monthly attendance sheet (worked vs
 * required hours, delta, penalty) per company and month, on screen and as
 * the template Excel workbook. HR sees the company; a line manager only the
 * people in their reporting tree (MonthlyAttendanceReport enforces both).
 */
class AttendanceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'attendance-report';

    protected string $view = 'employees::filament.pages.attendance-report';

    /** @var array{company_id?: ?int, month?: ?string, department_id?: ?int, employee_ids?: array<int, int>} */
    public ?array $filters = [];

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return MonthlyAttendanceReport::canView($user) && CurrentCompany::membershipIds($user) !== [];
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return 'Attendance Report';
    }

    public function getTitle(): string
    {
        return 'Monthly attendance report';
    }

    public function mount(): void
    {
        $this->form->fill([
            'company_id'   => CurrentCompany::id() ?? (CurrentCompany::membershipIds()[0] ?? null),
            'month'        => now(config('attendance.monthly_report.timezone'))->format('Y-m'),
            'department_id'=> null,
            'employee_ids' => [],
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
                        ->afterStateUpdated(function (Set $set): void {
                            $set('department_id', null);
                            $set('employee_ids', []);
                        }),
                    Select::make('month')
                        ->label('Month')
                        ->options(fn (): array => $this->monthOptions())
                        ->required()
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make('department_id')
                        ->label('Department')
                        ->placeholder('All departments')
                        ->options(fn (): array => Department::query()->where('company_id', $this->companyId())->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('employee_ids', [])),
                    Select::make('employee_ids')
                        ->label('Employees')
                        ->placeholder('All employees')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => $this->employeeOptions())
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

        return app(MonthlyAttendanceReport::class)->forCompany(
            $user,
            $companyId,
            $this->month(),
            $this->departmentId(),
            array_map('intval', (array) ($this->filters['employee_ids'] ?? [])),
        );
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        try {
            $report = $this->report();
        } catch (AuthorizationException $exception) {
            return ['report' => null, 'error' => $exception->getMessage()];
        }

        return ['report' => $report, 'error' => null];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function (): StreamedResponse {
                    abort_unless(static::canAccess(), 403);

                    try {
                        $report = $this->report();
                    } catch (AuthorizationException) {
                        abort(403);
                    }

                    abort_if($report === null, 403);

                    return app(MonthlyAttendanceExcel::class)->download($report);
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

    protected function month(): string
    {
        $month = (string) ($this->filters['month'] ?? '');

        return preg_match('/^\d{4}-\d{2}$/', $month) ? $month : now(config('attendance.monthly_report.timezone'))->format('Y-m');
    }

    /** @return array<string, string> */
    protected function monthOptions(): array
    {
        $current = Carbon::now(config('attendance.monthly_report.timezone'))->startOfMonth();
        $options = [];

        for ($offset = 0; $offset < 24; $offset++) {
            $month = $current->copy()->subMonthsNoOverflow($offset);
            $options[$month->format('Y-m')] = $month->format('F Y');
        }

        return $options;
    }

    /** @return array<int, string> */
    protected function employeeOptions(): array
    {
        $user = CurrentCompany::user();
        $companyId = $this->companyId();

        if (! $user instanceof User || $companyId === null || ! MonthlyAttendanceReport::canView($user)) {
            return [];
        }

        return app(MonthlyAttendanceReport::class)
            ->reportableEmployees($user, $companyId, $this->departmentId())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
