<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ShiftResource;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ShiftRosterResource;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\ShiftRoster;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Services\Attendance\ShiftRosterExcel;
use Webkul\Employee\Services\Attendance\ShiftRosterService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Models\Company;

/**
 * Attendance -> Shift roster (HR parity plan 3.4): weeks x employees, each
 * day in its shift's colour. HR and line managers see the people they may
 * see; clicking a day of someone they manage changes it (another shift, a
 * day off, a swap with a colleague, or back to the roster) with a reason;
 * "Assign roster" gives a pattern to several employees (or, for HR, a
 * department) from a start date; the grid downloads as Excel. Every change
 * goes through ShiftRosterService, which checks scope, company and locked
 * months again.
 */
class ShiftRosterCalendar extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'shift-roster';

    protected string $view = 'employees::filament.pages.shift-roster';

    /** @var array{company_id?: ?int, from?: ?string, weeks?: int|string|null, department_id?: ?int, search?: ?string} */
    public ?array $filters = [];

    /** The employee whose day is being changed (the swap list leaves them out). */
    public ?int $editingEmployeeId = null;

    /** @var array<string, array<string, mixed>> calendar per filter set, for this request only */
    protected array $calendarCache = [];

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return ShiftRosterService::canView($user) && CurrentCompany::membershipIds($user) !== [];
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return 'Shift roster';
    }

    public function getTitle(): string
    {
        return 'Shift roster';
    }

    public function mount(): void
    {
        $this->form->fill([
            'company_id'    => CurrentCompany::id() ?? (CurrentCompany::membershipIds()[0] ?? null),
            'from'          => CarbonImmutable::now($this->timezone())->startOfWeek()->toDateString(),
            'weeks'         => (int) config('attendance.shifts.roster_weeks', 2),
            'department_id' => null,
            'search'        => null,
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
                    DatePicker::make('from')
                        ->label('Week of')
                        ->required()
                        ->live(),
                    Select::make('weeks')
                        ->label('Weeks')
                        ->options([1 => '1 week', 2 => '2 weeks', 4 => '4 weeks'])
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make('department_id')
                        ->label('Department')
                        ->placeholder('All departments')
                        ->options(fn (): array => Department::query()->where('company_id', $this->companyId())->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live(),
                    TextInput::make('search')
                        ->label('Employee')
                        ->placeholder('Name or code')
                        ->live(debounce: 500),
                ])
                ->columns(['default' => 1, 'md' => 5]),
        ]);
    }

    /**
     * The grid for the selected filters, or null when nothing may be shown.
     *
     * @return array<string, mixed>|null
     *
     * @throws AuthorizationException
     */
    public function calendar(): ?array
    {
        $user = CurrentCompany::user();
        $companyId = $this->companyId();

        if (! $user instanceof User || $companyId === null || ! static::canAccess()) {
            return null;
        }

        $key = implode(':', [$user->getKey(), $companyId, $this->from(), $this->days(), $this->departmentId() ?? 0, $this->search()]);

        return $this->calendarCache[$key] ??= app(ShiftRosterService::class)
            ->calendar($user, $companyId, $this->from(), $this->days(), $this->departmentId(), $this->search());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        try {
            $calendar = $this->calendar();
        } catch (AuthorizationException $exception) {
            return ['calendar' => null, 'error' => $exception->getMessage()];
        }

        return ['calendar' => $calendar, 'error' => null];
    }

    public function previousPeriod(): void
    {
        $this->filters['from'] = CarbonImmutable::parse($this->from())->subDays($this->days())->toDateString();
        $this->calendarCache = [];
    }

    public function nextPeriod(): void
    {
        $this->filters['from'] = CarbonImmutable::parse($this->from())->addDays($this->days())->toDateString();
        $this->calendarCache = [];
    }

    public function thisWeek(): void
    {
        $this->filters['from'] = CarbonImmutable::now($this->timezone())->startOfWeek()->toDateString();
        $this->calendarCache = [];
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /** Clicking a day: another shift, a day off, a swap, or back to the roster. */
    public function editDayAction(): Action
    {
        return Action::make('editDay')
            ->modalHeading(fn (array $arguments): string => 'Change '.($this->managedEmployee($arguments)?->name ?? 'the day').' on '.$this->dateLabel($arguments))
            ->modalDescription(fn (array $arguments): ?string => $this->currentDescription($arguments))
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (array $arguments): array {
                $this->editingEmployeeId = (int) ($arguments['employee'] ?? 0);

                return [
                    'mode'     => 'change',
                    'shift_id' => $this->currentCell($arguments)['shift_id'] ?? null,
                ];
            })
            ->schema([
                Radio::make('mode')
                    ->label('What changes')
                    ->options([
                        'change' => 'Work another shift',
                        'off'    => 'Day off',
                        'swap'   => 'Swap with a colleague',
                        'reset'  => 'Back to the roster',
                    ])
                    ->required()
                    ->live(),
                Select::make('shift_id')
                    ->label('Shift')
                    ->options(fn (): array => app(ShiftRosterService::class)->shiftOptions((int) $this->companyId()))
                    ->visible(fn (Get $get): bool => $get('mode') === 'change')
                    ->required(fn (Get $get): bool => $get('mode') === 'change'),
                Select::make('swap_employee_id')
                    ->label('Colleague')
                    ->options(fn (): array => $this->swapOptions(['employee' => $this->editingEmployeeId]))
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('mode') === 'swap')
                    ->required(fn (Get $get): bool => $get('mode') === 'swap')
                    ->helperText('Each takes the other\'s shift (or day off) on this date.'),
                Textarea::make('reason')
                    ->label('Reason')
                    ->rows(2)
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('mode') !== 'reset')
                    ->required(fn (Get $get): bool => $get('mode') !== 'reset'),
            ])
            ->action(function (array $data, array $arguments): void {
                $user = Auth::user();
                $employee = $this->managedEmployee($arguments);
                $date = (string) ($arguments['date'] ?? '');

                if (! $user instanceof User || ! $employee || $date === '') {
                    Notification::make()->danger()->title('Not saved')->body('You may not change this day.')->send();

                    return;
                }

                $service = app(ShiftRosterService::class);

                $this->attempt(function () use ($service, $user, $employee, $date, $data): string {
                    $mode = (string) ($data['mode'] ?? 'change');

                    if ($mode === 'reset') {
                        $service->clearDay($user, $employee, $date);

                        return 'Back to the roster';
                    }

                    if ($mode === 'swap') {
                        $colleague = $this->swapEmployee((int) ($data['swap_employee_id'] ?? 0))
                            ?? throw ValidationException::withMessages(['swap_employee_id' => 'Choose a colleague whose roster you manage.']);
                        $service->swap($user, $employee, $colleague, $date, (string) $data['reason']);

                        return 'Shifts swapped with '.$colleague->name;
                    }

                    $service->setDay($user, $employee, $date, $mode === 'off' ? null : (int) $data['shift_id'], (string) $data['reason']);

                    return $mode === 'off' ? 'Day off saved' : 'Shift changed';
                });
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assignRoster')
                ->label('Assign roster')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('primary')
                ->visible(fn (): bool => $this->canManageSomeone())
                ->modalHeading('Assign a roster pattern')
                ->modalDescription('Day 1 of the pattern is the start date, and it repeats from there. A roster without an end date that started earlier is ended the day before; history is kept. Day changes made on this page stay.')
                ->schema([
                    Select::make('roster_id')
                        ->label('Pattern')
                        ->options(fn (): array => app(ShiftRosterService::class)->patternOptions((int) $this->companyId()))
                        ->required(),
                    Radio::make('target')
                        ->label('For')
                        ->options(fn (): array => ShiftRosterService::canConfigure(Auth::user()) ? ['employees' => 'Selected employees', 'department' => 'A whole department'] : ['employees' => 'Selected employees'])
                        ->default('employees')
                        ->required()
                        ->live(),
                    Select::make('employee_ids')
                        ->label('Employees')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => $this->managedEmployeeOptions())
                        ->visible(fn (Get $get): bool => $get('target') !== 'department')
                        ->required(fn (Get $get): bool => $get('target') !== 'department'),
                    Select::make('department_id')
                        ->label('Department')
                        ->options(fn (): array => Department::query()->where('company_id', $this->companyId())->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $get('target') === 'department')
                        ->required(fn (Get $get): bool => $get('target') === 'department')
                        ->helperText('Everyone in the department without their own roster follows it.'),
                    DatePicker::make('start_date')
                        ->label('Start date (day 1)')
                        ->default(fn (): string => $this->from())
                        ->required(),
                    DatePicker::make('end_date')
                        ->label('End date')
                        ->afterOrEqual('start_date')
                        ->helperText('Leave empty for no end date.'),
                    TextInput::make('notes')
                        ->label('Notes')
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $this->assignRoster($data);
                }),
            Action::make('downloadExcel')
                ->label('Download (Excel)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function (): StreamedResponse {
                    abort_unless(static::canAccess(), 403);

                    try {
                        $calendar = $this->calendar();
                    } catch (AuthorizationException) {
                        abort(403);
                    }

                    abort_if($calendar === null, 403);

                    return app(ShiftRosterExcel::class)->download($calendar);
                }),
            Action::make('shifts')
                ->label('Shifts')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible(fn (): bool => ShiftRosterService::canConfigure(Auth::user()))
                ->url(fn (): string => ShiftResource::getUrl('index')),
            Action::make('patterns')
                ->label('Patterns')
                ->icon('heroicon-o-queue-list')
                ->color('gray')
                ->visible(fn (): bool => ShiftRosterService::canConfigure(Auth::user()))
                ->url(fn (): string => ShiftRosterResource::getUrl('index')),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function assignRoster(array $data): void
    {
        $user = Auth::user();
        $companyId = $this->companyId();
        $roster = ShiftRoster::query()->where('company_id', $companyId)->find((int) ($data['roster_id'] ?? 0));

        if (! $user instanceof User || $companyId === null || ! $roster) {
            Notification::make()->danger()->title('Not saved')->body('Choose a roster pattern of this company.')->send();

            return;
        }

        $service = app(ShiftRosterService::class);

        if (($data['target'] ?? 'employees') === 'department') {
            $department = Department::query()->where('company_id', $companyId)->find((int) ($data['department_id'] ?? 0));

            $this->attempt(function () use ($service, $user, $roster, $department, $data): string {
                if (! $department) {
                    throw ValidationException::withMessages(['department_id' => 'Choose a department of this company.']);
                }

                $service->assignDepartment($user, $roster, $department, (string) $data['start_date'], $data['end_date'] ?? null, $data['notes'] ?? null);

                return 'Roster assigned to '.$department->name;
            });

            return;
        }

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_map('intval', (array) ($data['employee_ids'] ?? [])))
            ->orderBy('name')
            ->get();

        $result = $service->assignMany($user, $roster, $employees, (string) $data['start_date'], $data['end_date'] ?? null, $data['notes'] ?? null);
        $skipped = collect($result['skipped'])->map(fn (string $reason, string $name): string => "{$name}: {$reason}")->values();
        $this->calendarCache = [];

        Notification::make()
            ->{$result['assigned'] > 0 ? 'success' : 'warning'}()
            ->title($result['assigned'] > 0 ? "Roster assigned to {$result['assigned']} employee(s)" : 'No roster was assigned')
            ->body($skipped->isNotEmpty() ? 'Skipped: '.$skipped->take(5)->implode('; ').($skipped->count() > 5 ? ' …' : '') : null)
            ->send();
    }

    // ------------------------------------------------------------------

    /** The selected company, only when the user belongs to it. */
    public function companyId(): ?int
    {
        $companyId = (int) ($this->filters['company_id'] ?? 0);

        return $companyId > 0 && CurrentCompany::canAccess($companyId) ? $companyId : null;
    }

    /** The first day shown (Y-m-d). */
    public function from(): string
    {
        try {
            return CarbonImmutable::parse((string) ($this->filters['from'] ?? ''))->toDateString();
        } catch (\Throwable) {
            return CarbonImmutable::now($this->timezone())->startOfWeek()->toDateString();
        }
    }

    public function days(): int
    {
        $weeks = (int) ($this->filters['weeks'] ?? 0);

        return in_array($weeks, [1, 2, 4], true) ? $weeks * 7 : 14;
    }

    protected function departmentId(): ?int
    {
        $departmentId = (int) ($this->filters['department_id'] ?? 0);

        return $departmentId > 0 ? $departmentId : null;
    }

    protected function search(): string
    {
        return mb_substr(trim((string) ($this->filters['search'] ?? '')), 0, 60);
    }

    protected function timezone(): string
    {
        return (string) config('attendance.monthly_report.timezone', 'Asia/Karachi');
    }

    /** An employee of the selected company whose roster the user manages, from the action's arguments. */
    protected function managedEmployee(array $arguments): ?Employee
    {
        $employee = Employee::query()->where('company_id', $this->companyId())->find((int) ($arguments['employee'] ?? 0));

        return $employee && app(ShiftRosterService::class)->canManage(Auth::user(), $employee) ? $employee : null;
    }

    protected function swapEmployee(int $employeeId): ?Employee
    {
        $employee = Employee::query()->where('company_id', $this->companyId())->find($employeeId);

        return $employee && app(ShiftRosterService::class)->canManage(Auth::user(), $employee) ? $employee : null;
    }

    /** @return array<string, mixed>|null */
    protected function currentCell(array $arguments): ?array
    {
        $employee = $this->managedEmployee($arguments);
        $date = (string) ($arguments['date'] ?? '');
        if (! $employee || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return app(ShiftRosterService::class)->cell(app(AttendanceScheduleResolver::class)->scheduleFor($employee, $date), $date);
    }

    protected function currentDescription(array $arguments): ?string
    {
        $cell = $this->currentCell($arguments);

        return $cell ? 'Now: '.$cell['title'] : null;
    }

    protected function dateLabel(array $arguments): string
    {
        try {
            return CarbonImmutable::parse((string) ($arguments['date'] ?? ''))->format('D d M Y');
        } catch (\Throwable) {
            return 'this day';
        }
    }

    /** @return array<int, string> colleagues (same company, managed by the user) other than the employee clicked */
    protected function swapOptions(array $arguments): array
    {
        return collect($this->managedEmployeeOptions())
            ->except([(int) ($arguments['employee'] ?? 0)])
            ->all();
    }

    /** @return array<int, string> employees of the selected company whose roster the user manages */
    protected function managedEmployeeOptions(): array
    {
        $user = Auth::user();
        $companyId = $this->companyId();
        if (! $user instanceof User || $companyId === null) {
            return [];
        }

        $service = app(ShiftRosterService::class);

        return Employee::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('id', app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId))
            ->orderBy('name')
            ->get(['id', 'company_id', 'user_id', 'name', 'employee_number'])
            ->filter(fn (Employee $employee): bool => $service->canManage($user, $employee))
            ->mapWithKeys(fn (Employee $employee): array => [(int) $employee->id => $employee->name.($employee->employee_number ? ' ('.$employee->employee_number.')' : '')])
            ->all();
    }

    protected function canManageSomeone(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can(HrPermissions::ManageAttendance) && $this->companyId() !== null;
    }

    /** Runs a change and reports it; refusals (scope, overlap, locked month) become a notification. */
    protected function attempt(callable $callback): void
    {
        try {
            $title = $callback();
            $this->calendarCache = [];
            Notification::make()->success()->title($title)->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not saved')->body((string) collect($e->errors())->flatten()->first())->send();
        } catch (AuthorizationException|RuntimeException|InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
        }
    }
}
