<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Filament\Clusters\Configurations;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\AppraisalTemplateResource\Pages\ListAppraisalTemplates;
use Webkul\Employee\Models\AppraisalTemplate;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Services\CurrentCompany;

/**
 * Appraisal forms of the active company (HR Parity 3.2): sections of
 * competencies / KPIs with weights, the 1-5 scale labels, the rating bands,
 * next-period goals and when the employee sees the manager review.
 * Launching a cycle copies the template, so edits apply to cycles launched
 * afterwards.
 */
class AppraisalTemplateResource extends Resource
{
    protected static ?string $model = AppraisalTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?string $cluster = Configurations::class;

    protected static ?string $modelLabel = 'Appraisal template';

    protected static ?string $navigationLabel = 'Appraisal templates';

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
                Grid::make(4)->schema([
                    TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                    Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                    Toggle::make('is_default')
                        ->label('Default')
                        ->helperText('Preselected for new cycles.')
                        ->inline(false),
                ]),
                Textarea::make('description')->rows(2)->maxLength(1000),
                Section::make('Sections and items')
                    ->description('Each item is rated 1-5 by the employee and the manager. The score is the weighted average of the item ratings, so weights are relative (they need not add up to 100).')
                    ->schema([
                        Repeater::make('sections')
                            ->hiddenLabel()
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->addActionLabel('Add section')
                            ->minItems(1)
                            ->required()
                            ->schema([
                                Hidden::make('key'),
                                Grid::make(2)->schema([
                                    TextInput::make('title')->required()->maxLength(120),
                                    TextInput::make('description')->maxLength(500),
                                ]),
                                Repeater::make('items')
                                    ->label('Competencies / KPIs')
                                    ->reorderable()
                                    ->addActionLabel('Add item')
                                    ->minItems(1)
                                    ->required()
                                    ->schema([
                                        Hidden::make('key'),
                                        Grid::make(6)->schema([
                                            TextInput::make('title')->required()->maxLength(160)->columnSpan(3),
                                            Select::make('type')
                                                ->options(AppraisalKinds::itemTypes())
                                                ->default(AppraisalKinds::ItemCompetency)
                                                ->required()
                                                ->columnSpan(1),
                                            TextInput::make('weight')
                                                ->numeric()
                                                ->minValue(0)
                                                ->maxValue(100)
                                                ->default(10)
                                                ->required()
                                                ->columnSpan(2),
                                            TextInput::make('description')
                                                ->label('What good looks like')
                                                ->maxLength(500)
                                                ->columnSpanFull(),
                                        ]),
                                    ]),
                            ]),
                    ]),
                Section::make('Rating scale')
                    ->description('Labels of the five ratings shown on the form.')
                    ->collapsible()
                    ->columns(5)
                    ->schema(collect(AppraisalKinds::defaultRatingScale())
                        ->map(fn (string $label, int $rating) => TextInput::make("rating_scale.{$rating}")
                            ->label((string) $rating)
                            ->placeholder($label)
                            ->default($label)
                            ->maxLength(60))
                        ->values()
                        ->all()),
                Section::make('Rating bands')
                    ->description('The final score falls in the first band whose minimum it reaches (highest first).')
                    ->collapsible()
                    ->schema([
                        Repeater::make('rating_bands')
                            ->hiddenLabel()
                            ->default(AppraisalKinds::defaultRatingBands())
                            ->addActionLabel('Add band')
                            ->minItems(1)
                            ->columns(3)
                            ->schema([
                                TextInput::make('min')->label('Minimum score')->numeric()->minValue(0)->maxValue(5)->step(0.01)->required(),
                                TextInput::make('label')->required()->maxLength(60),
                                Select::make('color')->options(AppraisalKinds::bandColors())->default('gray')->required(),
                            ]),
                    ]),
                Section::make('Options')
                    ->columns(2)
                    ->schema([
                        Toggle::make('include_goals')
                            ->label('Goals for the next period')
                            ->helperText('The manager sets goals for the next period in the manager review.')
                            ->default(true),
                        Select::make('results_visibility')
                            ->label('The employee sees the manager review')
                            ->options(AppraisalKinds::visibilityOptions())
                            ->default(AppraisalKinds::VisibleAfterClose)
                            ->helperText('Default for new cycles; HR can change it per cycle.')
                            ->required(),
                    ]),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (AppraisalTemplate $record): ?string => $record->description),
                TextColumn::make('items')
                    ->label('Items')
                    ->state(fn (AppraisalTemplate $record): string => $record->itemCount().' in '.count($record->sections ?? []).' section(s)'),
                TextColumn::make('weight')
                    ->label('Total weight')
                    ->state(fn (AppraisalTemplate $record): string => rtrim(rtrim(number_format($record->totalWeight(), 2), '0'), '.')),
                TextColumn::make('cycles_count')->label('Cycles')->counts('cycles'),
                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('6xl'),
                DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppraisalTemplates::route('/'),
        ];
    }
}
