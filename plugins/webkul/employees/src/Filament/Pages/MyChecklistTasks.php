<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Filament\Checklists\ChecklistTaskTable;
use Webkul\Employee\Services\Checklists\EmployeeChecklistService;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * My tasks: the onboarding and exit checklist tasks waiting for the
 * signed-in user in the active company: assigned to them, and the HR
 * queue for the company's HR (EmployeeChecklistService::myTasksQuery).
 */
class MyChecklistTasks extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $slug = 'my-tasks';

    protected static ?int $navigationSort = 20;

    protected string $view = 'employees::filament.pages.my-checklist-tasks';

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return $user instanceof User && CurrentCompany::id($user) !== null;
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Dashboard;
    }

    public static function getNavigationLabel(): string
    {
        return 'My tasks';
    }

    public function getTitle(): string
    {
        return 'My tasks';
    }

    public function getSubheading(): ?string
    {
        return 'Onboarding and exit checklist tasks waiting for you. Mark each one done when it is finished.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(EmployeeChecklistService::class)
                ->myTasksQuery($this->user(), (int) CurrentCompany::id())
                ->with(['checklist', 'employee', 'assignee:id,name', 'completer:id,name', 'documentType:id,name']))
            ->columns(ChecklistTaskTable::columns(withEmployee: true))
            ->filters([
                SelectFilter::make('state')
                    ->label('Show')
                    ->options([
                        'open'    => 'To do',
                        'overdue' => 'Overdue',
                        'closed'  => 'Done or skipped',
                    ])
                    ->default('open')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open'    => $query->where('status', ChecklistKinds::TaskPending),
                        'overdue' => $query->overdue(),
                        'closed'  => $query->whereIn('status', [ChecklistKinds::TaskDone, ChecklistKinds::TaskSkipped]),
                        default   => $query,
                    }),
                SelectFilter::make('type')
                    ->label('Checklist')
                    ->options(ChecklistKinds::types())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('checklist', fn (Builder $checklists) => $checklists->where('type', $data['value']))
                        : $query),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id'))
            ->recordActions(ChecklistTaskTable::actions(withEmployeeLink: true))
            ->paginated([25, 50, 100])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Nothing waiting for you')
            ->emptyStateDescription('Onboarding and exit tasks assigned to you will appear here.');
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = CurrentCompany::user();

        return $user;
    }
}
