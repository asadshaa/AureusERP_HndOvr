<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\ReportingAssignment;
use Webkul\Employee\Models\ReportingChange;
use Webkul\Employee\Services\Reporting\ReportingLineService;
use Webkul\Employee\Support\ReportingPurposes;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Actions\HelpAction;
use Webkul\Support\Models\Company;

/**
 * Employees -> Reporting lines: HR sets, per person, the line manager and the
 * manager who approves one kind of request (leave, claims, ...), sees each
 * person's real approval flow and edits her own line (which then waits for a
 * second person). Everything is limited to the active company, and every
 * change goes through ReportingLineService, which re-checks permission,
 * company, loops and logins.
 */
class ReportingLines extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'employees/reporting-lines';

    protected string $view = 'employees::filament.pages.reporting-lines';

    /** @var array<int, array<string, int>>|null employee id => purpose => manager id, for this request */
    protected ?array $assignmentCache = null;

    /** @var array<int, string>|null manager employee id => name, for this request */
    protected ?array $managerNameCache = null;

    public static function canAccess(): bool
    {
        return app(ReportingLineService::class)->canManage(CurrentCompany::user());
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Reporting lines';
    }

    public function getTitle(): string
    {
        return 'Reporting lines';
    }

    public function getSubheading(): ?string
    {
        $companyId = CurrentCompany::id();
        $name = $companyId !== null ? (string) Company::query()->whereKey($companyId)->value('name') : null;

        return trim('Who reports to whom, and who approves what'.($name ? ' at '.$name : '').'.');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('reporting-lines'),
            Action::make('teamBoard')
                ->label('Drag & drop view')
                ->icon('heroicon-o-user-group')
                ->color('gray')
                ->url(fn (): string => TeamBoard::getUrl()),
            Action::make('history')
                ->label('History')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn (): string => ReportingHistory::getUrl()),
            $this->myReportingLineAction(),
        ];
    }

    public function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('name')
                ->label('Name')
                ->weight('medium')
                ->description(fn (Employee $record): ?string => filled($record->employee_number) ? (string) $record->employee_number : null)
                ->searchable(['name', 'employee_number'])
                ->sortable(),
            TextColumn::make('department.name')->label('Department')->placeholder('—')->toggleable(),
            TextColumn::make('parent.name')->label('Line manager')->placeholder('—'),
        ];

        foreach (ReportingPurposes::labels() as $purpose => $label) {
            $columns[] = TextColumn::make('purpose_'.$purpose)
                ->label($label)
                ->state(fn (Employee $record): ?string => $this->purposeManagerName($record, $purpose))
                ->formatStateUsing(fn (Employee $record, ?string $state): HtmlString => $this->purposeCell($record, $purpose, $state))
                ->tooltip(fn (Employee $record): ?string => $this->hasOwn($record, $purpose) ? 'Set for '.mb_strtolower($label).' only' : 'Follows the line manager')
                ->placeholder('—')
                ->toggleable();
        }

        return $table
            ->query(fn (): Builder => $this->peopleQuery())
            ->columns($columns)
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('department_id')
                    ->label('Department')
                    ->options(fn (): array => Department::query()->where('company_id', CurrentCompany::id())->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Filter::make('no_line_manager')
                    ->label('Has no line manager')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('parent_id')),
                Filter::make('has_own_approver')
                    ->label('Has own approver for a purpose')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn(
                        'id',
                        ReportingAssignment::query()->where('company_id', CurrentCompany::id())->select('employee_id'),
                    )),
            ])
            ->searchPlaceholder('Search by name or code')
            ->recordActions([
                $this->changeLineManagerAction(),
                $this->changeForPurposeAction(),
                $this->seeFlowAction(),
            ])
            ->toolbarActions([
                $this->bulkReassignAction(),
            ])
            ->emptyStateHeading('No people to show')
            ->emptyStateDescription('Only active employees of the current company are listed.');
    }

    /** @return Builder<Employee> */
    protected function peopleQuery(): Builder
    {
        $companyId = CurrentCompany::id();

        return Employee::query()
            ->where('company_id', $companyId ?? 0)
            ->where('is_active', true)
            ->with(['department:id,name', 'parent:id,name']);
    }

    // ------------------------------------------------------------------
    // Reading the columns without a query per row
    // ------------------------------------------------------------------

    /** @return array<int, array<string, int>> */
    protected function assignments(): array
    {
        return $this->assignmentCache ??= app(ReportingLineService::class)->assignmentsForCompany((int) CurrentCompany::id());
    }

    /** @return array<int, string> */
    protected function managerNames(): array
    {
        if ($this->managerNameCache === null) {
            $ids = collect($this->assignments())->flatten()->unique()->values()->all();

            $this->managerNameCache = $ids === []
                ? []
                : Employee::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
        }

        return $this->managerNameCache;
    }

    protected function hasOwn(Employee $record, string $purpose): bool
    {
        return isset($this->assignments()[(int) $record->id][$purpose]);
    }

    protected function purposeManagerName(Employee $record, string $purpose): ?string
    {
        $ownId = $this->assignments()[(int) $record->id][$purpose] ?? null;

        if ($ownId !== null) {
            return $this->managerNames()[$ownId] ?? 'Unknown';
        }

        return $record->parent?->name;
    }

    protected function purposeCell(Employee $record, string $purpose, ?string $state): HtmlString
    {
        if (blank($state)) {
            return new HtmlString('—');
        }

        if (! $this->hasOwn($record, $purpose)) {
            return new HtmlString(e($state));
        }

        return new HtmlString(
            '<em>'.e($state).'</em> <span style="font-size:.7rem;font-weight:600;padding:0 .35rem;border-radius:.5rem;border:1px solid currentColor;opacity:.8;">own</span>'
        );
    }

    // ------------------------------------------------------------------
    // Choosing a manager
    // ------------------------------------------------------------------

    /**
     * Active people of the company who could manage $employee: not the person
     * and nobody who reports to them (the service checks this again).
     *
     * @return array<int, string>
     */
    protected function managerOptions(?Employee $employee = null): array
    {
        $people = Employee::query()
            ->where('company_id', CurrentCompany::id() ?? 0)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        $blocked = [];
        if ($employee) {
            $parents = $people->pluck('parent_id', 'id')->all();
            $blocked[(int) $employee->id] = true;

            foreach ($parents as $id => $parentId) {
                $current = $parentId;
                $depth = 0;

                while ($current !== null && $depth++ < 100) {
                    if ((int) $current === (int) $employee->id) {
                        $blocked[(int) $id] = true;
                        break;
                    }
                    $current = $parents[$current] ?? null;
                }
            }
        }

        return $people->reject(fn (Employee $person): bool => isset($blocked[(int) $person->id]))
            ->pluck('name', 'id')
            ->all();
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    protected function changeLineManagerAction(): Action
    {
        return Action::make('changeLineManager')
            ->label('Change line manager')
            ->icon('heroicon-o-user')
            ->modalHeading(fn (Employee $record): string => 'Change line manager of '.$record->name)
            ->modalDescription('The line manager approves this person\'s requests unless a different manager is set for that kind of request. Waiting requests move to the new manager.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (Employee $record): array => ['manager' => $record->parent_id])
            ->schema(fn (Employee $record): array => [
                Select::make('manager')
                    ->label('Line manager')
                    ->options(fn (): array => $this->managerOptions($record))
                    ->searchable()
                    ->placeholder('No manager')
                    ->helperText('Leave empty if this person has no manager.'),
                Textarea::make('reason')->label('Reason (optional)')->rows(2)->maxLength(500),
            ])
            ->action(function (Action $action, Employee $record, array $data): void {
                $this->applyChange($action, $record, null, $data['manager'] ?? null, $data['reason'] ?? null);
            });
    }

    protected function changeForPurposeAction(): Action
    {
        return Action::make('changeForPurpose')
            ->label('Change for one purpose')
            ->icon('heroicon-o-adjustments-horizontal')
            ->modalHeading(fn (Employee $record): string => 'Different manager for '.$record->name)
            ->modalDescription('Choose what the manager approves, and who. Everything else still goes to the line manager.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => ['purpose' => null, 'manager' => null])
            ->schema(fn (Employee $record): array => [
                Select::make('purpose')
                    ->label('What does this manager approve?')
                    ->options(ReportingPurposes::labels())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (callable $set, ?string $state) => $set('manager', $state ? ($this->assignments()[(int) $record->id][$state] ?? null) : null)),
                Select::make('manager')
                    ->label('Manager for this')
                    ->options(fn (): array => $this->managerOptions($record))
                    ->searchable()
                    ->placeholder('Same as line manager')
                    ->helperText('Leave blank to follow the line manager. The manager needs a login to approve.'),
                Textarea::make('reason')->label('Reason (optional)')->rows(2)->maxLength(500),
            ])
            ->action(function (Action $action, Employee $record, array $data): void {
                $this->applyChange($action, $record, $data['purpose'], $data['manager'] ?? null, $data['reason'] ?? null);
            });
    }

    protected function seeFlowAction(): Action
    {
        return Action::make('seeFlow')
            ->label('See approval flow')
            ->icon('heroicon-o-queue-list')
            ->color('gray')
            ->slideOver()
            ->modalHeading(fn (Employee $record): string => 'Approval flow of '.$record->name)
            ->modalDescription('Who each step reaches for this person today.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (Employee $record) {
                abort_unless(app(ReportingLineService::class)->canManage(CurrentCompany::user(), (int) $record->company_id)
                    && (int) $record->company_id === (int) CurrentCompany::id(), 403);

                return view('employees::filament.pages.reporting-flow', [
                    'groups' => app(ReportingLineService::class)->flowFor($record),
                ]);
            });
    }

    protected function bulkReassignAction(): BulkAction
    {
        return BulkAction::make('giveNewManager')
            ->label('Give them a new manager')
            ->icon('heroicon-o-arrow-path')
            ->modalHeading('Give the selected people a new manager')
            ->modalDescription('Each person is checked on their own. Anyone who cannot be changed is listed afterwards with the reason.')
            ->modalSubmitActionLabel('Change')
            ->schema([
                Select::make('purpose')
                    ->label('What does the manager approve?')
                    ->options(ReportingPurposes::labels())
                    ->placeholder('Line manager (everything)')
                    ->helperText('Leave blank to change the line manager.'),
                Select::make('manager')
                    ->label('New manager')
                    ->options(fn (): array => $this->managerOptions())
                    ->searchable()
                    ->placeholder('No manager / same as line manager')
                    ->helperText('Leave blank to remove the manager (for one purpose: follow the line manager again).'),
                Textarea::make('reason')->label('Reason (optional)')->rows(2)->maxLength(500),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $user = CurrentCompany::user();
                $companyId = CurrentCompany::id();

                if (! $user instanceof User || $companyId === null || ! app(ReportingLineService::class)->canManage($user, $companyId)) {
                    Notification::make()->danger()->title('You may not change reporting lines in this company.')->send();

                    return;
                }

                $ids = $records
                    ->filter(fn ($record): bool => $record instanceof Employee && (int) $record->company_id === $companyId)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $result = app(ReportingLineService::class)->reassign(
                    $user,
                    $ids,
                    filled($data['purpose'] ?? null) ? $data['purpose'] : null,
                    filled($data['manager'] ?? null) ? (int) $data['manager'] : null,
                    $data['reason'] ?? null,
                );

                if ($result['changed'] > 0) {
                    Notification::make()->success()->title($result['changed'] === 1 ? 'Reporting line updated for 1 person' : 'Reporting line updated for '.$result['changed'].' people')->send();
                }

                if ($result['failed'] !== []) {
                    $lines = collect($result['failed'])->map(fn (array $failure): string => $failure['employee'].': '.$failure['reason']);
                    $body = $lines->take(8)->implode("\n").($lines->count() > 8 ? "\nand ".($lines->count() - 8).' more' : '');

                    Notification::make()->warning()->title(count($result['failed']).' could not be changed')->body($body)->persistent()->send();
                }
            });
    }

    protected function myReportingLineAction(): Action
    {
        return Action::make('myReportingLine')
            ->label('My reporting line')
            ->icon('heroicon-o-user-circle')
            ->color('gray')
            ->visible(fn (): bool => $this->myEmployee() !== null)
            ->modalHeading('My reporting line')
            ->modalDescription('A change to your own line needs another HR person or an admin to approve it before it takes effect.')
            ->modalSubmitActionLabel('Ask for this change')
            ->fillForm(fn (): array => ['manager' => $this->myEmployee()?->parent_id])
            ->schema(fn (): array => [
                Select::make('manager')
                    ->label('I report to')
                    ->options(fn (): array => $this->managerOptions($this->myEmployee()))
                    ->searchable()
                    ->placeholder('No manager'),
                Text::make(fn (): string => 'Reports to me: '.($this->reportsToMe() ?: 'nobody')),
                Textarea::make('reason')->label('Reason (optional)')->rows(2)->maxLength(500),
            ])
            ->action(function (Action $action, array $data): void {
                $me = $this->myEmployee();

                if (! $me) {
                    return;
                }

                $this->applyChange($action, $me, null, $data['manager'] ?? null, $data['reason'] ?? null);
            });
    }

    protected function myEmployee(): ?Employee
    {
        $user = CurrentCompany::user();
        $companyId = CurrentCompany::id($user);

        if (! $user || $companyId === null) {
            return null;
        }

        return Employee::query()->where('user_id', $user->id)->where('company_id', $companyId)->first();
    }

    protected function reportsToMe(): string
    {
        $me = $this->myEmployee();

        if (! $me) {
            return '';
        }

        return Employee::query()->where('parent_id', $me->id)->where('is_active', true)->orderBy('name')->pluck('name')->implode(', ');
    }

    /** Runs one change through the service and tells the user in plain words; a refusal keeps the modal open. */
    protected function applyChange(Action $action, Employee $employee, ?string $purpose, mixed $managerId, ?string $reason): void
    {
        $user = CurrentCompany::user();

        try {
            abort_unless($user instanceof User && (int) $employee->company_id === (int) CurrentCompany::id($user), 403);

            $change = app(ReportingLineService::class)->change(
                $user,
                $employee,
                $purpose,
                filled($managerId) ? (int) $managerId : null,
                $reason,
            );
        } catch (RuntimeException|InvalidArgumentException $exception) {
            Notification::make()->danger()->title('Not changed')->body($exception->getMessage())->send();
            $action->halt();

            return;
        }

        if ($change->status === ReportingChange::Pending) {
            Notification::make()->info()->title('Sent for approval')->body('A change to your own line needs another HR person or an admin to approve it.')->send();

            return;
        }

        Notification::make()->success()->title('Reporting line updated')->send();
    }
}
