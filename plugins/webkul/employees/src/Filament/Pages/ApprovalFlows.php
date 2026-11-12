<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Services\Reporting\ApprovalFlowService;
use Webkul\Employee\Support\ReportingPurposes;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Actions\HelpAction;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;

/**
 * Employees -> Approval flows: HR changes who approves each step of the
 * company's approval flows (leave, claims, advances ...). Every rule lives in
 * ApprovalFlowService; this page only shows the flows and calls it.
 */
class ApprovalFlows extends Page implements HasTable
{
    use InteractsWithTable;

    private const UserKind = '_user';

    private const RoleKind = '_role';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'employees/approval-flows';

    protected string $view = 'employees::filament.pages.approval-flows';

    /** @var array<int, int>|null waiting requests per flow, loaded once per request */
    protected ?array $pendingCache = null;

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();
        $companyId = CurrentCompany::id($user);

        return $user instanceof User && $companyId !== null && app(ApprovalFlowService::class)->canManage($user, $companyId);
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Approval flows';
    }

    public function getTitle(): string
    {
        return 'Approval flows';
    }

    public function getSubheading(): ?string
    {
        return 'Who approves what in your company. Change the approvers of a step, or switch a flow off. Requests already waiting keep their steps.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->service()->flowsFor($this->companyId()))
            ->columns([
                TextColumn::make('request_type')
                    ->label('Flow')
                    ->formatStateUsing(fn (string $state): string => ReportingPurposes::requestTypeLabel($state))
                    ->description(fn (ApprovalWorkflow $record): string => (string) $record->name)
                    ->weight('medium')
                    ->searchable(['request_type', 'name'])
                    ->sortable(),
                TextColumn::make('steps_summary')
                    ->label('Steps')
                    ->state(fn (ApprovalWorkflow $record): string => $this->service()->summarise($record))
                    ->wrap(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'On' : 'Off')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('waiting_now')
                    ->label('Waiting now')
                    ->state(fn (ApprovalWorkflow $record): int => $this->pendingCounts()[(int) $record->id] ?? 0)
                    ->alignEnd(),
                TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->since()
                    ->tooltip(fn (ApprovalWorkflow $record): ?string => $record->updated_at?->format('j M Y, H:i'))
                    ->sortable(),
            ])
            ->defaultSort('request_type')
            ->recordActions([
                $this->editAction(),
                $this->switchAction(),
            ])
            ->paginated([25, 50])
            ->emptyStateIcon('heroicon-o-queue-list')
            ->emptyStateHeading('No approval flows yet')
            ->emptyStateDescription('Create a flow to say who approves a kind of request.');
    }

    protected function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->slideOver()
            ->modalWidth('4xl')
            ->modalHeading(fn (ApprovalWorkflow $record): string => 'Edit '.ReportingPurposes::requestTypeLabel($record->request_type))
            ->modalSubmitActionLabel('Save changes')
            ->schema(fn (ApprovalWorkflow $record): array => [
                TextInput::make('name')->label('Name of the flow')->required()->maxLength(255),
                ...$this->stepsField($this->service()->pendingCount($record)),
            ])
            ->fillForm(fn (ApprovalWorkflow $record): array => [
                'name'  => $record->name,
                'steps' => $record->steps->sortBy('sequence')->map(fn (ApprovalStep $step): array => [
                    'id'                 => $step->id,
                    'name'               => $step->name,
                    'kind'               => $step->approver_user_id ? self::UserKind : ($step->approver_role_id ? self::RoleKind : $step->hierarchy_route),
                    'approver_user_id'   => $step->approver_user_id,
                    'approver_role_id'   => $step->approver_role_id,
                    'required_approvals' => $step->required_approvals ?: 1,
                    'sla_hours'          => $step->sla_hours,
                ])->values()->all(),
            ])
            ->action(function (array $data, ApprovalWorkflow $record, Action $action): void {
                $this->persist($action, fn () => $this->service()->save($this->user(), $record, $this->toServiceData($data)), 'Flow saved');
            });
    }

    protected function switchAction(): Action
    {
        return Action::make('switch')
            ->label(fn (ApprovalWorkflow $record): string => $record->is_active ? 'Switch off' : 'Switch on')
            ->icon(fn (ApprovalWorkflow $record): string => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->color(fn (ApprovalWorkflow $record): string => $record->is_active ? 'gray' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (ApprovalWorkflow $record): string => ($record->is_active ? 'Switch off ' : 'Switch on ').ReportingPurposes::requestTypeLabel($record->request_type).'?')
            ->modalDescription(fn (ApprovalWorkflow $record): string => $record->is_active
                ? 'While this flow is off, nobody can send a new request of this kind. Requests already waiting are not affected.'
                : 'People can send new requests of this kind again.')
            ->action(function (ApprovalWorkflow $record, Action $action): void {
                $this->persist($action, fn () => $this->service()->setActive($this->user(), $record, ! $record->is_active), 'Flow updated');
            });
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create flow')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => $this->service()->availableRequestTypes($this->companyId()) !== [])
                ->slideOver()
                ->modalWidth('4xl')
                ->modalSubmitActionLabel('Create flow')
                ->schema(fn (): array => [
                    Select::make('request_type')
                        ->label('What is it for?')
                        ->helperText('Only kinds of request that have no flow yet are listed.')
                        ->options(fn (): array => $this->service()->availableRequestTypes($this->companyId()))
                        ->searchable()
                        ->required(),
                    TextInput::make('name')->label('Name of the flow')->required()->maxLength(255),
                    ...$this->stepsField(0),
                ])
                ->action(function (array $data, Action $action): void {
                    $this->persist($action, fn () => $this->service()->save($this->user(), null, $this->toServiceData($data)), 'Flow created');
                }),
            HelpAction::make('approval-flows'),
        ];
    }

    /**
     * @return array<int, Repeater>
     */
    protected function stepsField(int $pending): array
    {
        $locked = $pending > 0;

        return [
            Repeater::make('steps')
                ->label('Steps, in the order they approve')
                ->helperText($locked ? $this->service()->lockMessage($pending) : 'Each step needs one kind of approver. Drag the steps to change their order.')
                ->addActionLabel('Add a step')
                ->addable(! $locked)
                ->deletable(! $locked)
                ->reorderable(! $locked)
                ->minItems(1)
                ->defaultItems(1)
                ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null) ? $state['name'] : null)
                ->columns(2)
                ->schema([
                    Hidden::make('id'),
                    TextInput::make('name')->label('Step name')->placeholder('For example: Manager approval')->required()->maxLength(255),
                    Select::make('kind')
                        ->label('Who approves')
                        ->options([
                            'By position' => ApprovalFlowService::Routes,
                            'By choice'   => [
                                self::UserKind => 'One named person',
                                self::RoleKind => 'Anyone who has a role',
                            ],
                        ])
                        ->required()
                        ->live(),
                    Select::make('approver_user_id')
                        ->label('Person')
                        ->searchable()
                        ->options(fn (): array => $this->userOptions())
                        ->getSearchResultsUsing(fn (string $search): array => $this->userOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('name'))
                        ->visible(fn ($get): bool => $get('kind') === self::UserKind)
                        ->required(fn ($get): bool => $get('kind') === self::UserKind),
                    Select::make('approver_role_id')
                        ->label('Role')
                        ->searchable()
                        ->options(fn (): array => $this->roleOptions())
                        ->visible(fn ($get): bool => $get('kind') === self::RoleKind)
                        ->required(fn ($get): bool => $get('kind') === self::RoleKind),
                    TextInput::make('required_approvals')
                        ->label('How many people must approve')
                        ->helperText('Only for roles: how many of the people with the role need to say yes.')
                        ->numeric()->integer()->minValue(1)->default(1)
                        ->visible(fn ($get): bool => $get('kind') === self::RoleKind),
                    TextInput::make('sla_hours')
                        ->label('Remind after (hours)')
                        ->helperText('Leave empty to use the usual reminder time.')
                        ->numeric()->integer()->minValue(1),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function toServiceData(array $data): array
    {
        $data['steps'] = collect($data['steps'] ?? [])->values()->map(function (array $step): array {
            $kind = $step['kind'] ?? null;

            return [
                'id'                 => $step['id'] ?? null,
                'name'               => $step['name'] ?? '',
                'hierarchy_route'    => in_array($kind, [self::UserKind, self::RoleKind, null], true) ? null : $kind,
                'approver_user_id'   => $kind === self::UserKind ? ($step['approver_user_id'] ?? null) : null,
                'approver_role_id'   => $kind === self::RoleKind ? ($step['approver_role_id'] ?? null) : null,
                'required_approvals' => $step['required_approvals'] ?? 1,
                'sla_hours'          => $step['sla_hours'] ?? null,
            ];
        })->all();

        return $data;
    }

    protected function persist(Action $action, callable $work, string $success): void
    {
        try {
            $work();
        } catch (RuntimeException|InvalidArgumentException $exception) {
            Notification::make()->title('Not saved')->body($exception->getMessage())->danger()->send();
            $action->halt();

            return;
        }

        $this->pendingCache = null;
        Notification::make()->title($success)->success()->send();
    }

    /** @return array<int, string> */
    protected function userOptions(?string $search = null): array
    {
        return CurrentCompany::scopeUsersOf(User::query(), $this->companyId())
            ->where('is_active', true)
            ->when(filled($search), fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> */
    protected function roleOptions(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get()
            ->reject(fn (Role $role): bool => $role->isSystemRole())
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, int> */
    protected function pendingCounts(): array
    {
        return $this->pendingCache ??= $this->service()->pendingCounts(
            ApprovalWorkflow::query()->where('company_id', $this->companyId())->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
    }

    protected function companyId(): int
    {
        return (int) CurrentCompany::id($this->user());
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = CurrentCompany::user();

        return $user;
    }

    protected function service(): ApprovalFlowService
    {
        return app(ApprovalFlowService::class);
    }
}
