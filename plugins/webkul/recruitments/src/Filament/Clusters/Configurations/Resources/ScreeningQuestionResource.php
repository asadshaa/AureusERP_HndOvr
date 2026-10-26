<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Recruitment\Enums\ScreeningQuestionType;
use Webkul\Recruitment\Filament\Clusters\Configurations;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\ScreeningQuestionResource\Pages\ManageScreeningQuestions;
use Webkul\Recruitment\Models\ScreeningQuestion;

/**
 * The company's screening question library. HR writes and edits the questions
 * here; each job then picks from the library (and can edit its own copy) on
 * the job's "Screening questions" tab.
 */
class ScreeningQuestionResource extends Resource
{
    protected static ?string $model = ScreeningQuestion::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $cluster = Configurations::class;

    protected static ?string $modelLabel = 'Screening question';

    protected static ?string $navigationLabel = 'Screening questions';

    public static function getNavigationGroup(): string
    {
        return __('recruitments::filament/clusters/configurations/resources/refuse-reason.navigation.group');
    }

    /**
     * Question fields shared by the library and the job's questions tab.
     *
     * @return array<int, mixed>
     */
    public static function questionFields(): array
    {
        $type = fn (Get $get): ?ScreeningQuestionType => ScreeningQuestionType::tryFrom((string) $get('type'));

        return [
            Select::make('type')
                ->label('Answer type')
                ->options(ScreeningQuestionType::options())
                ->default(ScreeningQuestionType::YES_NO->value)
                ->required()
                ->live(),
            Textarea::make('label')
                ->label('Question')
                ->required()
                ->maxLength(500)
                ->rows(2)
                ->columnSpanFull(),
            TextInput::make('help_text')
                ->label('Help text (shown under the question)')
                ->maxLength(1000)
                ->columnSpanFull(),
            TagsInput::make('options')
                ->label('Answer options')
                ->placeholder('Type an option and press Enter')
                ->visible(fn (Get $get): bool => (bool) $type($get)?->hasOptions())
                ->required(fn (Get $get): bool => (bool) $type($get)?->hasOptions())
                ->live()
                ->columnSpanFull(),
            Toggle::make('is_required')
                ->label('Applicants must answer')
                ->default(true),
            Fieldset::make('Must-have rule (applicants who fail it are flagged "Not qualified", never rejected automatically)')
                ->visible(fn (Get $get): bool => (bool) $type($get)?->supportsKnockout())
                ->schema([
                    Select::make('knockout.required_answer')
                        ->label('Applicant must answer')
                        ->options(['yes' => 'Yes', 'no' => 'No'])
                        ->placeholder('No rule')
                        ->visible(fn (Get $get): bool => $type($get) === ScreeningQuestionType::YES_NO),
                    TextInput::make('knockout.min')
                        ->label('Minimum')
                        ->numeric()
                        ->visible(fn (Get $get): bool => $type($get) === ScreeningQuestionType::NUMBER),
                    TextInput::make('knockout.max')
                        ->label('Maximum')
                        ->numeric()
                        ->visible(fn (Get $get): bool => $type($get) === ScreeningQuestionType::NUMBER),
                    Select::make('knockout.accepted')
                        ->label('Accepted answers')
                        ->multiple()
                        ->options(fn (Get $get): array => collect((array) $get('options'))->filter()->mapWithKeys(fn (string $option): array => [$option => $option])->all())
                        ->placeholder('Any answer is fine')
                        ->visible(fn (Get $get): bool => $type($get) === ScreeningQuestionType::SINGLE_CHOICE),
                    Select::make('knockout.must_include')
                        ->label('Answers that must be selected')
                        ->multiple()
                        ->options(fn (Get $get): array => collect((array) $get('options'))->filter()->mapWithKeys(fn (string $option): array => [$option => $option])->all())
                        ->placeholder('No requirement')
                        ->visible(fn (Get $get): bool => $type($get) === ScreeningQuestionType::MULTI_CHOICE),
                ]),
        ];
    }

    /** One-line description of a question's must-have rule, for tables. */
    public static function describeRule(?array $knockout): string
    {
        $knockout = (array) $knockout;

        return match (true) {
            isset($knockout['required_answer'])                 => 'Must answer '.ucfirst($knockout['required_answer']),
            isset($knockout['min']) && isset($knockout['max'])  => "Between {$knockout['min']} and {$knockout['max']}",
            isset($knockout['min'])                             => "At least {$knockout['min']}",
            isset($knockout['max'])                             => "At most {$knockout['max']}",
            isset($knockout['accepted'])                        => 'Accepted: '.implode(', ', $knockout['accepted']),
            isset($knockout['must_include'])                    => 'Must include: '.implode(', ', $knockout['must_include']),
            default                                             => '—',
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...static::questionFields(),
            Toggle::make('is_active')->label('Active (offered when adding questions to a job)')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('label')->label('Question')->wrap()->searchable(),
                TextColumn::make('type')->label('Answer type')->badge(),
                IconColumn::make('is_required')->label('Required')->boolean(),
                TextColumn::make('knockout')
                    ->label('Must-have rule')
                    ->state(fn (ScreeningQuestion $record): string => static::describeRule($record->knockout))
                    ->wrap(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScreeningQuestions::route('/'),
        ];
    }
}
