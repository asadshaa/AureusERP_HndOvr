<?php

namespace Webkul\Accounting\Filament\RelationManagers;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webkul\Accounting\Enums\DocumentStatus;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Filament\Actions\SendDocumentToPeerAction;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\DocumentResource;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;

/**
 * Attaches the existing, already-tested Document/DocumentService pipeline
 * to any owner record -- an invoice, a bank statement, a journal entry --
 * without either the owner's model or its base Filament resource ever
 * needing to know Document exists.
 *
 * Attaching this only requires two things from the host plugin:
 *   1. Register the relation once, e.g. in a service provider:
 *      Invoice::resolveRelationUsing('documentAttachments',
 *          fn ($model) => $model->morphMany(DocumentAttachment::class, 'attachable'));
 *   2. Add this class to the resource's getRelations().
 * Nothing about the owner model or resource file itself changes.
 */
class DocumentAttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documentAttachments';

    protected static ?string $title = 'Supporting documents';

    /**
     * Filament v4 relation managers default to lazy-loading (CanBeLazy's
     * $isLazy = true): the table only renders once its placeholder
     * scrolls into view, via an Alpine x-intersect trigger. That's a
     * reasonable default for a heavy/rarely-opened tab, but the whole
     * point of this feature is that supporting evidence should be
     * immediately obvious on a record, not something that only appears
     * once someone happens to scroll to exactly the right spot -- so it's
     * disabled here.
     */
    protected static bool $isLazy = false;

    /**
     * Filament's default RelationManager::canViewForRecord() decides
     * whether to show this manager at all by running Laravel's normal
     * `authorize('viewAny', DocumentAttachment::class)` -- which requires a
     * registered Eloquent Policy class to even be found, or it's denied
     * (and the whole manager silently disappears, with no error). There is
     * no DocumentAttachmentPolicy, deliberately: every real access
     * decision here already goes through AccountingPermissions +
     * DocumentService (company isolation, permission checks, audit --
     * see each Action's own ->authorize() below and DocumentService
     * itself). This override replaces Filament's policy-based gate with
     * that same permission check, instead of adding a Policy class whose
     * only job would be to defer back to it.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can(AccountingPermissions::ViewDocuments) ?? false;
    }

    public static function getEloquentQuery(Builder $query): Builder
    {
        return $query->with(['document.currentVersion', 'document.creator']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(DocumentResource::uploadFormSchema());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('document.title')
            ->columns([
                TextColumn::make('document.title')
                    ->label('Title')
                    ->searchable(),
                TextColumn::make('document.document_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('document.status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (DocumentStatus $state): string => $state === DocumentStatus::Active ? 'success' : 'gray'),
                TextColumn::make('document.currentVersion.original_filename')
                    ->label('File'),
                TextColumn::make('note')
                    ->label('Note')
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label('Attached by')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Attached at')
                    ->dateTime(),
            ])
            ->headerActions([
                Action::make('uploadAndAttach')
                    ->label('Upload document')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->authorize(AccountingPermissions::ManageDocuments)
                    ->schema(DocumentResource::uploadFormSchema())
                    ->action(function (array $data): void {
                        $owner = $this->getOwnerRecord();

                        try {
                            $document = app(DocumentService::class)->upload(
                                Auth::user(),
                                $owner->company_id,
                                DocumentType::from($data['document_type']),
                                $data['title'],
                                $data['description'] ?? null,
                                $data['file'],
                                request()->ip(),
                            );

                            app(DocumentService::class)->attach(Auth::user(), $document, $owner, ipAddress: request()->ip());

                            Notification::make()->success()->title('Document uploaded and attached')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not attach this document')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->authorize(AccountingPermissions::DownloadDocuments)
                    ->action(function (DocumentAttachment $record) {
                        try {
                            $result = app(DocumentService::class)->retrieveContents(Auth::user(), $record->document_id, request()->ip());

                            return response()->streamDownload(
                                fn () => print ($result['contents']),
                                $result['version']->original_filename,
                            );
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not download this document')->body($e->getMessage())->send();
                        }
                    }),

                // Rows here are DocumentAttachments, so the resolver hands
                // the action the Document they point at. Wiring it on the
                // relation manager (rather than each resource) puts "Send
                // to..." on every owner type at once -- invoices, bills,
                // journal entries, bank statements and payments.
                SendDocumentToPeerAction::make(fn (DocumentAttachment $record) => $record->document),

                Action::make('history')
                    ->label('History')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (DocumentAttachment $record): string => "History for \"{$record->document->title}\"")
                    ->modalContent(fn (DocumentAttachment $record) => view(
                        'accounting::filament.clusters.accounting.resources.document.history',
                        ['record' => $record->document->load(['versions.uploader', 'audits.actor'])],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('detach')
                    ->label('Detach')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This only removes the link between this document and this record -- the document itself, and its history, are kept.')
                    ->authorize(AccountingPermissions::ManageDocuments)
                    ->action(function (DocumentAttachment $record): void {
                        try {
                            app(DocumentService::class)->detach(Auth::user(), $record, request()->ip());
                            Notification::make()->success()->title('Document detached')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not detach this document')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
