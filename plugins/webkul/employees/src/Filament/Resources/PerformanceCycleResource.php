<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource\Pages\ManagePerformanceCycles;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Enums\NavigationGroup;

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
        return $schema->components([
            Hidden::make('company_id')->default(fn (): ?int => Auth::user()?->default_company_id),
            TextInput::make('name')->required()->maxLength(255),
            DatePicker::make('starts_on')->required()->native(false),
            DatePicker::make('ends_on')->required()->afterOrEqual('starts_on')->native(false),
            Select::make('status')->options([
                'draft' => 'Draft', 'active' => 'Active', 'closed' => 'Closed',
            ])->default('draft')->required(),
            KeyValue::make('competency_framework')->label('Competency framework')->columnSpanFull(),
            KeyValue::make('settings')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('starts_on')->date()->sortable(),
            TextColumn::make('ends_on')->date()->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('reviews_count')->counts('reviews')->label('Reviews'),
        ])->recordActions([
            Action::make('launch')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (PerformanceCycle $record): bool => $record->status === 'draft')
                ->action(function (PerformanceCycle $record): void {
                    $count = app(PerformanceService::class)->launch($record, Auth::user())->count();
                    Notification::make()->success()->title("Performance cycle launched for {$count} employees")->send();
                }),
            EditAction::make(),
            DeleteAction::make()->visible(fn (PerformanceCycle $record): bool => $record->status === 'draft'),
        ])->headerActions([CreateAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user !== null && ($user->can(HrPermissions::ManagePerformance) || $user->can(HrPermissions::ViewPerformance));
    }

    public static function getPages(): array
    {
        return ['index' => ManagePerformanceCycles::route('/')];
    }
}
