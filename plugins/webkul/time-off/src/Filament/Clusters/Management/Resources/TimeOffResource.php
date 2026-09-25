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
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Filament\Actions\ActivityTableAction;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Enums\State;
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
     * Count of leave requests still awaiting a decision (submitted or
     * past the line-manager step, not yet fully approved/refused) within
     * this user's already-scoped visible hierarchy -- getEloquentQuery()
     * below handles the hierarchy scoping, so this only adds the
     * state filter on top of it.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
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
                    ->formatStateUsing(fn (State $state) => $state->getLabel())
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
            ->recordActions([
                ActivityTableAction::make(),
                ActionGroup::make([
                    Action::make('submit_for_approval')
                        ->label('Submit for approval')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->visible(function (Leave $record): bool {
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
                        ->visible(fn (Leave $record): bool => $record->approvalRequest?->status === 'pending'
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
                        ->visible(fn (Leave $record): bool => $record->approvalRequest?->status === 'pending'
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
                    ViewAction::make(),
                    EditAction::make()
                        ->visible(fn (Leave $record): bool => $record->approvalRequest?->status !== 'pending'
                            && $record->state !== State::VALIDATE_TWO),
                    DeleteAction::make()
                        ->visible(fn (Leave $record): bool => $record->approvalRequest?->status !== 'pending'
                            && $record->state !== State::VALIDATE_TWO)
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
                        ->successNotification(
                            Notification::make()
                                ->success()
                                ->title(__('time-off::filament/clusters/management/resources/time-off.table.bulk-actions.delete.notification.title'))
                                ->body(__('time-off::filament/clusters/management/resources/time-off.table.bulk-actions.delete.notification.body'))
                        ),
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
        $user = Auth::user();
        $companyId = (int) $user?->default_company_id;

        return parent::getEloquentQuery()
            ->where('company_id', $companyId)
            ->whereIn(
                'employee_id',
                $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : [],
            );
    }
}
