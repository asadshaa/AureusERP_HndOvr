<?php

namespace Webkul\TimeOff\Filament\Clusters\Management\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Filament\Actions\ActivityTableAction;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Filament\Concerns\ScopedToMemberCompanies;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Actions\LeaveCancellationActions;
use Webkul\TimeOff\Filament\Clusters\Management;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource\Pages\CreateTimeOff;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource\Pages\EditTimeOff;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource\Pages\ListTimeOff;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource\Pages\ViewTimeOff;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\TimeOff\Traits\TimeOffHelper;

class TimeOffResource extends Resource
{
    use ScopedToMemberCompanies;
    use TimeOffHelper;

    protected static ?string $model = Leave::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $cluster = Management::class;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('time-off::filament/clusters/management/resources/time-off.model-label');
    }

    public static function getNavigationLabel(): string
    {
        return __('time-off::filament/clusters/management/resources/time-off.navigation.title');
    }

    /**
     * The shared LeavePolicy::viewAny also admits the employee-only
     * "my time off" permission (for My Time -> My Time Off), which would put a
     * Management menu in front of every employee. The Management screen is for
     * people who manage time off.
     */
    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->can('view_any_time_off_time::off');
    }

    /**
     * Count of leave requests still awaiting a decision (submitted or
     * past the line-manager step, not yet fully approved/refused) within
     * this user's already-scoped visible hierarchy -- getEloquentQuery()
     * below handles the hierarchy scoping, so this only adds the
     * state filter on top of it.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = CurrentCompany::scope(static::getEloquentQuery())
            ->whereIn('state', [State::CONFIRM, State::VALIDATE_ONE])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['employee.name', 'holidayStatus.name', 'date_from', 'date_to'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('time-off::filament/clusters/management/resources/time-off.global-search.employee')      => $record->employee?->name ?? '—',
            __('time-off::filament/clusters/management/resources/time-off.global-search.time-off-type') => $record->holidayStatus?->name ?? '—',
            __('time-off::filament/clusters/management/resources/time-off.global-search.date-from')     => $record->date_from ?? '—',
            __('time-off::filament/clusters/management/resources/time-off.global-search.date-to')       => $record->date_to ?? '—',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components((new self)->getFormSchema(true));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.name')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.columns.employee-name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('holidayStatus.name')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.columns.time-off-type'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('private_name')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.columns.description'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('date_from')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.columns.date-from'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('date_to')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.columns.date-to'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('duration_display')
                    ->label(__('Duration'))
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.columns.duration'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('state')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.columns.status'))
                    ->formatStateUsing(fn (State $state, Leave $record) => LeaveApprovalService::isReturned($record) ? 'Returned for changes' : $state->getLabel())
                    ->color(fn (State $state, Leave $record): string|array|null => LeaveApprovalService::isReturned($record) ? 'info' : $state->getColor())
                    ->sortable()
                    ->badge()
                    ->searchable(),
            ])
            ->defaultSort('date_from', 'asc')
            ->groups([
                Tables\Grouping\Group::make('employee.name')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.groups.employee-name'))
                    ->collapsible(),
                Tables\Grouping\Group::make('holidayStatus.name')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.groups.time-off-type'))
                    ->collapsible(),
                Tables\Grouping\Group::make('state')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.groups.status'))
                    ->collapsible(),
                Tables\Grouping\Group::make('date_from')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.groups.start-date'))
                    ->collapsible(),
                Tables\Grouping\Group::make('date_to')
                    ->label(__('time-off::filament/clusters/management/resources/time-off.table.groups.start-to'))
                    ->collapsible(),
            ])
            ->filters([
                static::companyFilter(),
            ])
            ->recordActions([
                ActivityTableAction::make()
                    ->visible(fn (Leave $record): bool => static::isActiveCompanyRecord($record)),
                ActionGroup::make([
                    Action::make('submit_for_approval')
                        ->label(fn (Leave $record): string => LeaveApprovalService::isReturned($record) ? 'Resubmit' : 'Submit for approval')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->visible(function (Leave $record): bool {
                            if (! static::isActiveCompanyRecord($record)) {
                                return false;
                            }
                            if (LeaveApprovalService::isReturned($record)) {
                                return Auth::user() instanceof User && app(LeaveApprovalService::class)->canResubmit($record, Auth::user());
                            }

                            $canSubmit = (int) $record->employee?->user_id === (int) Auth::id()
                                || (bool) Auth::user()?->can('hr_approve_leave');
                            $approvalStatus = $record->approvalRequest?->status;

                            return $canSubmit
                                && in_array($record->state, [State::CONFIRM, State::REFUSE], true)
                                && $approvalStatus !== 'pending';
                        })
                        ->action(function (Leave $record): void {
                            $request = app(LeaveApprovalService::class)->submit($record, Auth::user());

                            Notification::make()
                                ->success()
                                ->title('Leave submitted for approval')
                                ->body(app(ApprovalEngine::class)->describeCurrentApprover($request))
                                ->send();
                        }),
                    Action::make('approve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Leave $record): bool => static::isActiveCompanyRecord($record)
                            && $record->approvalRequest?->status === 'pending'
                            && app(ApprovalEngine::class)->canAct($record->approvalRequest, Auth::user()))
                        ->action(function (Leave $record): void {
                            app(LeaveApprovalService::class)->approve($record, Auth::user());

                            Notification::make()
                                ->success()
                                ->title(__('time-off::filament/clusters/management/resources/time-off.table.actions.approve.notification.title'))
                                ->body(__('time-off::filament/clusters/management/resources/time-off.table.actions.approve.notification.body'))
                                ->send();
                        })
                        ->label(function (Leave $record): string {
                            if ($record->state === State::VALIDATE_ONE) {
                                return __('time-off::filament/clusters/management/resources/time-off.table.actions.approve.title.validate');
                            }

                            return __('time-off::filament/clusters/management/resources/time-off.table.actions.approve.title.approve');
                        }),
                    Action::make('refuse')
                        ->icon('heroicon-o-x-circle')
                        ->visible(fn (Leave $record): bool => static::isActiveCompanyRecord($record)
                            && $record->approvalRequest?->status === 'pending'
                            && app(ApprovalEngine::class)->canAct($record->approvalRequest, Auth::user()))
                        ->color('danger')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Rejection reason')
                                ->required()
                                ->maxLength(2000),
                        ])
                        ->action(function (Leave $record, array $data): void {
                            app(LeaveApprovalService::class)->reject($record, Auth::user(), $data['reason']);

                            Notification::make()
                                ->success()
                                ->title(__('time-off::filament/clusters/management/resources/time-off.table.actions.refused.notification.title'))
                                ->body(__('time-off::filament/clusters/management/resources/time-off.table.actions.refused.notification.body'))
                                ->send();
                        })
                        ->label(__('time-off::filament/clusters/management/resources/time-off.table.actions.refused.title')),
                    Action::make('return_to_requester')
                        ->label('Return to requester')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('info')
                        ->modalDescription('The employee is asked to change the request and resubmit it. Until then it does not count against the balance; approval then starts again from the first step.')
                        ->visible(fn (Leave $record): bool => static::isActiveCompanyRecord($record)
                            && $record->approvalRequest?->status === 'pending'
                            && app(ApprovalEngine::class)->canAct($record->approvalRequest, Auth::user()))
                        ->schema([
                            Textarea::make('comment')
                                ->label('What needs to change')
                                ->required()
                                ->maxLength(2000),
                        ])
                        ->action(function (Leave $record, array $data): void {
                            app(LeaveApprovalService::class)->returnToRequester($record, Auth::user(), (string) $data['comment']);

                            Notification::make()
                                ->success()
                                ->title('Returned to the employee for changes')
                                ->send();
                        }),
                    ...LeaveCancellationActions::make(),
                    ViewAction::make(),
                    EditAction::make()
                        ->visible(fn (Leave $record): bool => $record->approvalRequest?->status !== 'pending'
                            && ! in_array($record->state, [State::VALIDATE_TWO, State::CANCEL], true)),
                    DeleteAction::make()
                        ->visible(fn (Leave $record): bool => $record->approvalRequest?->status !== 'pending'
                            && ! in_array($record->state, [State::VALIDATE_TWO, State::CANCEL], true))
                        ->successNotification(
                            Notification::make()
                                ->success()
                                ->title(__('time-off::filament/clusters/management/resources/time-off.table.actions.delete.notification.title'))
                                ->body(__('time-off::filament/clusters/management/resources/time-off.table.actions.delete.notification.body'))
                        ),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            $deletable = $records->filter(fn (Leave $record) => static::isActiveCompanyRecord($record) && $record->approvalRequest?->status !== 'pending' && ! in_array($record->state, [State::VALIDATE_TWO, State::CANCEL], true));
                            $skipped = $records->count() - $deletable->count();

                            $deletable->each(fn (Leave $record) => $record->delete());

                            if ($skipped > 0) {
                                Notification::make()
                                    ->warning()
                                    ->title('Some leave requests could not be deleted')
                                    ->body("{$skipped} approved or pending leave request(s), or requests of another company, were protected and could not be deleted.")
                                    ->send();
                            } else {
                                Notification::make()
                                    ->success()
                                    ->title(__('time-off::filament/clusters/management/resources/time-off.table.bulk-actions.delete.notification.title'))
                                    ->body(__('time-off::filament/clusters/management/resources/time-off.table.bulk-actions.delete.notification.body'))
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Group::make()
                            ->schema([
                                TextEntry::make('holidayStatus.name')
                                    ->label(__('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.time-off-type'))
                                    ->icon('heroicon-o-calendar'),

                                TextEntry::make('request_unit_half')
                                    ->label(__('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.half-day'))
                                    ->formatStateUsing(fn ($record) => $record->request_unit_half ? 'Yes' : 'No')
                                    ->icon('heroicon-o-clock'),

                                TextEntry::make('request_date_from')
                                    ->label(__('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.request-date-from'))
                                    ->date()
                                    ->icon('heroicon-o-calendar'),

                                TextEntry::make('request_date_to')
                                    ->label(__('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.request-date-to'))
                                    ->date()
                                    ->hidden(fn ($record) => $record->request_unit_half)
                                    ->icon('heroicon-o-calendar'),

                                TextEntry::make('request_date_from_period')
                                    ->label(__('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.period'))
                                    ->visible(fn ($record) => $record->request_unit_half)
                                    ->icon('heroicon-o-sun'),

                                TextEntry::make('private_name')
                                    ->label(__('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.description'))
                                    ->icon('heroicon-o-document-text'),

                                TextEntry::make('duration_display')
                                    ->label(__('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.requested-days'))
                                    ->formatStateUsing(function ($record) {
                                        if ($record->request_unit_half) {
                                            return __('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.day', ['day' => '0.5']);
                                        }

                                        $startDate = Carbon::parse($record->request_date_from);
                                        $endDate = $record->request_date_to ? Carbon::parse($record->request_date_to) : $startDate;

                                        return __('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.days', ['days' => ($startDate->diffInDays($endDate) + 1)]);
                                    })
                                    ->icon('heroicon-o-calendar-days'),

                                ImageEntry::make('attachment')
                                    ->label(__('time-off::filament/clusters/my-time/resources/my-time-off.infolist.entries.attachment'))
                                    ->visible(fn ($record) => $record->holidayStatus?->support_document),
                                ...LeaveCancellationActions::infolistEntries(),
                            ]),
                    ]),
            ])
            ->columns(1);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListTimeOff::route('/'),
            'create' => CreateTimeOff::route('/create'),
            'edit'   => EditTimeOff::route('/{record}/edit'),
            'view'   => ViewTimeOff::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Company scoping alone let any user holding the list permission see
        // every employee's leave requests. Also restrict to the requesting
        // user's HR hierarchy (self + reports + managed department/team),
        // matching the record-level check in LeavePolicy.
        // A multi-company user sees each of their companies this way; the
        // Company filter starts on the active one.
        $user = Auth::user();

        return static::withListRelations(CurrentCompany::scopeMemberships(parent::getEloquentQuery())
            ->whereIn(
                'employee_id',
                $user instanceof User ? app(HrHierarchyService::class)->visibleEmployeeIdsAcrossMemberships($user) : [],
            ));
    }

    /**
     * Everything the list columns and row actions read, loaded once per page
     * instead of per row: the employee and its hierarchy chain (policies,
     * cancellation rules, the approval engine's hierarchy routing), the leave
     * type, and the approval request with its workflow steps and requester
     * chain (approve / refuse / return visibility). The approval request's
     * subject is the leave itself, so it is set from the row rather than
     * loaded again.
     *
     * @param  Builder<Leave>  $query
     * @return Builder<Leave>
     */
    public static function withListRelations(Builder $query): Builder
    {
        $chain = ['user', 'parent.user', 'department.manager.user', 'team.manager.user'];

        return $query
            ->with([
                ...array_map(fn (string $relation): string => 'employee.'.$relation, $chain),
                'holidayStatus',
                'approvalRequest.workflow.steps',
                ...array_map(fn (string $relation): string => 'approvalRequest.requester.employee.'.$relation, $chain),
            ])
            ->afterQuery(function ($leaves) {
                foreach ($leaves as $leave) {
                    $approval = $leave instanceof Leave ? $leave->approvalRequest : null;
                    if ($approval && $approval->subject_type === $leave->getMorphClass() && (int) $approval->subject_id === (int) $leave->getKey()) {
                        $approval->setRelation('subject', $leave);
                    }
                }

                return $leaves;
            });
    }
}
