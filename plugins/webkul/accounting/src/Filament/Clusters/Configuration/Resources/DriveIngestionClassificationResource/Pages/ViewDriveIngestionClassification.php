<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Drive\DriveClassificationService;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Accounting\Services\Drive\DriveInvoicePostingService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class ViewDriveIngestionClassification extends ViewRecord
{
    protected static string $resource = DriveIngestionClassificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openPostedInvoice')
                ->label(fn () => $this->record->document_type === DriveDocumentType::VendorBill ? 'Open Posted Bill' : 'Open Posted Invoice')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('success')
                ->visible(fn () => (bool) $this->record->created_invoice_id)
                ->url(fn () => $this->record->getInvoiceUrl())
                ->openUrlInNewTab(),

            Action::make('postToLedger')
                ->label('Confirm & Post to Ledger')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                // Posting is a Post Journal act, like every other ledger post;
                // DriveInvoicePostingService::postClassification() enforces
                // the same permission server-side.
                ->authorize(AccountingPermissions::PostJournal)
                ->visible(fn () => $this->record->created_invoice_id === null
                    && $this->record->validation_status !== DriveClassificationStatus::Posted
                    && $this->record->resolved_partner_id
                    && $this->record->resolved_account_id
                    && (float) $this->record->extracted_amount > 0)
                ->requiresConfirmation()
                ->modalHeading('Confirm & Post Invoice to Ledger')
                ->modalDescription('This will create the formal accounting Move, attach the Google Drive document, and post balanced journal entries to the General Ledger.')
                ->action(function (): void {
                    try {
                        $move = app(DriveInvoicePostingService::class)->postClassification($this->record, Auth::user());

                        Notification::make()
                            ->success()
                            ->title('Posted to General Ledger')
                            ->body("Successfully created and posted {$move->name}.")
                            ->send();

                        $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record->id]));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Posting Failed')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->authorize(AccountingPermissions::ManageDocuments)
                ->visible(fn () => $this->record->created_invoice_id === null
                    && $this->record->validation_status !== DriveClassificationStatus::Posted
                    && $this->record->validation_status !== DriveClassificationStatus::Rejected)
                ->requiresConfirmation()
                ->modalHeading('Reject Ingested Document')
                ->modalDescription('Are you sure you want to reject this document? It will not be posted to the ledger.')
                ->action(function (): void {
                    $this->record->update([
                        'validation_status' => DriveClassificationStatus::Rejected,
                    ]);

                    Notification::make()
                        ->warning()
                        ->title('Document Rejected')
                        ->body('This document has been rejected and will not be posted.')
                        ->send();

                    $this->refreshFormData(['validation_status']);
                }),

            Action::make('resolve')
                ->label('Edit & Submit')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->authorize(AccountingPermissions::ManageDocuments)
                // Previously only shown for NeedsReview -- an accountant
                // needs to be able to correct the extracted/resolved
                // fields (wrong vendor match, a flagged possible
                // duplicate that's actually legitimate, a posting that
                // failed after approval, etc.) in every pre-posted state,
                // not only the one specific status Phase 2 happened to
                // leave it in. Once an invoice has actually been created
                // (created_invoice_id set / Posted), this correctly stays
                // hidden -- correcting a posted transaction is a
                // reversal/correction workflow, not an edit of the
                // source document.
                ->visible(fn () => $this->record->created_invoice_id === null
                    && $this->record->validation_status !== DriveClassificationStatus::Posted)
                ->form([
                    Select::make('document_type')
                        ->label('Document Type')
                        ->options(collect(DriveDocumentType::cases())->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()]))
                        ->default(fn () => ($this->record->document_type && $this->record->document_type !== DriveDocumentType::Unknown)
                            ? $this->record->document_type->value
                            : DriveDocumentType::CustomerInvoice->value)
                        ->required(),
                    Select::make('resolved_partner_id')
                        ->label('Partner')
                        ->options(fn () => Partner::query()->where('company_id', $this->record->company_id)->pluck('name', 'id'))
                        ->default(fn () => $this->record->resolved_partner_id)
                        ->searchable()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Partner Name')
                                ->default(fn () => $this->record->extracted_partner_name)
                                ->required(),
                        ])
                        ->createOptionUsing(function (array $data) {
                            return Partner::create([
                                'name'          => $data['name'],
                                'company_id'    => $this->record->company_id,
                                'account_type'  => 'company',
                                'sub_type'      => 'partner',
                                'is_active'     => true,
                                'customer_rank' => $this->record->document_type === DriveDocumentType::CustomerInvoice ? 1 : 0,
                                'supplier_rank' => $this->record->document_type === DriveDocumentType::VendorBill ? 1 : 0,
                            ])->id;
                        })
                        ->required(),
                    Select::make('resolved_fs_tag_id')
                        ->label('FS Tag (Financial Statement Tag)')
                        ->options(fn () => FsTag::query()
                            ->where('company_id', $this->record->company_id)
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn ($tag) => [$tag->id => "{$tag->code} - {$tag->name}"])
                        )
                        ->default(function () {
                            if ($this->record->resolved_fs_tag_id) {
                                return $this->record->resolved_fs_tag_id;
                            }

                            $isCust = $this->record->document_type === DriveDocumentType::CustomerInvoice
                                || $this->record->document_type === DriveDocumentType::Unknown
                                || $this->record->document_type === null;

                            if ($isCust) {
                                $tag = FsTag::query()
                                    ->where('company_id', $this->record->company_id)
                                    ->where('is_active', true)
                                    ->whereNotNull('account_id')
                                    ->where(function ($q) {
                                        $q->where('code', 'like', '%REV%')
                                            ->orWhere('code', 'like', '%INC%')
                                            ->orWhereHas('account', fn ($acc) => $acc->whereIn('account_type', ['income', 'income_other']));
                                    })
                                    ->first();

                                if ($tag) {
                                    return $tag->id;
                                }
                            }

                            return FsTag::query()
                                ->where('company_id', $this->record->company_id)
                                ->where('is_active', true)
                                ->whereNotNull('account_id')
                                ->value('id');
                        })
                        ->searchable()
                        ->helperText('Selecting an FS Tag automatically resolves the GL account for accounting posting.')
                        ->required(),
                    TextInput::make('extracted_invoice_number')
                        ->label('Invoice / Refund #')
                        ->default(fn () => $this->record->extracted_invoice_number)
                        ->required(),
                    TextInput::make('extracted_amount')
                        ->label('Amount')
                        ->numeric()
                        ->default(fn () => $this->record->extracted_amount)
                        ->required(),
                    TextInput::make('extracted_currency_code')
                        ->label('Currency Code')
                        ->default(fn () => $this->record->extracted_currency_code ?: 'USD')
                        ->required(),
                    DatePicker::make('extracted_date')
                        ->label('Document Date')
                        ->default(fn () => $this->record->extracted_date ?: now()),
                    Toggle::make('post_immediately')
                        ->label('Post directly to General Ledger upon submission')
                        ->helperText('When enabled, creates the formal accounting Move and balanced journal entries in the ledger immediately.')
                        ->visible(fn (): bool => (bool) Auth::user()?->can(AccountingPermissions::PostJournal))
                        ->default(fn (): bool => (bool) Auth::user()?->can(AccountingPermissions::PostJournal)),
                ])
                ->action(function (array $data): void {
                    $record = $this->record;

                    $fsTag = FsTag::query()
                        ->where('company_id', $record->company_id)
                        ->where('is_active', true)
                        ->find($data['resolved_fs_tag_id']);

                    if (! $fsTag || ! $fsTag->account_id) {
                        Notification::make()
                            ->danger()
                            ->title('Invalid FS Tag')
                            ->body("The selected FS Tag \"{$fsTag?->code}\" has no GL account mapped.")
                            ->send();

                        return;
                    }

                    $account = Account::query()
                        ->postable()
                        ->where('deprecated', false)
                        ->whereHas('companies', fn ($q) => $q->where('companies.id', $record->company_id))
                        ->find($fsTag->account_id);

                    if (! $account) {
                        Notification::make()
                            ->danger()
                            ->title('Invalid GL Account')
                            ->body("The GL account mapped to FS Tag \"{$fsTag->code}\" is inactive, non-postable, or not owned by this company.")
                            ->send();

                        return;
                    }

                    $partner = Partner::query()
                        ->where('company_id', $record->company_id)
                        ->find($data['resolved_partner_id']);

                    $record->document_type = DriveDocumentType::from($data['document_type']);
                    $record->resolved_partner_id = $partner?->id;
                    $record->extracted_partner_name = $partner?->name ?? $record->extracted_partner_name;
                    $record->resolved_fs_tag_id = $fsTag->id;
                    $record->extracted_fs_tag_code = $fsTag->code;
                    $record->resolved_account_id = $account->id;
                    $record->extracted_invoice_number = $data['extracted_invoice_number'];
                    $record->extracted_amount = $data['extracted_amount'];
                    $record->extracted_currency_code = $data['extracted_currency_code'];
                    $record->extracted_date = $data['extracted_date'];
                    $record->validation_status = DriveClassificationStatus::Valid;
                    $record->validation_issues = null;
                    $record->save();

                    // If user opted to post directly to General Ledger immediately
                    if (! empty($data['post_immediately'])) {
                        try {
                            $move = app(DriveInvoicePostingService::class)->postClassification($record, Auth::user());

                            Notification::make()
                                ->success()
                                ->title('Posted to General Ledger')
                                ->body("Successfully created and posted {$move->name}.")
                                ->send();

                            $this->redirect(static::getResource()::getUrl('view', ['record' => $record->id]));

                            return;
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Posting Failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }

                    // Route for approval
                    $approvals = app(ApprovalEngine::class);
                    $requester = User::query()
                        ->where('default_company_id', $record->company_id)
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->first();

                    if ($requester && $approvals->matchingWorkflow($record->company_id, 'drive_ingestion_classification')) {
                        $context = [
                            'company_id'     => $record->company_id,
                            'document_type'  => $record->document_type?->value,
                            'invoice_number' => $record->extracted_invoice_number,
                            'partner_id'     => $record->resolved_partner_id,
                            'amount'         => (string) $record->extracted_amount,
                            'currency_code'  => $record->extracted_currency_code,
                            'extracted_date' => $record->extracted_date?->toDateString(),
                        ];

                        $existing = ApprovalRequest::query()
                            ->where('company_id', $record->company_id)
                            ->where('request_type', 'drive_ingestion_classification')
                            ->where('subject_type', $record->getMorphClass())
                            ->where('subject_id', $record->getKey())
                            ->where('status', 'pending')
                            ->latest('id')
                            ->first();

                        if ($existing) {
                            $existing->update([
                                'amount'  => (string) $record->extracted_amount,
                                'context' => $context,
                            ]);
                            $record->update(['approval_request_id' => $existing->id]);
                            $approvalRequest = $existing;
                        } else {
                            $request = $approvals->submit(
                                $record,
                                $requester,
                                'drive_ingestion_classification',
                                (string) $record->extracted_amount,
                                $context,
                            );
                            $record->update(['approval_request_id' => $request->id]);
                            $approvalRequest = $request;
                        }
                    }

                    Notification::make()
                        ->success()
                        ->title('Classification Resolved & Verified')
                        ->body(isset($approvalRequest)
                            ? 'The document has been mapped to GL account and submitted for approval. '.$approvals->describeCurrentApprover($approvalRequest)
                            : 'The document has been mapped to GL account and marked as Valid for posting.')
                        ->send();

                    $this->refreshFormData(['validation_status', 'resolved_fs_tag_id', 'resolved_partner_id', 'resolved_account_id']);
                }),

            Action::make('reanalyze')
                ->label('Re-analyze')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    if ($this->record->driveIngestion) {
                        app(DriveClassificationService::class)->classify($this->record->driveIngestion);
                        Notification::make()
                            ->success()
                            ->title('Re-analysis Complete')
                            ->body('Document candidates and resolution re-evaluated.')
                            ->send();

                        $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record->id]));
                    }
                }),

            Action::make('download')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => (bool) $this->record->driveIngestion?->document?->currentVersion?->storage_path)
                ->action(function () {
                    $version = $this->record->driveIngestion?->document?->currentVersion;
                    if (! $version) {
                        return null;
                    }

                    $disk = Storage::disk($version->storage_disk ?: 'accounting_documents');
                    if (! $disk->exists($version->storage_path)) {
                        Notification::make()->danger()->title('File not found in storage')->send();

                        return null;
                    }

                    return $disk->download($version->storage_path, $this->record->driveIngestion->filename);
                }),

            Action::make('delete')
                ->label('Delete Document')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->authorize(AccountingPermissions::ManageDocuments)
                ->visible(fn () => $this->record->created_invoice_id === null
                    && $this->record->validation_status !== DriveClassificationStatus::Posted)
                ->requiresConfirmation()
                ->modalHeading('Delete Ingested Document')
                ->modalDescription('Are you sure you want to delete this unposted document? It will be permanently removed from Aureus ERP and moved to Google Drive trash.')
                ->action(function () {
                    try {
                        $result = app(DriveIngestionService::class)->deleteIngestionClassification($this->record, deleteFromDrive: true);
                        $driveMsg = $result['drive_deleted'] ? ' and moved to Google Drive trash' : '';
                        Notification::make()->success()->title('Document Deleted')->body("Document successfully deleted{$driveMsg}.")->send();
                        $this->redirect(static::getResource()::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Delete Failed')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
