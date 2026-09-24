<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\DriveIngestionClassificationResource;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\InvoiceResource;
use Webkul\Accounting\Filament\Clusters\Vendors\Resources\BillResource;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Drive\DriveClassificationService;
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
                        ->default($this->record->document_type?->value)
                        ->required(),
                    Select::make('resolved_partner_id')
                        ->label('Partner')
                        ->options(fn () => Partner::query()->where('company_id', $this->record->company_id)->pluck('name', 'id'))
                        ->default($this->record->resolved_partner_id)
                        ->searchable()
                        ->required(),
                    Select::make('resolved_fs_tag_id')
                        ->label('FS Tag (Financial Statement Tag)')
                        ->options(fn () => FsTag::query()
                            ->where('company_id', $this->record->company_id)
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn ($tag) => [$tag->id => "{$tag->code} - {$tag->name}"])
                        )
                        ->default($this->record->resolved_fs_tag_id)
                        ->searchable()
                        ->helperText('Selecting an FS Tag automatically resolves the GL account for accounting posting.')
                        ->required(),
                    TextInput::make('extracted_invoice_number')
                        ->label('Invoice / Refund #')
                        ->default($this->record->extracted_invoice_number)
                        ->required(),
                    TextInput::make('extracted_amount')
                        ->label('Amount')
                        ->numeric()
                        ->default($this->record->extracted_amount)
                        ->required(),
                    TextInput::make('extracted_currency_code')
                        ->label('Currency Code')
                        ->default($this->record->extracted_currency_code ?: 'PKR')
                        ->required(),
                    DatePicker::make('extracted_date')
                        ->label('Document Date')
                        ->default($this->record->extracted_date),
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
                        } else {
                            $request = $approvals->submit(
                                $record,
                                $requester,
                                'drive_ingestion_classification',
                                (string) $record->extracted_amount,
                                $context,
                            );
                            $record->update(['approval_request_id' => $request->id]);
                        }
                    }

                    Notification::make()
                        ->success()
                        ->title('Classification Resolved')
                        ->body('The document has been mapped to GL account and submitted for approval.')
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

            Action::make('viewInvoice')
                ->label('View Created Invoice')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('success')
                ->visible(fn () => filled($this->record->created_invoice_id))
                ->url(fn () => $this->record->createdInvoice?->isSale()
                    ? InvoiceResource::getUrl('view', ['record' => $this->record->created_invoice_id])
                    : BillResource::getUrl('view', ['record' => $this->record->created_invoice_id])
                ),
        ];
    }
}
