<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\Attendance\TodayAttendance as TodayAttendanceService;
use Webkul\Employee\Services\Attendance\TodayAttendanceExcel;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Models\Company;

/**
 * Attendance -> Today's attendance: every employee's status for one day
 * (default today, Asia/Karachi) with problems first, the day's counts, and
 * an Excel download of the same list. HR sees the whole company; a line
 * manager only their reporting tree (TodayAttendance service enforces both,
 * through MonthlyAttendanceReport::reportableEmployees).
 */
class TodayAttendance extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'todays-attendance';

    protected string $view = 'employees::filament.pages.today-attendance';

    /** @var array{company_id?: ?int, date?: ?string, department_id?: ?int} */
    public ?array $filters = [];

    /**
     * Table filters in the URL, so the dashboard can link straight to e.g. the absent list.
     *
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    /** @var array<string, array<string, mixed>> report per filter set, for this request only */
    protected array $reportCache = [];

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
        return "Today's attendance";
    }

    public function getTitle(): string
    {
        return "Today's attendance";
    }

    public function mount(): void
    {
        // ?company= (the HR digest's link) is honoured only for a company the user belongs to.
        $requested = (int) request()->query('company', 0);

        $this->form->fill([
            'company_id'    => $requested > 0 && CurrentCompany::canAccess($requested)
                ? $requested
                : (CurrentCompany::id() ?? (CurrentCompany::membershipIds()[0] ?? null)),
            'date'          => app(TodayAttendanceService::class)->today(),
            'department_id' => null,
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
                            $this->resetPage();
                        }),
                    DatePicker::make('date')
                        ->label('Date')
                        ->required()
                        ->maxDate(fn (): string => app(TodayAttendanceService::class)->today())
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetPage()),
                    Select::make('department_id')
                        ->label('Department')
                        ->placeholder('All departments')
                        ->options(fn (): array => Department::query()->where('company_id', $this->companyId())->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetPage()),
                ])
                ->columns(['default' => 1, 'md' => 3]),
        ]);
    }

    /**
     * The selected day, or null when nothing may be shown.
     *
     * @return array<string, mixed>|null
     *
     * @throws AuthorizationException
     */
    public function report(): ?array
    {
        $user = CurrentCompany::user();
        $companyId = $this->companyId();

        if (! $user instanceof User || $companyId === null || ! static::canAccess()) {
            return null;
        }

        $key = implode(':', [$user->getKey(), $companyId, $this->date(), $this->departmentId() ?? 0]);

        return $this->reportCache[$key] ??= app(TodayAttendanceService::class)
            ->forCompany($user, $companyId, $this->date(), $this->departmentId());
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

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (array $filters, ?string $sortColumn, ?string $sortDirection, ?string $search, int $page, int $recordsPerPage): LengthAwarePaginator => $this->tableRecords($filters, $sortColumn, $sortDirection, $search, $page, $recordsPerPage))
            ->columns([
                TextColumn::make('employee')
                    ->label('Employee')
                    ->weight('medium')
                    ->description(fn (array $record): ?string => $record['code'] !== '' ? $record['code'] : null)
                    ->sortable(),
                TextColumn::make('department')->label('Department')->placeholder('—')->sortable(),
                TextColumn::make('shift')
                    ->label('Shift')
                    ->placeholder('—')
                    ->tooltip(fn (array $record): string => (string) $record['schedule'])
                    ->toggleable(),
                TextColumn::make('line_manager')->label('Line manager')->placeholder('—')->sortable(),
                TextColumn::make('status_label')
                    ->label('Status')
                    ->badge()
                    ->color(fn (array $record): string => TodayAttendanceService::statusColor($record['status']))
                    ->tooltip(fn (array $record): ?string => $record['remarks'] !== '' ? $record['remarks'] : null)
                    ->sortable(),
                TextColumn::make('first_in')->label('First in')->placeholder('—')->sortable(),
                TextColumn::make('last_out')->label('Last out')->placeholder('—')->sortable(),
                TextColumn::make('check_ins')->label('Check-ins')->placeholder('—')->wrap(),
                TextColumn::make('worked')->label('Worked so far')->placeholder('—')->sortable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state, array $record): string => trim(($state ?? '').($record['remote'] ? ' · remote' : '').($record['needs_review'] ? ' · needs review' : ''), ' ·')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(TodayAttendanceService::statusOptions()),
            ])
            ->searchable()
            ->recordActions([
                Action::make('employee')
                    ->label('Employee')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->url(fn (array $record): string => EmployeeResource::getUrl('view', ['record' => $record['employee_id']])),
                Action::make('attendance')
                    ->label('Attendance')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->visible(fn (): bool => AttendanceRecordResource::canViewAny())
                    ->url(fn (array $record): string => AttendanceRecordResource::getUrl('index', [
                        'filters' => [
                            'company_id'  => ['values' => [$this->companyId()]],
                            'employee_id' => ['value' => $record['employee_id']],
                        ],
                    ])),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No employees to show')
            ->emptyStateDescription('Nobody matches the selected company, department and status.');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function tableRecords(array $filters, ?string $sortColumn, ?string $sortDirection, ?string $search, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        try {
            $report = $this->report();
        } catch (AuthorizationException) {
            $report = null;
        }

        $rows = collect($report === null ? [] : TodayAttendanceService::sortProblemsFirst($report['rows']));

        $statuses = array_filter((array) ($filters['status']['values'] ?? []));
        if ($statuses !== []) {
            $rows = $rows->filter(fn (array $row): bool => in_array($row['status'], $statuses, true)
                || (in_array(TodayAttendanceService::MISSING_CHECK_OUT, $statuses, true) && $row['missing_check_out'])
                || (in_array(TodayAttendanceService::LATE, $statuses, true) && $row['late']));
        }

        if (filled($search)) {
            $needle = mb_strtolower((string) $search);
            $rows = $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['employee'].' '.$row['code'].' '.$row['department'].' '.$row['line_manager']), $needle));
        }

        $sortable = ['employee', 'department', 'line_manager', 'status_label', 'first_in', 'last_out', 'worked'];
        if (in_array($sortColumn, $sortable, true)) {
            $column = match ($sortColumn) {
                'status_label' => 'status_rank',
                'worked'       => 'worked_seconds',
                default        => $sortColumn,
            };
            $rows = $rows->sortBy(fn (array $row): array => [is_string($row[$column]) ? mb_strtolower($row[$column]) : $row[$column], mb_strtolower($row['employee'])], SORT_REGULAR, $sortDirection === 'desc');
        }

        return new LengthAwarePaginator(
            $rows->forPage($page, $recordsPerPage)->all(),
            total: $rows->count(),
            perPage: $recordsPerPage,
            currentPage: $page,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadExcel')
                ->label('Download (Excel)')
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

                    return app(TodayAttendanceExcel::class)->download($report);
                }),
        ];
    }

    /** The selected company, only when the user belongs to it. */
    public function companyId(): ?int
    {
        $companyId = (int) ($this->filters['company_id'] ?? 0);

        return $companyId > 0 && CurrentCompany::canAccess($companyId) ? $companyId : null;
    }

    protected function departmentId(): ?int
    {
        $departmentId = (int) ($this->filters['department_id'] ?? 0);

        return $departmentId > 0 ? $departmentId : null;
    }

    /** The selected date (Y-m-d), never in the future. */
    protected function date(): string
    {
        $service = app(TodayAttendanceService::class);
        $today = $service->today();
        $date = (string) ($this->filters['date'] ?? '');

        if ($date !== '') {
            try {
                $date = Carbon::parse($date, $service->timezone())->toDateString();
            } catch (\Throwable) {
                $date = '';
            }
        }

        return $date === '' || $date > $today ? $today : $date;
    }
}
