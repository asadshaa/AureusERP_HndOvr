<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DocumentStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Enums\DriveSyncStatus;
use Webkul\Accounting\Filament\Actions\SendDocumentToPeerAction;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\DocumentResource\Pages\ListDocuments;
use Webkul\Accounting\Jobs\SyncDocumentToDriveJob;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-clip';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return 'Documents';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->forCompany(Auth::user()?->default_company_id)
            ->with(['currentVersion', 'creator', 'driveSync']);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ViewDocuments) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageDocuments) ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageDocuments) ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->can(AccountingPermissions::DeleteDocuments) ?? false;
    }

    /**
     * Shared by the "Upload" header action and the "Add version" record
     * action -- both ultimately just need a file plus an optional reason,
     * with the file going straight through DocumentService, never a plain
     * Eloquent save.
     */
    public static function uploadFormSchema(): array
    {
        return [
            Select::make('document_type')
                ->label('Document type')
                ->options(DocumentType::options())
                ->required()
                ->native(false),
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->columnSpanFull(),
            FileUpload::make('file')
                ->label('File')
                ->required()
                ->storeFiles(false)
                ->maxSize(DocumentService::MAX_FILE_SIZE_BYTES / 1024)
                ->acceptedFileTypes(DocumentService::ALLOWED_MIME_TYPES)
                ->helperText('PDF, image, Word, or Excel/CSV -- up to 20MB.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->description(fn (Document $record): ?string => $record->description),
                TextColumn::make('document_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (DocumentStatus $state): string => $state === DocumentStatus::Active ? 'success' : 'gray'),
                TextColumn::make('currentVersion.original_filename')
                    ->label('Current file'),
                TextColumn::make('currentVersion.version_number')
                    ->label('Version')
                    ->badge(),
                TextColumn::make('currentVersion.file_size')
                    ->label('Size')
                    ->formatStateUsing(fn (?int $state): string => $state ? number_format($state / 1024, 1).' KB' : '—'),
                TextColumn::make('creator.name')
                    ->label('Uploaded by')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Uploaded at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('driveSync.status')
                    ->label('Drive status')
                    ->badge()
                    // No driveSync row yet reads the same as NotSynced --
                    // that IS its meaning (see Document::driveSync()), so
                    // this is a display fallback, not masking an error.
                    ->formatStateUsing(fn (?DriveSyncStatus $state): string => ($state ?? DriveSyncStatus::NotSynced)->getLabel())
                    ->color(fn (?DriveSyncStatus $state): string|array|null => ($state ?? DriveSyncStatus::NotSynced)->getColor())
                    // Gated to ManageDocuments, not just the ViewDocuments
                    // this whole table already requires -- last_sync_error
                    // is raw exception text from the Google API/OAuth
                    // client, which can carry request/response detail that
                    // shouldn't be visible to a merely-viewing user, only
                    // to whoever can actually act on it (retry via Sync now).
                    ->tooltip(fn (Document $record): ?string => Auth::user()?->can(AccountingPermissions::ManageDocuments)
                        ? $record->driveSync?->last_sync_error
                        : null)
                    ->visible(fn (): bool => (bool) config('accounting_drive.enabled')),
                TextColumn::make('driveSync.last_synced_at')
                    ->label('Last synced')
                    ->dateTime()
                    ->placeholder('Never')
                    ->visible(fn (): bool => (bool) config('accounting_drive.enabled')),
            ])
            ->filters([
                SelectFilter::make('document_type')->options(DocumentType::options()),
                SelectFilter::make('status')->options(DocumentStatus::options()),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->authorize(AccountingPermissions::DownloadDocuments)
                    ->action(function (Document $record) {
                        try {
                            $result = app(DocumentService::class)->retrieveContents(Auth::user(), $record->id, request()->ip());

                            return response()->streamDownload(
                                fn () => print ($result['contents']),
                                $result['version']->original_filename,
                            );
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not download this document')->body($e->getMessage())->send();
                        }
                    }),

                SendDocumentToPeerAction::make(),

                Action::make('syncToDrive')
                    ->label(fn (Document $record): string => $record->driveSync?->status === DriveSyncStatus::Failed ? 'Retry Drive sync' : 'Sync now')
                    ->icon('heroicon-o-arrow-path')
                    ->color(fn (Document $record): string => $record->driveSync?->status === DriveSyncStatus::Failed ? 'danger' : 'gray')
                    ->authorize(AccountingPermissions::ManageDocuments)
                    ->visible(fn (): bool => (bool) config('accounting_drive.enabled'))
                    ->action(function (Document $record): void {
                        SyncDocumentToDriveJob::dispatch($record->id);

                        Notification::make()->success()->title('Sync to Google Drive queued')->send();
                    }),

                Action::make('openInDrive')
                    ->label('Open in Drive')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (Document $record): bool => (bool) config('accounting_drive.enabled') && $record->driveSync?->exists_in_drive)
                    ->url(fn (Document $record): ?string => $record->driveSync?->drive_file_id
                        ? app(DriveClient::class)->webViewLink($record->driveSync->drive_file_id)
                        : null)
                    ->openUrlInNewTab(),

                Action::make('addVersion')
                    ->label('Add version')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->authorize(AccountingPermissions::ManageDocuments)
                    ->schema([
                        FileUpload::make('file')
                            ->label('New file')
                            ->required()
                            ->storeFiles(false)
                            ->maxSize(DocumentService::MAX_FILE_SIZE_BYTES / 1024)
                            ->acceptedFileTypes(DocumentService::ALLOWED_MIME_TYPES),
                        Textarea::make('change_reason')
                            ->label('Reason for the new version')
                            ->required()
                            ->helperText('Why is this replacing the current file? This is mandatory and stays on the audit trail.'),
                    ])
                    ->action(function (Document $record, array $data): void {
                        try {
                            app(DocumentService::class)->addVersion(
                                Auth::user(),
                                $record,
                                $data['file'],
                                $data['change_reason'],
                                request()->ip(),
                            );

                            Notification::make()->success()->title('New version added')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not add a new version')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('history')
                    ->label('History')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Document $record): string => "History for \"{$record->title}\"")
                    ->modalContent(fn (Document $record) => view(
                        'accounting::filament.clusters.accounting.resources.document.history',
                        ['record' => $record->load(['versions.uploader', 'audits.actor'])],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(AccountingPermissions::DeleteDocuments)
                    ->visible(fn (Document $record): bool => $record->status === DocumentStatus::Active)
                    ->action(function (Document $record): void {
                        try {
                            app(DocumentService::class)->archive(Auth::user(), $record, request()->ip());
                            Notification::make()->success()->title('Document archived')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not archive this document')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->authorize(AccountingPermissions::ManageDocuments)
                    ->visible(fn (Document $record): bool => $record->status === DocumentStatus::Archived)
                    ->action(function (Document $record): void {
                        try {
                            app(DocumentService::class)->restore(Auth::user(), $record, request()->ip());
                            Notification::make()->success()->title('Document restored')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not restore this document')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
        ];
    }
}
