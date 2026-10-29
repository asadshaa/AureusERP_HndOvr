<?php

namespace Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Checklists\ChecklistTaskTable;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeChecklist;
use Webkul\Employee\Models\EmployeeChecklistTask;
use Webkul\Employee\Policies\EmployeeChecklistTaskPolicy;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;

/**
 * "Onboarding & exit" tab of an employee: the checklist progress and its
 * tasks, newest checklist first. HR starts, undoes and manages; line
 * managers see their reports' checklists and complete their own tasks; the
 * employee sees only their own "employee" tasks.
 */
class ChecklistTasksRelationManager extends RelationManager
{
    protected static string $relationship = 'checklistTasks';

    protected static ?string $title = 'Onboarding & exit';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['checklist', 'assignee:id,name', 'completer:id,name', 'documentType:id,name', 'employee'])
                ->where('status', '!=', ChecklistKinds::TaskCancelled)
                ->when(! $this->seesAllTasks(), fn (Builder $query): Builder => $query->where('responsible_role', ChecklistKinds::RoleEmployee)))
            ->description(fn (): ?string => $this->progressSummary())
            ->columns(ChecklistTaskTable::columns(withEmployee: false))
            ->groups([
                Group::make('checklist_id')
                    ->label('Checklist')
                    ->getTitleFromRecordUsing(fn (EmployeeChecklistTask $record): string => $record->checklist?->typeLabel().' · '.$record->checklist?->anchorLabel()
                        .' · '.(ChecklistKinds::checklistStatuses()[$record->checklist?->status] ?? ''))
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query->orderBy('checklist_id', 'desc')),
            ])
            ->defaultGroup('checklist_id')
            ->groupingSettingsHidden()
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort')->orderBy('id'))
            ->paginated([25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No onboarding or exit checklist')
            ->emptyStateDescription($this->isHr() ? 'Use "Start onboarding" or "Start exit" at the top of the page.' : null)
            ->recordActions(ChecklistTaskTable::actions(withEmployeeLink: false));
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $ownerRecord instanceof Employee
            && app(EmployeeChecklistTaskPolicy::class)->viewEmployeeChecklists($user, $ownerRecord);
    }

    /** "Onboarding: 6 of 10 done, 2 overdue." for each open checklist. */
    protected function progressSummary(): ?string
    {
        $today = ChecklistKinds::today()->toDateString();

        $checklists = EmployeeChecklist::query()
            ->where('employee_id', $this->employee()->getKey())
            ->where('status', ChecklistKinds::ChecklistOpen)
            ->withCount([
                'tasks as total_count'   => fn (Builder $tasks) => $tasks->where('status', '!=', ChecklistKinds::TaskCancelled),
                'tasks as done_count'    => fn (Builder $tasks) => $tasks->whereIn('status', [ChecklistKinds::TaskDone, ChecklistKinds::TaskSkipped]),
                'tasks as overdue_count' => fn (Builder $tasks) => $tasks->where('status', ChecklistKinds::TaskPending)->whereDate('due_date', '<', $today),
            ])
            ->get();

        if ($checklists->isEmpty()) {
            return null;
        }

        return $checklists->map(fn (EmployeeChecklist $checklist): string => $checklist->typeLabel().': '.$checklist->done_count.' of '.$checklist->total_count.' done'
            .($checklist->overdue_count > 0 ? ', '.$checklist->overdue_count.' overdue' : '')
            .($checklist->type === ChecklistKinds::Offboarding && $checklist->last_working_day ? ' (last working day '.$checklist->last_working_day->format('d M Y').')' : '')
            .'.')->implode(' ');
    }

    protected function employee(): Employee
    {
        /** @var Employee $employee */
        $employee = $this->getOwnerRecord();

        return $employee;
    }

    protected function isHr(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(EmployeeChecklistTaskPolicy::class)->manageEmployee($user, $this->employee());
    }

    protected function seesAllTasks(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(EmployeeChecklistTaskPolicy::class)->seesAllTasksOf($user, $this->employee());
    }
}
