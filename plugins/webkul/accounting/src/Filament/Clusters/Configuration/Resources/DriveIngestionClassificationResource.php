<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource\Pages\ListDriveIngestionClassifications;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource\Pages\ViewDriveIngestionClassification;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Accounting\Services\Drive\DriveInvoicePostingService;
use Webkul\Accounting\Support\AccountingPermissions;

/**
 * Phase 2 review queue: the list/table here is read-only (view only) --
 * the actual editing surface is ViewDriveIngestionClassification's
 * "Resolve & Submit" header action, which lets an accountant correct
 * whatever Phase 2's heuristic extraction got wrong (vendor name
 * misread, amount missed, wrong FS Tag) and re-submits for approval.
 * DriveInvoicePostingService (Phase 3) posts exactly whatever ends up
 * stored on the row once approved, so a bad extraction left uncorrected
 * would either get stuck failing validation or, worse, post wrong data.
 */
class DriveIngestionClassificationResource extends Resource
{
    protected static ?string $model = DriveIngestionClassification::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string
    {
        return 'Drive Ingestion Review';
    }

    /**
     * At-a-glance count of rows genuinely needing a human's attention --
     * without this, finding a handful of new documents among a hundred
     * already-processed ones meant scrolling the whole list (it has no
     * default sort, so new rows land wherever insertion order puts them).
     * PendingReview is included alongside NeedsReview/DuplicateSuspected/
     * PostingFailed since classify() can, in principle, leave a row there
     * if it throws before finishing -- Valid and Posted are correctly
     * excluded, since those don't need anyone to do anything.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereIn('validation_status', static::needsAttentionStatuses())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Shared by the navigation badge above and the per-row "New" tag in
     * table() below, so both always agree on exactly which rows count as
     * still needing a human -- rather than two independently-maintained
     * lists silently drifting apart.
     *
     * @return array<int, DriveClassificationStatus>
     */
    private static function needsAttentionStatuses(): array
    {
        return [
            DriveClassificationStatus::PendingReview,
            DriveClassificationStatus::NeedsReview,
            DriveClassificationStatus::DuplicateSuspected,
            DriveClassificationStatus::PostingFailed,
        ];
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Document Classification & Resolution')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('document_type')
                                    ->options(collect(DriveDocumentType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()]))
                                    ->required(),
                                Select::make('resolved_partner_id')
                                    ->relationship(
                                        name: 'resolvedPartner',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn ($query) => $query->where('company_id', Auth::user()?->default_company_id)
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->label('Resolved Partner'),
                                Select::make('resolved_fs_tag_id')
                                    ->relationship(
                                        name: 'resolvedFsTag',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn ($query) => $query->where('company_id', Auth::user()?->default_company_id)->where('is_active', true)
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->label('Resolved FS Tag')
                                    ->helperText('Selecting an FS Tag automatically maps the transaction to the Chart of Accounts GL account.'),
                                TextInput::make('extracted_invoice_number')
                                    ->label('Invoice / Refund #'),
                                TextInput::make('extracted_amount')
                                    ->numeric()
                                    ->label('Amount'),
                                TextInput::make('extracted_currency_code')
                                    ->label('Currency Code'),
                                DatePicker::make('extracted_date')
                                    ->label('Date'),
                            ]),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Classification Status')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('document_type')
                                    ->label('Document Type')
                                    ->badge(),
                                TextEntry::make('validation_status')
                                    ->label('Validation Status')
                                    ->badge(),
                                TextEntry::make('approvalRequest.status')
                                    ->label('Approval Status')
                                    ->placeholder('Not Submitted')
                                    ->badge(),
                                TextEntry::make('created_at')
                                    ->label('Discovered At')
                                    ->dateTime(),
                            ]),
                    ]),

                Grid::make(2)
                    ->schema([
                        Section::make('Extracted Document Details')
                            ->icon('heroicon-o-document-magnifying-glass')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('extracted_invoice_number')
                                            ->label('Invoice / Refund #')
                                            ->placeholder('—'),
                                        TextEntry::make('extracted_partner_name')
                                            ->label('Extracted Partner')
                                            ->placeholder('—'),
                                        TextEntry::make('extracted_amount')
                                            ->label('Extracted Amount')
                                            ->numeric(4)
                                            ->placeholder('—'),
                                        TextEntry::make('extracted_currency_code')
                                            ->label('Extracted Currency')
                                            ->placeholder('—'),
                                        TextEntry::make('extracted_date')
                                            ->label('Extracted Date')
                                            ->date()
                                            ->placeholder('—'),
                                        TextEntry::make('extracted_fs_tag_code')
                                            ->label('Extracted FS Tag Code')
                                            ->placeholder('—'),
                                    ]),
                            ]),

                        Section::make('Resolved Accounting Entities')
                            ->icon('heroicon-o-check-badge')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('resolvedPartner.name')
                                            ->label('Resolved Partner')
                                            ->placeholder('Unresolved'),
                                        TextEntry::make('resolvedFsTag.name')
                                            ->label('Resolved FS Tag')
                                            ->formatStateUsing(fn ($record) => $record->resolvedFsTag ? "{$record->resolvedFsTag->code} - {$record->resolvedFsTag->name}" : 'Unresolved')
                                            ->placeholder('Unresolved'),
                                        TextEntry::make('resolvedAccount.name')
                                            ->label('Mapped GL Account')
                                            ->formatStateUsing(fn ($record) => $record->resolvedAccount ? "{$record->resolvedAccount->code} - {$record->resolvedAccount->name}" : 'Unresolved')
                                            ->placeholder('Unresolved'),
                                        TextEntry::make('createdInvoice.name')
                                            ->label('Created Invoice / Move')
                                            ->placeholder('None Created')
                                            ->url(fn ($record) => $record->getInvoiceUrl())
                                            ->openUrlInNewTab()
                                            ->color('primary')
                                            ->icon('heroicon-o-arrow-top-right-on-square'),
                                        TextEntry::make('posted_at')
                                            ->label('Posted At')
                                            ->dateTime()
                                            ->placeholder('Not Posted'),
                                        TextEntry::make('posting_failure_reason')
                                            ->label('Posting Failure Reason')
                                            ->placeholder('None')
                                            ->visible(fn ($record) => filled($record->posting_failure_reason)),
                                    ]),
                            ]),
                    ]),

                Section::make('Validation Issues & Review Diagnostics')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn ($record) => ! empty($record->validation_issues))
                    ->schema([
                        TextEntry::make('validation_issues')
                            ->hiddenLabel()
                            ->formatStateUsing(fn ($state) => static::formatValidationIssues($state))
                            ->color('danger'),
                    ]),

                Section::make('Google Drive File & Provenance')
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->collapsible()
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('driveIngestion.filename')
                                    ->label('Filename'),
                                TextEntry::make('driveIngestion.drive_file_id')
                                    ->label('Google Drive File ID')
                                    ->copyable(),
                                TextEntry::make('driveIngestion.file_size')
                                    ->label('File Size (bytes)')
                                    ->numeric(),
                                TextEntry::make('driveIngestion.document.currentVersion.checksum_sha256')
                                    ->label('SHA-256 Checksum')
                                    ->copyable()
                                    ->placeholder('—'),
                                TextEntry::make('driveIngestion.drive_modified_at')
                                    ->label('Drive Modified At')
                                    ->dateTime(),
                                TextEntry::make('driveIngestion.processed_at')
                                    ->label('Processed At')
                                    ->dateTime(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('is_new_tag')
                    ->label('')
                    // ->visible() on a table column hides/shows the whole
                    // column, not per-row -- the per-row toggle has to live
                    // in the state itself instead: null for an
                    // already-handled row renders as nothing (no empty
                    // badge chip), "New" for one still needing attention
                    // renders as the badge.
                    ->state(fn (DriveIngestionClassification $record): ?string => in_array($record->validation_status, static::needsAttentionStatuses(), true) ? 'New' : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder(''),
                TextColumn::make('driveIngestion.filename')->label('Filename')->searchable()->limit(40),
                TextColumn::make('document_type')->label('Type')->badge(),
                TextColumn::make('extracted_invoice_number')->label('Invoice #')->placeholder('-'),
                TextColumn::make('extracted_partner_name')->label('Partner (extracted)')->placeholder('-'),
                TextColumn::make('extracted_amount')->label('Amount')->numeric(4)->placeholder('-'),
                TextColumn::make('extracted_currency_code')->label('Currency')->placeholder('-'),
                TextColumn::make('extracted_date')->label('Date')->date()->placeholder('-'),
                TextColumn::make('resolvedPartner.name')->label('Resolved partner')->placeholder('Unresolved'),
                TextColumn::make('resolvedFsTag.code')->label('Resolved FS Tag')->placeholder('Unresolved'),
                TextColumn::make('resolvedAccount.code')->label('Resolved GL')->placeholder('Unresolved'),
                TextColumn::make('validation_status')->label('Status')->badge()->sortable(),
                TextColumn::make('approval_request_id')->label('Approval Req.')->placeholder('None'),
                TextColumn::make('validation_issues')
                    ->label('Issues')
                    ->formatStateUsing(fn (mixed $state): string => static::formatValidationIssues($state))
                    ->wrap()
                    ->limit(80)
                    ->placeholder('-'),
                TextColumn::make('createdInvoice.name')
                    ->label('Posted Move')
                    ->placeholder('-')
                    ->url(fn (DriveIngestionClassification $record) => $record->getInvoiceUrl())
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->icon('heroicon-o-arrow-top-right-on-square'),
                TextColumn::make('created_at')->label('Discovered At')->dateTime()->sortable(),
            ])
            ->recordUrl(fn (DriveIngestionClassification $record) => static::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('validation_status')->options(collect(DriveClassificationStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])->all()),
                SelectFilter::make('document_type')->options(collect(DriveDocumentType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('openInvoice')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('success')
                    ->visible(fn (DriveIngestionClassification $record) => (bool) $record->created_invoice_id)
                    ->url(fn (DriveIngestionClassification $record) => $record->getInvoiceUrl())
                    ->openUrlInNewTab(),
                Action::make('quickPost')
                    ->label('Post to GL')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    // Same Post Journal gate the service enforces server-side.
                    ->authorize(AccountingPermissions::PostJournal)
                    ->visible(fn (DriveIngestionClassification $record) => $record->created_invoice_id === null
                        && $record->validation_status !== DriveClassificationStatus::Posted
                        && $record->resolved_partner_id
                        && $record->resolved_account_id
                        && (float) $record->extracted_amount > 0)
                    ->requiresConfirmation()
                    ->modalHeading('Confirm & Post to General Ledger')
                    ->modalDescription('Are you sure you want to confirm and post this document to the General Ledger?')
                    ->action(function (DriveIngestionClassification $record) {
                        try {
                            $move = app(DriveInvoicePostingService::class)->postClassification($record, Auth::user());
                            Notification::make()->success()->title("Posted {$move->name}")->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Posting Failed')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('quickReject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->authorize(AccountingPermissions::ManageDocuments)
                    ->visible(fn (DriveIngestionClassification $record) => $record->created_invoice_id === null
                        && $record->validation_status !== DriveClassificationStatus::Posted
                        && $record->validation_status !== DriveClassificationStatus::Rejected)
                    ->requiresConfirmation()
                    ->modalHeading('Reject Ingested Document')
                    ->modalDescription('Are you sure you want to reject this document? It will not be posted to the ledger.')
                    ->action(function (DriveIngestionClassification $record) {
                        $record->update(['validation_status' => DriveClassificationStatus::Rejected]);
                        Notification::make()->warning()->title('Document Rejected')->send();
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->authorize(AccountingPermissions::ManageDocuments)
                    ->visible(fn (DriveIngestionClassification $record) => $record->created_invoice_id === null
                        && $record->validation_status !== DriveClassificationStatus::Posted)
                    ->requiresConfirmation()
                    ->modalHeading('Delete Ingested Document')
                    ->modalDescription('Are you sure you want to delete this unposted document? It will be permanently removed from Aureus ERP and moved to Google Drive trash.')
                    ->action(function (DriveIngestionClassification $record) {
                        try {
                            $result = app(DriveIngestionService::class)->deleteIngestionClassification($record, deleteFromDrive: true);
                            $driveMsg = $result['drive_deleted'] ? ' and moved to Google Drive trash' : '';
                            Notification::make()->success()->title('Document Deleted')->body("Document successfully deleted{$driveMsg}.")->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Delete Failed')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkAction::make('bulkReject')
                    ->label('Reject Selected')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->authorize(AccountingPermissions::ManageDocuments)
                    ->requiresConfirmation()
                    ->modalHeading('Reject Selected Documents')
                    ->modalDescription('Are you sure you want to reject the selected documents?')
                    ->action(function (Collection $records) {
                        $count = 0;
                        foreach ($records as $record) {
                            if ($record->created_invoice_id === null && $record->validation_status !== DriveClassificationStatus::Posted) {
                                $record->update(['validation_status' => DriveClassificationStatus::Rejected]);
                                $count++;
                            }
                        }
                        Notification::make()->warning()->title("Rejected {$count} document(s)")->send();
                    }),
                BulkAction::make('bulkDelete')
                    ->label('Delete Selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->authorize(AccountingPermissions::ManageDocuments)
                    ->requiresConfirmation()
                    ->modalHeading('Delete Selected Ingested Documents')
                    ->modalDescription('Are you sure you want to delete the selected unposted documents? They will be permanently removed from Aureus ERP and moved to Google Drive trash. Any already-posted documents will be preserved.')
                    ->action(function (Collection $records) {
                        $deletedCount = 0;
                        $skippedCount = 0;
                        $service = app(DriveIngestionService::class);

                        foreach ($records as $record) {
                            if ($record->created_invoice_id !== null || $record->validation_status === DriveClassificationStatus::Posted) {
                                $skippedCount++;

                                continue;
                            }

                            try {
                                $service->deleteIngestionClassification($record, deleteFromDrive: true);
                                $deletedCount++;
                            } catch (\Throwable $e) {
                                $skippedCount++;
                            }
                        }

                        if ($deletedCount > 0) {
                            $msg = "Removed {$deletedCount} document(s) from Aureus and moved to Google Drive trash.";
                            if ($skippedCount > 0) {
                                $msg .= " ({$skippedCount} already-posted document(s) were protected and skipped).";
                            }
                            Notification::make()->success()->title("Deleted {$deletedCount} Document(s)")->body($msg)->send();
                        } elseif ($skippedCount > 0) {
                            Notification::make()->warning()
                                ->title('No Documents Deleted')
                                ->body("All {$skippedCount} selected document(s) are already posted to the General Ledger and cannot be deleted.")
                                ->send();
                        }
                    }),
            ]);
    }

    public static function formatValidationIssues(mixed $state): string
    {
        if (blank($state)) {
            return '';
        }

        if (is_string($state)) {
            $decoded = json_decode($state, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $state = $decoded;
            } else {
                return $state;
            }
        }

        if (! is_array($state)) {
            return (string) $state;
        }

        if (! array_is_list($state) && (isset($state['message']) || isset($state['error_code']))) {
            $prefix = isset($state['error_code']) ? "[{$state['error_code']}] " : '';

            return $prefix.($state['message'] ?? '');
        }

        $items = [];
        foreach ($state as $item) {
            if (is_string($item)) {
                $items[] = $item;
            } elseif (is_array($item)) {
                if (isset($item['message'])) {
                    $prefix = isset($item['error_code']) ? "[{$item['error_code']}] " : '';
                    $items[] = $prefix.$item['message'];
                } elseif (isset($item['error_code'])) {
                    $items[] = $item['error_code'];
                } else {
                    $items[] = json_encode($item);
                }
            } else {
                $items[] = (string) $item;
            }
        }

        return implode(' | ', $items);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDriveIngestionClassifications::route('/'),
            'view'  => ViewDriveIngestionClassification::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ViewDocuments) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
