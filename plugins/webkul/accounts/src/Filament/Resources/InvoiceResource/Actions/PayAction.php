<?php

namespace Webkul\Account\Filament\Resources\InvoiceResource\Actions;

use Closure;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use Throwable;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Mail\Invoice\Actions\InvoiceEmail;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Models\PaymentRegister;
use Webkul\Accounting\Models\Journal;
use Webkul\Accounting\Services\Drive\InvoiceDriveExportService;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\EmailService;
use Webkul\Support\Traits\PDFHandler;

class PayAction extends Action
{
    use PDFHandler;

    protected bool|Closure $hasDatabaseTransactions = true;

    public static function getDefaultName(): ?string
    {
        return 'customers.invoice.pay';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('accounts::filament/resources/invoice/actions/pay-action.title'))
            ->color('success')
            ->schema(function (Schema $schema) {
                $paymentRegister = new PaymentRegister;

                try {
                    $paymentRegister->lines = $this->getRecord()->lines;
                    $paymentRegister->company = $this->getRecord()->company;
                    $paymentRegister->currency = $this->getRecord()->currency;
                    $paymentRegister->currency_id = $this->getRecord()->currency_id;
                    $paymentRegister->payment_type = $this->getRecord()->isInbound(true)
                        ? PaymentType::RECEIVE
                        : PaymentType::SEND;
                    $paymentRegister->computeBatches();
                    $paymentRegister->computeAvailableJournalIds();
                    $paymentRegister->journal_id = $paymentRegister->available_journal_ids[0] ?? null;
                    $paymentRegister->journal = Journal::find($paymentRegister->journal_id);

                    $paymentRegister->computePaymentMethodLineId();

                    $amountsToPay = $paymentRegister->getTotalAmountsToPay($paymentRegister->batches);
                    $paymentRegister->amount = $amountsToPay['amount_by_default'];
                    $paymentRegister->computeInstallmentsMode();
                } catch (Exception $e) {
                    Notification::make()
                        ->title(__('accounts::filament/resources/invoice/actions/pay-action.notifications.payment-failed.title'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }

                return $schema->components([
                    Group::make()
                        ->schema([
                            Select::make('journal_id')
                                ->relationship(
                                    'journal',
                                    'name',
                                    modifyQueryUsing: fn (Builder $query) => $query->whereIn('id', $paymentRegister->available_journal_ids)
                                )
                                ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.journal'))
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->default(fn () => $paymentRegister->available_journal_ids[0] ?? null)
                                ->afterStateUpdated(function (Set $set, Get $get) use ($paymentRegister) {
                                    $paymentRegister->journal_id = $get('journal_id');
                                    $paymentRegister->journal = Journal::find($get('journal_id'));
                                    $paymentRegister->computePaymentMethodLineId();

                                    $set('payment_method_line_id', $paymentRegister->payment_method_line_id);
                                    $set('partner_bank_id', null);
                                }),

                            Select::make('payment_method_line_id')
                                ->label(__('accounts::filament/resources/invoice.form.tabs.other-information.fieldset.accounting.fields.payment-method'))
                                ->required()
                                ->searchable()
                                ->preload()
                                ->live()
                                ->default($paymentRegister->payment_method_line_id)
                                ->relationship(
                                    name: 'paymentMethodLine',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: function (Builder $query, Get $get) {
                                        $journal = Journal::find($get('journal_id'));

                                        if (! $journal) {
                                            return $query->whereRaw('1 = 0');
                                        }

                                        $paymentMethodLineIds = $journal->getAvailablePaymentMethodLines(
                                            $this->getRecord()->isInbound(true)
                                                ? PaymentType::RECEIVE
                                                : PaymentType::SEND
                                        )->pluck('id');

                                        $query->whereIn('id', $paymentMethodLineIds);
                                    }
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name)
                                ->afterStateUpdated(function (Set $set, Get $get) use ($paymentRegister) {
                                    $paymentRegister->payment_method_line_id = $get('payment_method_line_id');
                                    $paymentRegister->paymentMethodLine = PaymentMethodLine::find($get('payment_method_line_id'));
                                    $paymentRegister->journal = Journal::find($get('journal_id'));
                                    $paymentRegister->computeShowRequirePartnerBank();
                                }),
                            Select::make('partner_bank_id')
                                ->relationship(
                                    'partnerBank',
                                    'account_number',
                                    modifyQueryUsing: function (Builder $query, Get $get) {
                                        $companyId = $get('company_id') ?? filament()->auth()->user()->default_company_id;

                                        $bankAccountIds = \Webkul\Account\Models\Journal::where('type', JournalType::BANK)
                                            ->where('company_id', $companyId)
                                            ->pluck('bank_account_id')
                                            ->filter();

                                        $query->whereIn('id', $bankAccountIds);
                                    }
                                )
                                ->getOptionLabelFromRecordUsing(function ($record): string {
                                    return $record->account_number.' - '.$record->bank->name.($record->trashed() ? ' (Deleted)' : '');
                                })
                                ->disableOptionWhen(function ($label) {
                                    return str_contains($label, ' (Deleted)');
                                })
                                ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.partner-bank-account'))
                                ->searchable()
                                ->preload()
                                ->required(function (Get $get) use ($paymentRegister) {
                                    $journal = Journal::find($get('journal_id'));

                                    if (! $journal) {
                                        return false;
                                    }

                                    $paymentRegister->journal = $journal;
                                    $paymentRegister->payment_method_line_id = $get('payment_method_line_id');
                                    $paymentRegister->paymentMethodLine = PaymentMethodLine::find($get('payment_method_line_id'));

                                    if (! $paymentRegister->paymentMethodLine) {
                                        return false;
                                    }

                                    $paymentRegister->computeShowRequirePartnerBank();

                                    return $paymentRegister->require_partner_bank_account && $paymentRegister->show_partner_bank_account;
                                })
                                ->visible(function (Get $get) use ($paymentRegister) {
                                    $journal = Journal::find($get('journal_id'));

                                    if (! $journal) {
                                        return false;
                                    }

                                    $paymentRegister->journal = $journal;
                                    $paymentRegister->payment_method_line_id = $get('payment_method_line_id');
                                    $paymentRegister->paymentMethodLine = PaymentMethodLine::find($get('payment_method_line_id'));

                                    if (! $paymentRegister->paymentMethodLine) {
                                        return false;
                                    }

                                    $paymentRegister->computeShowRequirePartnerBank();

                                    return $paymentRegister->show_partner_bank_account;
                                })
                                ->disabled(function (Get $get) use ($paymentRegister) {
                                    $journal = Journal::find($get('journal_id'));

                                    if (! $journal) {
                                        return true;
                                    }

                                    $paymentRegister->journal = $journal;
                                    $paymentRegister->payment_method_line_id = $get('payment_method_line_id');
                                    $paymentRegister->paymentMethodLine = PaymentMethodLine::find($get('payment_method_line_id'));

                                    if (! $paymentRegister->paymentMethodLine) {
                                        return true;
                                    }

                                    $paymentRegister->computeShowRequirePartnerBank();

                                    return ! $paymentRegister->require_partner_bank_account;
                                }),
                        ]),

                    Group::make()
                        ->schema([
                            Group::make()
                                ->schema([
                                    Hidden::make('installments_mode')
                                        ->default($paymentRegister->installments_mode),
                                    TextInput::make('amount')
                                        ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.amount'))
                                        ->prefix(fn ($record) => $record->currency->symbol ?? '')
                                        ->default($paymentRegister->amount)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, $state) use ($paymentRegister) {
                                            $paymentRegister->amount = $state;
                                            $paymentRegister->computeInstallmentsMode();
                                            $set('installments_mode', $paymentRegister->installments_mode);
                                        })
                                        ->helperText(function (Get $get) use ($paymentRegister) {
                                            $paymentRegister->amount = $get('amount') ?? $paymentRegister->amount;
                                            $paymentRegister->installments_mode = $get('installments_mode') ?? $paymentRegister->installments_mode;

                                            $switchValues = $paymentRegister->computeInstallmentsSwitchValues();

                                            if (! $switchValues['installments_switch_html']) {
                                                return null;
                                            }

                                            return new HtmlString($switchValues['installments_switch_html']);
                                        })
                                        ->hintAction(
                                            Action::make('toggleInstallments')
                                                ->label(function (Get $get) use ($paymentRegister) {
                                                    $installmentsMode = $get('installments_mode') ?? $paymentRegister->installments_mode;

                                                    return $installmentsMode === 'full' ? 'installments' : 'full amount';
                                                })
                                                ->link()
                                                ->action(function (Set $set, Get $get) use ($paymentRegister) {
                                                    $switchValues = $paymentRegister->computeInstallmentsSwitchValues();

                                                    if ($switchValues['installments_switch_amount'] > 0) {
                                                        $paymentRegister->amount = $switchValues['installments_switch_amount'];
                                                        $paymentRegister->computeInstallmentsMode();

                                                        $set('amount', $paymentRegister->amount);
                                                        $set('installments_mode', $paymentRegister->installments_mode);
                                                    }
                                                })
                                        ),
                                    Select::make('currency_id')
                                        ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.currency'))
                                        ->relationship(
                                            name: 'currency',
                                            titleAttribute: 'name',
                                            modifyQueryUsing: fn (Builder $query) => $query->active(),
                                        )
                                        ->default(function ($record, Get $get) {
                                            $journal = Journal::find($get('journal_id'));

                                            if (! $journal) {
                                                return $record->currency_id;
                                            }

                                            return $journal->currency_id ?? $record->currency_id;
                                        })
                                        ->required()
                                        ->searchable()
                                        ->preload()
                                        ->required(),
                                ])
                                ->columns(2),
                            DatePicker::make('payment_date')
                                ->native(false)
                                ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.payment-date'))
                                ->default(now())
                                ->required(),
                            TextInput::make('communication')
                                ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.communication'))
                                ->default(function ($record) {
                                    return $record->name;
                                })
                                ->required(),
                        ]),

                    Section::make(__('Vendor Notification & Google Drive Sync'))
                        ->schema([
                            Checkbox::make('send_receipt')
                                ->label(fn () => $this->getRecord()->isInbound(true)
                                    ? __('Send payment receipt to customer')
                                    : __('Send paid bill receipt to vendor')
                                )
                                ->default(fn () => filled($this->getRecord()->partner?->email))
                                ->live(),
                            TextInput::make('recipient_email')
                                ->label(fn () => $this->getRecord()->isInbound(true)
                                    ? __('Customer Email')
                                    : __('Vendor Email')
                                )
                                ->email()
                                ->default(fn () => $this->getRecord()->partner?->email)
                                ->visible(fn (Get $get) => (bool) $get('send_receipt'))
                                ->required(fn (Get $get) => (bool) $get('send_receipt')),
                            TextInput::make('email_subject')
                                ->label(__('Email Subject'))
                                ->default(function () {
                                    $partnerName = $this->getRecord()->partner?->name ?? '';
                                    $recordName = $this->getRecord()->name;

                                    return $this->getRecord()->isInbound(true)
                                        ? "Payment Receipt - {$recordName} ({$partnerName})"
                                        : "Paid Bill Receipt - {$recordName} ({$partnerName})";
                                })
                                ->visible(fn (Get $get) => (bool) $get('send_receipt')),
                            Checkbox::make('sync_to_paid_drive')
                                ->label(__('Upload to Google Drive Paid Invoices folder'))
                                ->helperText(__('Automatically upload a copy of this paid invoice/bill to your company\'s Paid Invoices folder in Google Drive.'))
                                ->default(true)
                                ->visible(fn () => (bool) config('accounting_drive.enabled', false)),
                        ])
                        ->collapsible()
                        ->columnSpanFull(),
                ])
                    ->columns(2);
            })
            ->action(function (Move $record, $data, Component $livewire): void {
                try {
                    $sendReceipt = ! empty($data['send_receipt']);
                    $recipientEmail = $data['recipient_email'] ?? null;
                    $emailSubject = $data['email_subject'] ?? null;
                    $syncToPaidDrive = ! empty($data['sync_to_paid_drive']);

                    $paymentCurrency = Currency::find($data['currency_id'] ?? $record->currency_id);

                    if ($record->currency && $paymentCurrency) {
                        $record->currency->getConversionRate(
                            $record->currency,
                            $paymentCurrency,
                            $record->company,
                            $data['payment_date'] ?? now(),
                            strict: true,
                        );
                    }

                    $lineIds = $record->paymentTermLines
                        ->filter(fn ($line) => ! $line->reconciled)
                        ->pluck('id')
                        ->toArray();

                    $paymentRegister = PaymentRegister::create($data);

                    $paymentRegister->lines()->sync($lineIds);

                    $paymentRegister->refresh();

                    $paymentRegister->computeFromLines();

                    $paymentRegister->save();

                    AccountFacade::createPayments($paymentRegister);

                    $record->refresh();

                    // If partner didn't have an email, update it from recipient_email
                    if ($record->partner && ! $record->partner->email && filled($recipientEmail)) {
                        $record->partner->update(['email' => $recipientEmail]);
                    }

                    // Generate paid PDF if email sending or Drive sync is enabled
                    $pdfDiskPath = null;
                    if ($sendReceipt || ($syncToPaidDrive && config('accounting_drive.enabled', false))) {
                        try {
                            $template = match ($record->move_type) {
                                MoveType::IN_INVOICE => 'accounts::bill/actions/preview.index',
                                MoveType::IN_REFUND  => 'accounts::refund/actions/preview.index',
                                default              => 'accounts::invoice/actions/preview.index',
                            };

                            if (! view()->exists($template)) {
                                $template = 'accounts::invoice/actions/preview.index';
                            }

                            $cleanName = str_replace(['/', '\\'], '-', $record->name ?: 'bill-'.$record->id);
                            $html = view($template, ['record' => $record])->render();
                            $pdfDiskPath = $this->savePDF($html, 'paid-'.$cleanName.'-'.now()->format('YmdHis'));
                        } catch (Throwable $pdfError) {
                            Notification::make()
                                ->title(__('PDF Generation Notice'))
                                ->body($pdfError->getMessage())
                                ->warning()
                                ->send();
                        }
                    }

                    // Send email with attached paid bill/invoice
                    if ($sendReceipt && filled($recipientEmail)) {
                        try {
                            $partnerName = $record->partner?->name ?? ($record->isInbound(true) ? 'Customer' : 'Vendor');
                            $companyName = $record->company?->name ?? 'Aureus ERP';
                            $amountFormatted = money($data['amount'] ?? $record->amount_total, $record->currency?->name ?? 'USD');
                            $paymentDate = $data['payment_date'] ?? now()->format('Y-m-d');
                            $isVendorBill = ! $record->isInbound(true);

                            $subject = $emailSubject ?: ($isVendorBill
                                ? "Paid Bill Receipt - {$record->name} ({$companyName})"
                                : "Payment Receipt - Invoice {$record->name}");

                            $description = $isVendorBill
                                ? "<p>Dear {$partnerName},</p><p>We are pleased to inform you that payment for bill <strong>{$record->name}</strong> from <strong>{$companyName}</strong> in the amount of <strong>{$amountFormatted}</strong> has been processed successfully on <strong>{$paymentDate}</strong>.</p><p>Please find attached the official paid bill receipt for your accounting records.</p><p>If you have any questions regarding this transaction, please do not hesitate to contact us.</p><p><strong>Best regards,</strong><br>{$companyName}</p>"
                                : "<p>Dear {$partnerName},</p><p>Thank you for your payment of <strong>{$amountFormatted}</strong> for invoice <strong>{$record->name}</strong> received on <strong>{$paymentDate}</strong>.</p><p>Please find attached the official payment receipt for your records.</p><p><strong>Best regards,</strong><br>{$companyName}</p>";

                            $payload = [
                                'record_name' => $record->name,
                                'model_name'  => class_basename($record),
                                'subject'     => $subject,
                                'description' => $description,
                                'to'          => [
                                    'address' => $recipientEmail,
                                    'name'    => $partnerName,
                                ],
                                'from'        => [
                                    'address' => Auth::user()?->email ?? config('mail.from.address'),
                                    'name'    => Auth::user()?->name ?? $companyName,
                                    'company' => $record->company?->toArray() ?? Auth::user()?->defaultCompany?->toArray(),
                                ],
                            ];

                            $attachments = [];
                            if ($pdfDiskPath) {
                                $attachments[] = [
                                    'path' => $pdfDiskPath,
                                    'name' => str_replace(['/', '\\'], '-', $record->name).'-PAID.pdf',
                                    'mime' => 'application/pdf',
                                ];
                            }

                            app(EmailService::class)->send(
                                view: 'accounts::mail/invoice/actions/invoice',
                                mailClass: InvoiceEmail::class,
                                payload: $payload,
                                attachments: $attachments,
                            );

                            $record->addMessage([
                                'from' => [
                                    'company' => $record->company?->toArray() ?? Auth::user()?->defaultCompany?->toArray(),
                                ],
                                'body' => view('accounts::mail/invoice/actions/invoice', ['payload' => $payload])->render(),
                                'type' => 'comment',
                            ], Auth::id());

                            Notification::make()
                                ->title(__('Receipt Sent'))
                                ->body(__('Paid receipt sent to :email', ['email' => $recipientEmail]))
                                ->success()
                                ->send();
                        } catch (Throwable $mailError) {
                            Notification::make()
                                ->title(__('Email Notice'))
                                ->body($mailError->getMessage())
                                ->warning()
                                ->send();
                        }
                    }

                    // Upload to Google Drive Paid Invoices folder
                    if ($syncToPaidDrive && config('accounting_drive.enabled', false)) {
                        try {
                            app(InvoiceDriveExportService::class)->exportPaidInvoice(
                                user: Auth::user(),
                                invoice: $record,
                                pdfDiskPath: $pdfDiskPath,
                            );

                            Notification::make()
                                ->title(__('Uploaded to Google Drive'))
                                ->body(__('A copy of :name has been saved to your Google Drive "Paid Invoices" folder.', ['name' => $record->name]))
                                ->success()
                                ->send();
                        } catch (Throwable $driveError) {
                            Notification::make()
                                ->title(__('Google Drive Notice'))
                                ->body($driveError->getMessage())
                                ->warning()
                                ->send();
                        }
                    }

                    if (method_exists($livewire, 'refreshFormData')) {
                        $livewire->refreshFormData(['state', 'payment_state', 'amount_residual']);
                    }

                    $livewire->dispatch('refreshInvoiceSummary');
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->body($e->getMessage())
                        ->send();

                    $this->halt(shouldRollBackDatabaseTransaction: true);
                }
            })
            ->hidden(function (Move $record) {
                return $record->state != MoveState::POSTED
                    || ! in_array($record->payment_state, [
                        PaymentState::NOT_PAID,
                        PaymentState::PARTIAL,
                        PaymentState::IN_PAYMENT,
                    ]);
            });
    }
}
