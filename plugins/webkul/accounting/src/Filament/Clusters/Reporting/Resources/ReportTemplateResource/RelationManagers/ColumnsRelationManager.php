<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\RelationManagers;

use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Webkul\Accounting\Enums\ColumnType;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource;
use Webkul\Security\Services\SelectableCompanies;
use Webkul\Support\Models\Company;

class ColumnsRelationManager extends RelationManager
{
    protected static string $relationship = 'columns';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('accounting::filament/clusters/reporting/resources/report-template.columns.title');
    }

    /**
     * Published and archived versions are immutable; their structure is
     * browsable but not editable.
     */
    public function isReadOnly(): bool
    {
        return ! $this->getOwnerRecord()->isDraft()
            || ! ReportTemplateResource::canEdit($this->getOwnerRecord());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        Select::make('column_type')
                            ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.column-type'))
                            ->options(ColumnType::options())
                            ->default(ColumnType::MONTH->value)
                            ->live()
                            ->required(),
                        TextInput::make('label')
                            ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.label'))
                            ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.label-help'))
                            ->maxLength(255),
                        Select::make('start_month')
                            ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.start-month'))
                            ->options(array_combine(range(1, 12), range(1, 12)))
                            ->visible(fn (Get $get) => in_array($get('column_type'), [ColumnType::MONTH->value, ColumnType::RANGE->value], true)),
                        Select::make('end_month')
                            ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.end-month'))
                            ->options(array_combine(range(1, 12), range(1, 12)))
                            ->visible(fn (Get $get) => $get('column_type') === ColumnType::RANGE->value),
                        TextInput::make('year_offset')
                            ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.year-offset'))
                            ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.year-offset-help'))
                            ->integer()
                            ->default(0),
                        Select::make('company_id')
                            ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.company'))
                            ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.company-help'))
                            ->options(fn () => SelectableCompanies::scope(Company::query())->pluck('name', 'id'))
                            ->rule(fn (): Closure => SelectableCompanies::rule())
                            ->searchable()
                            ->nullable(),
                        Toggle::make('is_consolidated')
                            ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.consolidated'))
                            ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.columns.form.fields.consolidated-help'))
                            ->inline(false),
                    ]),
            ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('sort')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('label')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.table.columns.label'))
                    ->placeholder(__('accounting::filament/clusters/reporting/resources/report-template.columns.table.columns.derived')),
                TextColumn::make('column_type')
                    ->badge()
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.table.columns.type')),
                TextColumn::make('start_month')
                    ->placeholder('-')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.table.columns.start')),
                TextColumn::make('end_month')
                    ->placeholder('-')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.table.columns.end')),
                TextColumn::make('year_offset')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.table.columns.year-offset')),
                TextColumn::make('company.name')
                    ->placeholder('-')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.table.columns.company')),
                IconColumn::make('is_consolidated')
                    ->boolean()
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.table.columns.consolidated')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.columns.table.actions.create')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
