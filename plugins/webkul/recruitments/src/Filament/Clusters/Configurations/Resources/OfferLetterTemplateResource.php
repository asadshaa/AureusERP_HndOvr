<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Recruitment\Filament\Clusters\Configurations;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\OfferLetterTemplateResource\Pages\ManageOfferLetterTemplates;
use Webkul\Recruitment\Models\OfferLetterTemplate;
use Webkul\Recruitment\Services\OfferLetterRenderer;
use Webkul\Security\Services\CurrentCompany;

/**
 * Recruitment > Configurations > Offer letter templates (HR parity 3.6): the
 * active company's offer letters, rich text with {{placeholders}} that
 * OfferLetterRenderer fills in (the editor's merge-tag button inserts
 * them). HR of the company only (OfferLetterTemplatePolicy). The page also
 * holds the company's CEO approval threshold.
 */
class OfferLetterTemplateResource extends Resource
{
    protected static ?string $model = OfferLetterTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $cluster = Configurations::class;

    protected static ?string $modelLabel = 'Offer letter template';

    protected static ?string $navigationLabel = 'Offer letter templates';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('recruitments::filament/clusters/configurations/resources/refuse-reason.navigation.group');
    }

    public static function getEloquentQuery(): Builder
    {
        return CurrentCompany::scope(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        $placeholders = collect(OfferLetterRenderer::PLACEHOLDERS)
            ->map(fn (string $label, string $tag): string => '{{'.$tag.'}} '.$label)
            ->implode(' · ');

        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(150),
            TextInput::make('email_subject')
                ->label(__('Email subject'))
                ->placeholder(OfferLetterRenderer::defaultEmailSubject())
                ->helperText(__('Placeholders work here too.'))
                ->maxLength(255),
            Section::make(__('Letter'))
                ->description(__('Placeholders: :list', ['list' => $placeholders]))
                ->schema([
                    RichEditor::make('body')
                        ->hiddenLabel()
                        ->required()
                        ->toolbarButtons([
                            ['bold', 'italic', 'underline', 'link'],
                            ['h2', 'h3'],
                            ['alignStart', 'alignCenter', 'alignEnd'],
                            ['bulletList', 'orderedList'],
                            ['table', 'mergeTags'],
                            ['undo', 'redo'],
                        ])
                        ->mergeTags(OfferLetterRenderer::PLACEHOLDERS)
                        ->default(OfferLetterRenderer::defaultTemplateBody()),
                ])
                ->columnSpanFull(),
            Toggle::make('is_default')
                ->label(__('Default template of the company'))
                ->helperText(__('Used when an offer does not pick a template.')),
            Toggle::make('is_active')
                ->label(__('Active'))
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('email_subject')->label(__('Email subject'))->limit(60)->placeholder(OfferLetterRenderer::defaultEmailSubject()),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('updated_at')->label(__('Updated'))->since(),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('5xl'),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOfferLetterTemplates::route('/'),
        ];
    }
}
