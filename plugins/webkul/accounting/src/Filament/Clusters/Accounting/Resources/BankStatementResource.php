<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\BankStatement;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource\Pages\ListBankStatements;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource\Pages\ViewBankStatement;
use Webkul\Accounting\Filament\RelationManagers\DocumentAttachmentsRelationManager;
use Webkul\Accounting\Support\AccountingPermissions;

class BankStatementResource extends Resource
{
    protected static ?string $model = BankStatement::class;

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Bank Statements';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Auth::user()?->default_company_id)
            ->withCount('lines');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('bank_name')->label('Bank')->searchable()->sortable(),
            TextColumn::make('bank_account_number')->label('Account / IBAN')->searchable(),
            TextColumn::make('statement_start_date')->label('From')->date()->sortable(),
            TextColumn::make('statement_end_date')->label('To')->date()->sortable(),
            TextColumn::make('opening_balance')->money(fn (BankStatement $record) => $record->currency?->name ?? 'PKR')->alignRight(),
            TextColumn::make('total_debits')->alignRight(),
            TextColumn::make('total_credits')->alignRight(),
            TextColumn::make('closing_balance')->alignRight(),
            TextColumn::make('currency.code')->label('Original currency'),
            TextColumn::make('company_closing_balance')->label('Company closing')->numeric(4)->alignRight()->placeholder('Missing rate'),
            TextColumn::make('companyCurrency.code')->label('Company currency'),
            TextColumn::make('conversion_status')->badge(),
            TextColumn::make('lines_count')->label('Transactions')->alignRight(),
            TextColumn::make('import_status')->badge(),
            IconColumn::make('is_completed')->label('Posted/closed')->boolean(),
            TextColumn::make('original_filename')->label('File')->toggleable(),
            TextColumn::make('file_hash')->label('SHA-256')->limit(12)->tooltip(fn (BankStatement $record) => $record->file_hash)->toggleable(isToggledHiddenByDefault: true),
        ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('statement_end_date', 'desc');
    }

    /**
     * Shared with ViewBankStatement -- the statement's own recorded
     * filename/hash prove a file WAS imported and check it hasn't changed
     * since; they don't let anyone actually open that original file again.
     * The Supporting documents relation manager below is where the real,
     * downloadable copy (the bank's own PDF/CSV export) lives.
     */
    public static function infolistSchema(): array
    {
        return [
            TextEntry::make('bank_name')->label('Bank'),
            TextEntry::make('bank_account_number')->label('Account / IBAN'),
            TextEntry::make('statement_start_date')->label('From')->date(),
            TextEntry::make('statement_end_date')->label('To')->date(),
            TextEntry::make('opening_balance')->money(fn (BankStatement $record) => $record->currency?->name ?? 'PKR'),
            TextEntry::make('closing_balance')->money(fn (BankStatement $record) => $record->currency?->name ?? 'PKR'),
            TextEntry::make('original_filename')->label('Imported file'),
            TextEntry::make('file_hash')->label('Imported file SHA-256'),
            IconEntry::make('is_completed')->label('Posted/closed')->boolean(),
        ];
    }

    public static function getRelations(): array
    {
        return [
            DocumentAttachmentsRelationManager::class,
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::BankStatements) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankStatements::route('/'),
            'view'  => ViewBankStatement::route('/{record}'),
        ];
    }
}
