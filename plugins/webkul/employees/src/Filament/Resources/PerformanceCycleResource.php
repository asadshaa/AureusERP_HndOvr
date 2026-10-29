<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\AppraisalTemplateResource;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource\Pages\CycleOverview;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource\Pages\ManagePerformanceCycles;
use Webkul\Employee\Models\AppraisalTemplate;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * Performance cycles of the active company (HR Parity 3.2): HR picks the
 * appraisal template, the period, who takes part (all active employees or
 * departments) and the self-review / manager-review deadlines, launches the
 * cycle, follows it on the Overview and closes it to share the results.
 */
class PerformanceCycleResource extends Resource
{
    protected static ?string $model = PerformanceCycle::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Performance Cycles';
    }

    public static function form(Schema $schema): Schema
    {
        $launched = fn (?PerformanceCycle $record): bool => $record !== null && $record->status !== AppraisalKinds::CycleDraft;

        return $schema->components([
            Hidden::make('company_id')->default(fn (): ?int => CurrentCompany::id()),
            TextInput::make('name')->required()->maxLength(255)->placeholder('Annual appraisal 2026')->columnSpanFull(),
            DatePicker::make('starts_on')->label('Period from')->required()->native(false),
            DatePicker::make('ends_on')->label('Period to')->required()->afterOrEqual('starts_on')->native(false),
            Select::make('template_id')
                ->label('Appraisal template')
                ->options(fn (): array => AppraisalTemplate::query()
                    ->where('company_id', CurrentCompany::id() ?? 0)
                    ->active()
                    ->orderByDesc('is_default')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->default(fn (): ?int => AppraisalTemplate::preferredFor((int) CurrentCompany::id())?->getKey())
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    $template = $state ? AppraisalTemplate::query()->where('company_id', CurrentCompany::id() ?? 0)->find($state) : null;
                    if ($template) {
                        $set('results_visibility', $template->results_visibility);
                    }
                })
                ->disabled($launched)
                ->helperText('The template is copied when the cycle is launched.'),
            Select::make('results_visibility')
                ->label('The employee sees the manager review')
                ->options(AppraisalKinds::visibilityOptions())
                ->default(fn (): string => AppraisalTemplate::preferredFor((int) CurrentCompany::id())?->results_visibility ?? AppraisalKinds::VisibleAfterClose)
                ->required()
                ->disabled(fn (?PerformanceCycle $record): bool => $record?->isClosed() ?? false),
            DatePicker::make('self_review_due_on')
                ->label('Self reviews due')
                ->required()
                ->native(false),
            DatePicker::make('manager_review_due_on')
                ->label('Manager reviews due')
                ->required()
                ->afterOrEqual('self_review_due_on')
                ->native(false),
            Section::make('Participants')
                ->columnSpanFull()
                ->schema([
                    Radio::make('participant_scope')
                        ->hiddenLabel()
                        ->options(AppraisalKinds::participantScopes())
                        ->default(AppraisalKinds::ParticipantsAll)
                        ->inline()
                        ->live()
                        ->required()
                        ->disabled($launched),
                    Select::make('department_ids')
                        ->label('Departments')
                        ->multiple()
                        ->options(fn (): array => Department::query()
                            ->where('company_id', CurrentCompany::id() ?? 0)
                            ->orderBy('complete_name')
                            ->pluck('complete_name', 'id')
                            ->all())
                        ->visible(fn (Get $get): bool => $get('participant_scope') === AppraisalKinds::ParticipantsDepartments)
                        ->required(fn (Get $get): bool => $get('participant_scope') === AppraisalKinds::ParticipantsDepartments)
                        ->disabled($launched),
                ]),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('template:id,name')
                ->withCount(['reviews', 'reviews as done_count' => fn (Builder $reviews) => $reviews->whereIn('status', [AppraisalKinds::StatusCompleted, AppraisalKinds::StatusAcknowledged])]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (PerformanceCycle $record): string => $record->periodLabel()),
                TextColumn::make('template.name')->label('Template')->placeholder('Overall rating only'),
                TextColumn::make('self_review_due_on')->label('Self reviews due')->date('j M Y')->placeholder('—')->sortable(),
                TextColumn::make('manager_review_due_on')->label('Manager reviews due')->date('j M Y')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AppraisalKinds::cycleStatuses()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        AppraisalKinds::CycleActive => 'warning',
                        AppraisalKinds::CycleClosed => 'success',
                        default                     => 'gray',
                    }),
                TextColumn::make('reviews_count')
                    ->label('Done')
                    ->formatStateUsing(fn (PerformanceCycle $record): string => $record->reviews_count > 0 ? "{$record->done_count} / {$record->reviews_count}" : '—'),
            ])
            ->recordUrl(fn (PerformanceCycle $record): ?string => $record->status !== AppraisalKinds::CycleDraft && static::canViewCycle($record)
                ? CycleOverview::getUrl(['record' => $record])
                : null)
            ->recordActions([
                Action::make('overview')
                    ->label('Overview')
                    ->icon('heroicon-o-presentation-chart-bar')
                    ->color('gray')
                    ->visible(fn (PerformanceCycle $record): bool => $record->status !== AppraisalKinds::CycleDraft && static::canViewCycle($record))
                    ->url(fn (PerformanceCycle $record): string => CycleOverview::getUrl(['record' => $record])),
                Action::make('launch')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Launch this cycle?')
                    ->modalDescription(fn (PerformanceCycle $record): string => static::launchSummary($record))
                    ->visible(fn (PerformanceCycle $record): bool => (bool) Auth::user()?->can('launch', $record))
                    ->action(function (PerformanceCycle $record): void {
                        try {
                            $user = static::user();
                            abort_unless($user->can('launch', $record), 403);
                            $count = app(PerformanceService::class)->launch($record, $user)->count();
                            Notification::make()->success()->title("Performance cycle launched for {$count} employees")->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Could not launch the cycle')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('close')
                    ->label('Close & share results')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (PerformanceCycle $record): string => static::closeSummary($record))
                    ->visible(fn (PerformanceCycle $record): bool => (bool) Auth::user()?->can('close', $record))
                    ->action(fn (PerformanceCycle $record) => static::closeCycle($record)),
                EditAction::make()->visible(fn (PerformanceCycle $record): bool => ! $record->isClosed() && (bool) Auth::user()?->can('update', $record)),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('templates')
                    ->label('Appraisal templates')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->visible(fn (): bool => AppraisalTemplateResource::canViewAny())
                    ->url(fn (): string => AppraisalTemplateResource::getUrl('index')),
                CreateAction::make()->label('New cycle'),
            ]);
    }

    public static function launchSummary(PerformanceCycle $record): string
    {
        $participants = app(PerformanceService::class)->participants($record);
        $withoutLogin = $participants->filter(fn ($employee): bool => $employee->user?->is_active !== true)->count();

        return "{$participants->count()} employees take part. Each gets the self review now (due "
            .($record->self_review_due_on?->format('j M') ?? 'no deadline').'); their line manager reviews them next (due '
            .($record->manager_review_due_on?->format('j M') ?? 'no deadline').').'
            .($withoutLogin > 0 ? " {$withoutLogin} without a login go straight to the manager review." : '');
    }

    public static function closeSummary(PerformanceCycle $record): string
    {
        $stats = app(PerformanceService::class)->cycleStats($record);
        $open = ($stats['statuses'][AppraisalKinds::StatusSelfReview] ?? 0) + ($stats['statuses'][AppraisalKinds::StatusManagerReview] ?? 0);

        return ($record->showsResultsAfterManagerReview() ? '' : "{$stats['done']} reviewed appraisals are shared with the employees, who acknowledge them. ")
            .($open > 0 ? "{$open} appraisals are not finished and stay unfinished. " : '')
            .'No more reviews or calibration after closing.';
    }

    public static function closeCycle(PerformanceCycle $record): void
    {
        try {
            $user = static::user();
            abort_unless($user->can('close', $record), 403);
            $result = app(PerformanceService::class)->closeCycle($record, $user);
            Notification::make()->success()->title('Cycle closed')->body("{$result['shared']} employees were told their appraisal is ready.")->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Could not close the cycle')->body($e->getMessage())->send();
        }
    }

    public static function canViewCycle(PerformanceCycle $record): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(AppraisalAccess::class)->canViewCycle($user, $record);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', CurrentCompany::id() ?? 0);
    }

    /** The cycle list holds no personal data: HR, and read-only hr_view_performance holders (PerformanceCyclePolicy::viewAny()). */
    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('viewAny', PerformanceCycle::class);
    }

    protected static function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public static function getPages(): array
    {
        return [
            'index'    => ManagePerformanceCycles::route('/'),
            'overview' => CycleOverview::route('/{record}/overview'),
        ];
    }
}
