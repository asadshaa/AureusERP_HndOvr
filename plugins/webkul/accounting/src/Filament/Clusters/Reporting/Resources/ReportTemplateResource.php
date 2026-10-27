<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Resources;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Webkul\Accounting\Data\ValidationIssue;
use Webkul\Accounting\Enums\CurrencyMode;
use Webkul\Accounting\Enums\EntityMode;
use Webkul\Accounting\Enums\LayoutType;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\Pages\CreateReportTemplate;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\Pages\EditReportTemplate;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\Pages\ListReportTemplates;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\RelationManagers\ColumnsRelationManager;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\RelationManagers\LinesRelationManager;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportTemplateVersioningService;
use Webkul\Security\Filament\Concerns\ScopedToActiveCompany;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Security\Services\SelectableCompanies;
use Webkul\Support\Models\Company;

class ReportTemplateResource extends Resource
{
    use ScopedToActiveCompany;

    /** Templates with no company are available to every company; only a group owner may change them. */
    protected static bool $includeSharedRecords = true;

    protected static ?string $model = ReportTemplate::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $cluster = Reporting::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    public static function getModelLabel(): string
    {
        return __('accounting::filament/clusters/reporting/resources/report-template.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/reporting/resources/report-template.navigation.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/reporting/resources/report-template.navigation.group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('accounting::filament/clusters/reporting/resources/report-template.form.sections.general'))
                    ->description(fn (?ReportTemplate $record) => $record !== null && ! $record->isDraft()
                        ? __('accounting::filament/clusters/reporting/resources/report-template.form.immutable-notice', [
                            'status' => $record->statusEnum()->getLabel(),
                        ])
                        : null)
                    ->disabled(fn (?ReportTemplate $record) => $record !== null && ! $record->isDraft())
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.name'))
                                    ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.name-help'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('code')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.code'))
                                    ->required()
                                    ->maxLength(255),
                                Select::make('layout_type')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.layout-type'))
                                    ->options(LayoutType::options())
                                    ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.layout-type-help'))
                                    ->required(),
                                Select::make('currency_mode')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.currency-mode'))
                                    ->options(CurrencyMode::options())
                                    ->required(),
                                Select::make('entity_mode')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.entity-mode'))
                                    ->options(EntityMode::options())
                                    ->required(),
                                Select::make('company_id')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.company'))
                                    ->options(fn () => SelectableCompanies::scope(Company::query())->pluck('name', 'id'))
                                    ->default(fn (): ?int => CurrentCompany::id())
                                    ->rule(fn (): Closure => SelectableCompanies::rule())
                                    ->searchable()
                                    ->nullable()
                                    // Only a group owner may make a template shared by all companies.
                                    ->required(fn (): bool => ! CurrentCompany::isGroupOwner())
                                    ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.company-help')),
                            ]),
                        Textarea::make('description')
                            ->label(__('accounting::filament/clusters/reporting/resources/report-template.form.fields.description'))
                            ->rows(3),
                    ]),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.name'))
                    ->description(fn (ReportTemplate $record) => $record->code)
                    ->searchable(['name', 'code'])
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (TemplateStatus $state) => match ($state) {
                        TemplateStatus::PUBLISHED => 'success',
                        TemplateStatus::DRAFT     => 'warning',
                        TemplateStatus::ARCHIVED  => 'gray',
                    })
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.status'))
                    ->sortable(),
                TextColumn::make('version')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.version'))
                    ->prefix('v')
                    ->sortable(),
                TextColumn::make('company.name')
                    ->placeholder(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.all-companies'))
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.company'))
                    ->toggleable(),
                TextColumn::make('layout_type')
                    ->badge()
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.layout'))
                    ->toggleable(),
                TextColumn::make('lines_count')
                    ->counts('lines')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.lines'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')
                    ->placeholder('-')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.created-by'))
                    ->toggleable(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.published-at'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.columns.updated-at'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.filters.status'))
                    ->options(TemplateStatus::options()),
                SelectFilter::make('layout_type')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.filters.layout'))
                    ->options(LayoutType::options()),
                SelectFilter::make('company_id')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.table.filters.company'))
                    ->options(fn () => SelectableCompanies::scope(Company::query())->pluck('name', 'id')),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    static::publishAction(),
                    static::newVersionAction(),
                    static::archiveAction(),
                    DeleteAction::make()
                        ->visible(fn (ReportTemplate $record) => $record->isDraft()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(fn (ReportTemplate $record): bool => static::companyAllowsChange($record)),
                ]),
            ])
            ->defaultSort('sort');
    }

    public static function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('accounting::filament/clusters/reporting/resources/report-template.actions.publish.label'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('accounting::filament/clusters/reporting/resources/report-template.actions.publish.confirm'))
            ->visible(fn (ReportTemplate $record) => $record->isDraft())
            ->authorize(fn (ReportTemplate $record): bool => static::companyAllowsChange($record))
            ->action(function (ReportTemplate $record) {
                $errors = app(ReportTemplateVersioningService::class)->publish($record);

                if ($errors->isEmpty()) {
                    Notification::make()
                        ->success()
                        ->title(__('accounting::filament/clusters/reporting/resources/report-template.actions.publish.published'))
                        ->send();

                    return;
                }

                Notification::make()
                    ->danger()
                    ->title(__('accounting::filament/clusters/reporting/resources/report-template.actions.publish.blocked', ['count' => $errors->count()]))
                    ->body($errors->take(10)->map(fn (ValidationIssue $issue) => $issue->message)->implode("\n"))
                    ->persistent()
                    ->send();
            });
    }

    public static function newVersionAction(): Action
    {
        return Action::make('newVersion')
            ->label(__('accounting::filament/clusters/reporting/resources/report-template.actions.new-version.label'))
            ->icon('heroicon-o-document-duplicate')
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('accounting::filament/clusters/reporting/resources/report-template.actions.new-version.confirm'))
            ->authorize(fn (ReportTemplate $record): bool => static::companyAllowsChange($record))
            ->action(function (ReportTemplate $record) {
                $draft = app(ReportTemplateVersioningService::class)->newDraftVersion($record);

                Notification::make()
                    ->success()
                    ->title(__('accounting::filament/clusters/reporting/resources/report-template.actions.new-version.created', ['version' => $draft->version]))
                    ->send();

                return redirect(EditReportTemplate::getUrl(['record' => $draft]));
            });
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(__('accounting::filament/clusters/reporting/resources/report-template.actions.archive.label'))
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (ReportTemplate $record) => $record->statusEnum() === TemplateStatus::PUBLISHED)
            ->authorize(fn (ReportTemplate $record): bool => static::companyAllowsChange($record))
            ->action(function (ReportTemplate $record) {
                app(ReportTemplateVersioningService::class)->archive($record);

                Notification::make()
                    ->success()
                    ->title(__('accounting::filament/clusters/reporting/resources/report-template.actions.archive.archived'))
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            ColumnsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListReportTemplates::route('/'),
            'create' => CreateReportTemplate::route('/create'),
            'edit'   => EditReportTemplate::route('/{record}/edit'),
        ];
    }
}
