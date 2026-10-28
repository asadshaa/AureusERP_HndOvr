<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Webkul\Employee\Filament\Clusters\Configurations;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\DocumentTypeResource\Pages\ListDocumentTypes;
use Webkul\Employee\Models\DocumentType;
use Webkul\Security\Services\CurrentCompany;

/** Employee document types of the active company (CNIC, contract, ...). */
class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $cluster = Configurations::class;

    protected static ?string $modelLabel = 'Document type';

    protected static ?string $navigationLabel = 'Document types';

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
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('company_id', CurrentCompany::id() ?? 0),
                    ),
                Toggle::make('has_expiry')
                    ->label('Has an expiry date')
                    ->helperText('Shows the expiry date field and sends reminders 30 and 7 days before and on expiry.'),
                Toggle::make('visible_to_employee')
                    ->label('Visible to the employee')
                    ->default(true)
                    ->helperText('Off for HR-only documents such as medical certificates.'),
                Toggle::make('required_for_new_hires')
                    ->label('Required for every employee')
                    ->helperText('Counted in "missing required documents".'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('has_expiry')->label('Expiry')->boolean(),
                IconColumn::make('visible_to_employee')->label('Employee sees it')->boolean(),
                IconColumn::make('required_for_new_hires')->label('Required')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('documents_count')->label('Documents')->counts('documents'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable('sort')
            ->defaultSort('sort');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTypes::route('/'),
        ];
    }
}
