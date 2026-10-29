<?php

namespace Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Actions\WorkScheduleActions;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Services\Attendance\WorkScheduleService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Models\User;

/**
 * "Work schedule" tab of an employee: the effective-dated schedules HR
 * assigned (start / end, required minutes, working days, flexible window),
 * newest first. A day without one uses the company default (office hours in
 * config/attendance.php). HR of the company manages them; the employee and
 * their managers can see them.
 */
class WorkSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'workSchedules';

    protected static ?string $title = 'Work schedule';

    public function form(Schema $schema): Schema
    {
        return $schema->components(WorkScheduleActions::formSchema())->columns(2);
    }

    public function table(Table $table): Table
    {
        $default = app(AttendanceScheduleResolver::class)->defaultSchedule();

        return $table
            ->description('Without a schedule for a day, the company default applies: '.$default->label().', '.intdiv($default->requiredMinutes, 60).'h required, '.WorkSchedule::weekdaysLabel($default->workingDays).'.')
            ->columns([
                TextColumn::make('effective_from')->label('From')->date('d M Y'),
                TextColumn::make('effective_to')->label('To')->date('d M Y')->placeholder('No end date'),
                TextColumn::make('hours')->label('Hours')
                    ->getStateUsing(fn (WorkSchedule $record): string => WorkSchedule::clock($record->start_time).'–'.WorkSchedule::clock($record->end_time)),
                TextColumn::make('required_minutes')->label('Required')
                    ->formatStateUsing(fn ($state): string => intdiv((int) $state, 60).'h '.str_pad((string) ((int) $state % 60), 2, '0', STR_PAD_LEFT).'m'),
                TextColumn::make('working_days')->label('Working days')
                    ->getStateUsing(fn (WorkSchedule $record): string => WorkSchedule::weekdaysLabel(array_map('intval', (array) $record->working_days))),
                TextColumn::make('flexible_minutes')->label('Flexible')
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? $state.' min' : '—'),
                TextColumn::make('break_minutes')->label('Unpaid break')
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? $state.' min' : '—'),
                TextColumn::make('name')->label('Name')->placeholder('—')->toggleable(),
                TextColumn::make('creator.name')->label('Assigned by')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Assign schedule')
                    ->modalHeading('Assign a work schedule')
                    ->visible(fn (): bool => $this->canManageOwner())
                    ->using(fn (array $data, CreateAction $action): ?Model => WorkScheduleActions::guarded(
                        $action,
                        fn (): WorkSchedule => app(WorkScheduleService::class)->assign($this->user(), $this->employee(), $data),
                    )),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canManageOwner())
                    ->using(fn (WorkSchedule $record, array $data, EditAction $action): ?Model => WorkScheduleActions::guarded(
                        $action,
                        fn (): WorkSchedule => tap($record)->update($data),
                    )),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canManageOwner())
                    ->using(fn (WorkSchedule $record, DeleteAction $action): mixed => WorkScheduleActions::guarded(
                        $action,
                        fn (): ?bool => $record->delete(),
                    )),
            ])
            ->defaultSort('effective_from', 'desc');
    }

    public function isReadOnly(): bool
    {
        return ! $this->canManageOwner();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $ownerRecord instanceof Employee
            && ((int) $ownerRecord->user_id === (int) $user->id || app(HrHierarchyService::class)->canManage($user, $ownerRecord));
    }

    /** Server-side: HR of the employee's company (WorkScheduleService checks it again on save). */
    private function canManageOwner(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(WorkScheduleService::class)->canManage($user, $this->employee());
    }

    private function employee(): Employee
    {
        /** @var Employee $employee */
        $employee = $this->getOwnerRecord();

        return $employee;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
