<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource\Pages\ManagePerformanceReviews;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Enums\NavigationGroup;

class PerformanceReviewResource extends Resource
{
    protected static ?string $model = PerformanceReview::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Performance Reviews';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('cycle_id')->relationship('cycle', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id))->disabled()->dehydrated(),
            Select::make('employee_id')->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id))->disabled()->dehydrated(),
            Select::make('reviewer_id')->relationship('reviewer', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id))->searchable()->preload(),
            TextInput::make('self_rating')->numeric()->minValue(0)->maxValue(5),
            TextInput::make('manager_rating')->numeric()->minValue(0)->maxValue(5),
            Select::make('status')->options([
                'self_review' => 'Self review', 'manager_review' => 'Manager review', 'completed' => 'Completed',
            ])->required(),
            KeyValue::make('competency_ratings')->columnSpanFull(),
            Textarea::make('self_comments')->columnSpanFull(),
            Textarea::make('manager_comments')->columnSpanFull(),
            Textarea::make('improvement_plan')->columnSpanFull(),
            Textarea::make('promotion_recommendation')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('cycle.name')->searchable()->sortable(),
            TextColumn::make('employee.name')->searchable()->sortable(),
            TextColumn::make('reviewer.name')->label('Manager reviewer')->placeholder('—'),
            TextColumn::make('self_rating')->numeric(decimalPlaces: 2)->placeholder('—'),
            TextColumn::make('manager_rating')->numeric(decimalPlaces: 2)->placeholder('—'),
            TextColumn::make('status')->badge(),
            TextColumn::make('completed_at')->dateTime()->placeholder('Pending'),
        ])->filters([
            SelectFilter::make('status')->options([
                'self_review' => 'Self review', 'manager_review' => 'Manager review', 'completed' => 'Completed',
            ]),
        ])->recordActions([
            Action::make('submit_self_review')
                ->label('Submit Self Review')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn (PerformanceReview $record): bool => $record->status === 'self_review'
                    && (int) $record->employee?->user_id === (int) Auth::id())
                ->schema([
                    TextInput::make('self_rating')->numeric()->minValue(0)->maxValue(5)->required(),
                    Textarea::make('self_comments')->columnSpanFull(),
                ])
                ->action(function (PerformanceReview $record, array $data): void {
                    try {
                        app(PerformanceService::class)->submitSelfReview($record, $record->employee, (float) $data['self_rating'], $data['self_comments'] ?? null);
                        Notification::make()->success()->title('Self review submitted')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Could not submit self review')->body($e->getMessage())->send();
                    }
                }),
            Action::make('complete_manager_review')
                ->label('Complete Manager Review')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (PerformanceReview $record): bool => $record->status === 'manager_review'
                    && (int) $record->reviewer?->user_id === (int) Auth::id())
                ->schema([
                    TextInput::make('manager_rating')->numeric()->minValue(0)->maxValue(5)->required(),
                    KeyValue::make('competency_ratings')->columnSpanFull(),
                    Textarea::make('manager_comments')->columnSpanFull(),
                    Textarea::make('improvement_plan')->columnSpanFull(),
                    Textarea::make('promotion_recommendation')->columnSpanFull(),
                ])
                ->action(function (PerformanceReview $record, array $data): void {
                    try {
                        app(PerformanceService::class)->completeManagerReview(
                            $record,
                            $record->reviewer,
                            (float) $data['manager_rating'],
                            $data['manager_comments'] ?? null,
                            [
                                'competency_ratings'       => $data['competency_ratings'] ?? null,
                                'improvement_plan'         => $data['improvement_plan'] ?? null,
                                'promotion_recommendation' => $data['promotion_recommendation'] ?? null,
                            ],
                        );
                        Notification::make()->success()->title('Manager review completed')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Could not complete manager review')->body($e->getMessage())->send();
                    }
                }),
            EditAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);

        $user = Auth::user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can('hr_view_all_records') || $user->can(HrPermissions::ManagePerformance)) {
            return $query;
        }

        $hierarchy = app(HrHierarchyService::class);
        $visibleEmployeeIds = $hierarchy->visibleEmployeeIds($user, (int) $user->default_company_id);

        return $query->where(function (Builder $q) use ($visibleEmployeeIds, $user): void {
            $q->whereIn('employee_id', $visibleEmployeeIds);
            if ($user->employee) {
                $q->orWhere('reviewer_id', $user->employee->id);
            }
        });
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user !== null && (
            $user->can(HrPermissions::ManagePerformance)
            || $user->can(HrPermissions::ViewPerformance)
            || $user->employee !== null
        );
    }

    public static function getPages(): array
    {
        return ['index' => ManagePerformanceReviews::route('/')];
    }
}
