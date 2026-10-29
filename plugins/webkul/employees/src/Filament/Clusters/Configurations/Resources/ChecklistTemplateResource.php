<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Filament\Clusters\Configurations;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ChecklistTemplateResource\Pages\ListChecklistTemplates;
use Webkul\Employee\Models\ChecklistTemplate;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Onboarding and exit checklist templates of the active company. Starting a
 * checklist copies the template's tasks, so edits here apply to checklists
 * started afterwards.
 */
class ChecklistTemplateResource extends Resource
{
    protected static ?string $model = ChecklistTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $cluster = Configurations::class;

    protected static ?string $modelLabel = 'Checklist template';

    protected static ?string $navigationLabel = 'Checklist templates';

    public static function getNavigationGroup(): string
    {
        return __('employees::filament/clusters/configurations/resources/departure-reason.navigation.group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', CurrentCompany::id() ?? 0);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)->schema([
                    TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                    Select::make('type')
                        ->options(ChecklistKinds::types())
                        ->required()
                        ->disabled(fn (?ChecklistTemplate $record): bool => $record !== null),
                ]),
                Textarea::make('description')->rows(2)->maxLength(1000),
                Grid::make(2)->schema([
                    Toggle::make('is_active')->label('Active')->default(true),
                    Toggle::make('is_default')
                        ->label('Default for its type')
                        ->helperText('Used when onboarding starts by itself and preselected for "Start exit".'),
                ]),
                Repeater::make('tasks')
                    ->relationship()
                    ->orderColumn('sort')
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                    ->defaultItems(0)
                    ->addActionLabel('Add task')
                    ->schema([
                        Grid::make(6)->schema([
                            TextInput::make('title')->required()->maxLength(255)->columnSpan(3),
                            Select::make('responsible_role')
                                ->label('Who')
                                ->options(ChecklistKinds::roles())
                                ->required()
                                ->default(ChecklistKinds::RoleHr)
                                ->live()
                                ->columnSpan(1),
                            TextInput::make('due_offset_days')
                                ->label('Due (days)')
                                ->helperText('From the joining date / last working day; negative = before.')
                                ->numeric()
                                ->integer()
                                ->minValue(-365)
                                ->maxValue(365)
                                ->default(0)
                                ->required()
                                ->columnSpan(2),
                            Select::make('responsible_user_id')
                                ->label('Person')
                                ->helperText('Empty: the HR team.')
                                ->options(fn (): array => CurrentCompany::scopeUsersOf(User::query(), (int) CurrentCompany::id())
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->visible(fn (Get $get): bool => in_array($get('responsible_role'), [ChecklistKinds::RoleHr, ChecklistKinds::RoleItAdmin], true))
                                ->columnSpan(3),
                            Select::make('document_type_id')
                                ->label('Completed by document')
                                ->options(fn (): array => DocumentType::query()
                                    ->where('company_id', CurrentCompany::id() ?? 0)
                                    ->where('is_active', true)
                                    ->orderBy('sort')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->columnSpan(3),
                            Select::make('action_key')
                                ->label('Handled by the system')
                                ->options(ChecklistKinds::actions())
                                ->columnSpan(3),
                            Textarea::make('description')->label('Instructions')->rows(1)->maxLength(1000)->columnSpan(3),
                        ]),
                    ]),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (ChecklistTemplate $record): ?string => $record->description),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ChecklistKinds::typeLabel($state))
                    ->color(fn (?string $state): string => $state === ChecklistKinds::Offboarding ? 'danger' : 'info'),
                TextColumn::make('tasks_count')->label('Tasks')->counts('tasks'),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->options(ChecklistKinds::types()),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('6xl'),
                DeleteAction::make(),
            ])
            ->defaultSort('type');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecklistTemplates::route('/'),
        ];
    }
}
