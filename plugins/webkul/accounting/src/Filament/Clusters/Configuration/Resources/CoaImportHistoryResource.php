<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CoaImportHistoryResource\Pages\ListCoaImportBatches;
use Webkul\Accounting\Models\CoaImportBatch;
use Webkul\Security\Filament\Concerns\ScopedToActiveCompany;

/**
 * Read-only history of Chart-of-Accounts imports: what was imported, when, by
 * whom, in which mode, with the row counts and a downloadable warning/error
 * report per batch. Only the active company's imports are listed.
 */
class CoaImportHistoryResource extends Resource
{
    use ScopedToActiveCompany;

    protected static ?string $model = CoaImportBatch::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $cluster = Configuration::class;

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    public static function getModelLabel(): string
    {
        return 'Import History';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Import History';
    }

    public static function getNavigationLabel(): string
    {
        return 'Import History';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->label('When')->sortable(),
                TextColumn::make('company.name')->label('Company')->sortable(),
                TextColumn::make('filename')->label('File')->limit(40)->placeholder('-'),
                TextColumn::make('mode')->badge()->label('Mode'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'imported' => 'success', 'failed' => 'danger', default => 'warning',
                    }),
                TextColumn::make('groups_created')->label('Groups')->alignRight(),
                TextColumn::make('accounts_created')->label('Accounts')->alignRight(),
                TextColumn::make('accounts_updated')->label('Updated')->alignRight(),
                TextColumn::make('journals_created')->label('Journals')->alignRight(),
                TextColumn::make('creator.name')->label('By')->placeholder('-')->toggleable(),
            ])
            ->recordActions([
                Action::make('errorReport')
                    ->label('Warnings')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (CoaImportBatch $record) => ! empty($record->options['warnings'] ?? []))
                    ->action(function (CoaImportBatch $record) {
                        $rows = collect($record->options['warnings'] ?? []);
                        $csv = "severity,code,sheet_row,account_code,message\n";
                        foreach ($rows as $w) {
                            $csv .= implode(',', [
                                $w['severity'] ?? '',
                                $w['code'] ?? '',
                                $w['sheet_row'] ?? '',
                                $w['account_code'] ?? '',
                                '"'.str_replace('"', '""', (string) ($w['message'] ?? '')).'"',
                            ])."\n";
                        }

                        return response()->streamDownload(fn () => print ($csv), "coa-import-{$record->id}-warnings.csv");
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoaImportBatches::route('/'),
        ];
    }
}
