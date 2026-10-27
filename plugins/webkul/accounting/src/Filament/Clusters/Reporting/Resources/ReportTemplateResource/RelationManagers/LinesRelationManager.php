<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\RelationManagers;

use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\FormulaOperandType;
use Webkul\Accounting\Enums\FormulaOperator;
use Webkul\Accounting\Enums\FormulaPurpose;
use Webkul\Accounting\Enums\LineType;
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Security\Services\SelectableCompanies;
use Webkul\Support\Models\Company;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('accounting::filament/clusters/reporting/resources/report-template.lines.title');
    }

    /**
     * Published and archived versions are immutable; their structure is
     * browsable but not editable. Shared (no company) templates can only be
     * changed by a group owner.
     */
    public function isReadOnly(): bool
    {
        return ! $this->getOwnerRecord()->isDraft()
            || ! ReportTemplateResource::canEdit($this->getOwnerRecord());
    }

    /**
     * The active company's chart of accounts, labelled "code name".
     *
     * @return array<int, string>
     */
    protected function accountOptions(): array
    {
        return Account::query()
            ->whereHas('companies', fn (Builder $query) => $query->where('companies.id', CurrentCompany::id()))
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $account) => [$account->id => trim(($account->code ? $account->code.' ' : '').$account->name)])
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        $lineOptions = fn () => ReportLine::query()
            ->where('report_template_id', $this->getOwnerRecord()->id)
            ->whereNotNull('caption')
            ->orderBy('sort')
            ->pluck('caption', 'id');

        return $schema
            ->components([
                Section::make(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.sections.line'))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Select::make('line_type')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.line-type'))
                                    ->options(LineType::options())
                                    ->default(LineType::DETAIL->value)
                                    ->live()
                                    ->required(),
                                TextInput::make('caption')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.caption'))
                                    ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.caption-help'))
                                    ->maxLength(255),
                                TextInput::make('code')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.code'))
                                    ->maxLength(255),
                                Select::make('parent_id')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.parent'))
                                    ->options($lineOptions)
                                    ->searchable()
                                    ->nullable(),
                                Select::make('value_source')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.value-source'))
                                    ->options(ValueSource::options())
                                    ->placeholder(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.value-source-placeholder'))
                                    ->live()
                                    ->nullable(),
                                Select::make('value_basis')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.value-basis'))
                                    ->options(ValueBasis::options())
                                    ->placeholder(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.value-basis-placeholder'))
                                    ->nullable(),
                                TextInput::make('external_provider')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.external-provider'))
                                    ->visible(fn (Get $get) => $get('value_source') === ValueSource::EXTERNAL->value)
                                    ->maxLength(255),
                                Select::make('company_id')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.company'))
                                    ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.company-help'))
                                    ->options(fn () => SelectableCompanies::scope(Company::query())->pluck('name', 'id'))
                                    ->rule(fn (): Closure => SelectableCompanies::rule())
                                    ->searchable()
                                    ->nullable(),
                                Select::make('sign')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.sign'))
                                    ->options([
                                        1  => '+',
                                        -1 => '-',
                                    ])
                                    ->default(1),
                                TextInput::make('indent_level')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.indent'))
                                    ->integer()
                                    ->default(0)
                                    ->minValue(0),
                                Toggle::make('is_bold')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.bold'))
                                    ->inline(false),
                                Toggle::make('is_check')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.check'))
                                    ->helperText(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.check-help'))
                                    ->inline(false),
                                Toggle::make('is_visible')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.visible'))
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ]),
                Section::make(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.sections.mapping'))
                    ->description(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.sections.mapping-help'))
                    ->collapsible()
                    ->schema([
                        Repeater::make('accountBindings')
                            ->hiddenLabel()
                            ->relationship('accountBindings')
                            ->defaultItems(0)
                            ->addActionLabel(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.add-account'))
                            ->schema([
                                Select::make('account_id')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.account'))
                                    ->options(fn (): array => $this->accountOptions())
                                    ->searchable()
                                    ->required(),
                                Select::make('sign')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.sign'))
                                    ->options([
                                        1  => '+',
                                        -1 => '-',
                                    ])
                                    ->default(1),
                            ])
                            ->columns(2),
                    ]),
                Section::make(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.sections.formula'))
                    ->description(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.sections.formula-help'))
                    ->collapsible()
                    ->schema([
                        Repeater::make('formulas')
                            ->hiddenLabel()
                            ->relationship('formulas')
                            ->orderColumn('sort')
                            ->defaultItems(0)
                            ->addActionLabel(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.add-operand'))
                            ->schema([
                                Select::make('purpose')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.purpose'))
                                    ->options(FormulaPurpose::options())
                                    ->default(FormulaPurpose::VALUE->value)
                                    ->required(),
                                Select::make('operator')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.operator'))
                                    ->options(FormulaOperator::options())
                                    ->default(FormulaOperator::ADD->value)
                                    ->required(),
                                Select::make('operand_type')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.operand-type'))
                                    ->options(FormulaOperandType::options())
                                    ->default(FormulaOperandType::LINE->value)
                                    ->live()
                                    ->required(),
                                Select::make('operand_line_id')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.operand-line'))
                                    ->options($lineOptions)
                                    ->searchable()
                                    ->visible(fn (Get $get) => $get('operand_type') !== FormulaOperandType::CONSTANT->value),
                                TextInput::make('operand_constant')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.operand-constant'))
                                    ->numeric()
                                    ->visible(fn (Get $get) => $get('operand_type') === FormulaOperandType::CONSTANT->value),
                            ])
                            ->columns(4),
                    ]),
                Section::make(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.sections.inputs'))
                    ->description(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.sections.inputs-help'))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('inputs')
                            ->hiddenLabel()
                            ->relationship('inputs')
                            ->defaultItems(0)
                            ->addActionLabel(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.add-input'))
                            ->schema([
                                DatePicker::make('date')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.date'))
                                    ->required(),
                                TextInput::make('value')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.value'))
                                    ->numeric()
                                    ->required(),
                                Select::make('company_id')
                                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.form.fields.company'))
                                    ->options(fn () => SelectableCompanies::scope(Company::query())->pluck('name', 'id'))
                                    ->rule(fn (): Closure => SelectableCompanies::rule())
                                    ->searchable()
                                    ->nullable(),
                            ])
                            ->columns(3),
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
                TextColumn::make('caption')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.table.columns.caption'))
                    ->placeholder(__('accounting::filament/clusters/reporting/resources/report-template.lines.table.columns.blank'))
                    ->weight(fn (ReportLine $record) => $record->is_bold ? 'bold' : null)
                    ->searchable(),
                TextColumn::make('line_type')
                    ->badge()
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.table.columns.type')),
                TextColumn::make('value_source')
                    ->badge()
                    ->placeholder('-')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.table.columns.source')),
                TextColumn::make('account_bindings_count')
                    ->counts('accountBindings')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.table.columns.accounts')),
                TextColumn::make('formulas_count')
                    ->counts('formulas')
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.table.columns.operands')),
                IconColumn::make('is_check')
                    ->boolean()
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.table.columns.check')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('accounting::filament/clusters/reporting/resources/report-template.lines.table.actions.create')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
