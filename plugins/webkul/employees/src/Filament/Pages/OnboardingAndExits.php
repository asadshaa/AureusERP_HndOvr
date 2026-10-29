<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ChecklistTemplateResource;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\EmployeeChecklist;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * Employees -> Onboarding & exits: every onboarding and exit checklist of
 * the active company within the HR user's scope, with progress and
 * overdue counts. Open checklists first; filter by open, overdue,
 * completed or cancelled, and by type.
 */
class OnboardingAndExits extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $slug = 'employees/onboarding-and-exits';

    protected static ?int $navigationSort = 4;

    protected string $view = 'employees::filament.pages.onboarding-and-exits';

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return $user instanceof User && CurrentCompany::id($user) !== null && $user->can(HrPermissions::ManageChecklists);
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Onboarding & exits';
    }

    public function getTitle(): string
    {
        return 'Onboarding & exits';
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('templates')
                ->label('Checklist templates')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->visible(fn (): bool => ChecklistTemplateResource::canViewAny())
                ->url(fn (): string => ChecklistTemplateResource::getUrl('index')),
        ];
    }

    public function table(Table $table): Table
    {
        $today = ChecklistKinds::today()->toDateString();

        return $table
            ->query(fn (): Builder => $this->baseQuery()
                ->with(['employee:id,name,department_id,job_title', 'employee.department:id,name'])
                ->withCount([
                    'tasks as total_count'   => fn (Builder $tasks) => $tasks->where('status', '!=', ChecklistKinds::TaskCancelled),
                    'tasks as done_count'    => fn (Builder $tasks) => $tasks->whereIn('status', [ChecklistKinds::TaskDone, ChecklistKinds::TaskSkipped]),
                    'tasks as overdue_count' => fn (Builder $tasks) => $tasks->where('status', ChecklistKinds::TaskPending)->whereDate('due_date', '<', $today),
                ]))
            ->columns([
                TextColumn::make('employee.name')
                    ->label('Employee')
                    ->weight('medium')
                    ->searchable()
                    ->description(fn (EmployeeChecklist $record): ?string => $record->employee?->department?->name),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ChecklistKinds::typeLabel($state))
                    ->color(fn (?string $state): string => $state === ChecklistKinds::Offboarding ? 'danger' : 'info'),
                TextColumn::make('anchor_date')
                    ->label('Joining / last day')
                    ->date('d M Y')
                    ->sortable()
                    ->description(fn (EmployeeChecklist $record): ?string => $record->type === ChecklistKinds::Offboarding && $record->leaving_date
                        ? 'Leaves '.$record->leaving_date->format('d M Y')
                        : null),
                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (EmployeeChecklist $record): string => $record->done_count.' / '.$record->total_count)
                    ->description(fn (EmployeeChecklist $record): string => ($record->total_count > 0 ? (int) round($record->done_count / $record->total_count * 100) : 100).'% done'),
                TextColumn::make('overdue_count')
                    ->label('Overdue')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ChecklistKinds::checklistStatuses()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        ChecklistKinds::ChecklistCompleted => 'success',
                        ChecklistKinds::ChecklistCancelled => 'gray',
                        default                            => 'warning',
                    }),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->since()
                    ->sortable()
                    ->description(fn (EmployeeChecklist $record): string => $record->auto_started ? 'automatically' : ''),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Show')
                    ->options([
                        'open'      => 'Open',
                        'overdue'   => 'Open with overdue tasks',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ])
                    ->default('open')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open'      => $query->where('status', ChecklistKinds::ChecklistOpen),
                        'overdue'   => $query->where('status', ChecklistKinds::ChecklistOpen)
                            ->whereHas('tasks', fn (Builder $tasks) => $tasks->where('status', ChecklistKinds::TaskPending)->whereDate('due_date', '<', $today)),
                        'completed' => $query->where('status', ChecklistKinds::ChecklistCompleted),
                        'cancelled' => $query->where('status', ChecklistKinds::ChecklistCancelled),
                        default     => $query,
                    }),
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(ChecklistKinds::types()),
            ])
            ->defaultSort('anchor_date')
            ->recordUrl(fn (EmployeeChecklist $record): ?string => EmployeeResource::canView($record->employee)
                ? EmployeeResource::getUrl('view', ['record' => $record->employee_id])
                : null)
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No checklists')
            ->emptyStateDescription('Onboarding starts when a new joiner is added; use "Start exit" on an employee when someone leaves.');
    }

    /** @return Builder<EmployeeChecklist> */
    protected function baseQuery(): Builder
    {
        $user = CurrentCompany::user();
        $companyId = CurrentCompany::id($user);

        if (! $user instanceof User || $companyId === null || ! static::canAccess()) {
            return EmployeeChecklist::query()->whereRaw('1 = 0');
        }

        return EmployeeChecklist::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId)->all() ?: [0]);
    }
}
