<?php

namespace Webkul\Account\Filament\Resources\InvoiceResource\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Services\Drive\InvoiceDriveExportService;
use Webkul\Support\Traits\PDFHandler;

class PrintAndSendAction extends Action
{
    use PDFHandler;

    protected string $template = 'accounts::invoice/actions/preview.index';

    public static function getDefaultName(): ?string
    {
        return 'customers.invoice.print-and-send';
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function setTemplate(string $template): static
    {
        $this->template = $template;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('accounts::filament/resources/invoice/actions/print-and-send.title'))
            ->color('gray')
            ->visible(function (Move $record) {
                return
                    $record->state == MoveState::CANCEL
                    || $record->state == MoveState::POSTED;
            });

        $this->beforeFormFilled(function (Move $record, Action $action) {
            $money = function ($amount, $currency) {
                return money($amount, $currency);
            };

            // A Bill is money WE owe a vendor -- "kindly arrange payment"
            // only makes sense the other way around, for a customer
            // Invoice. isInbound(true) is false for a vendor bill.
            $description = $record->isInbound(true)
                ? "
                    <p>Dear {$record->partner->name},</p>
                    <p>Your invoice <strong>{$record->name}</strong> from <strong>{$record->company->name}</strong> for <strong>{$money($record->amount_total, $record->currency->name)}</strong> is now available. Kindly arrange payment at your earliest convenience.</p>
                    <p>When making the payment, please reference <strong>{$record->name}</strong> for account <strong>".($record->partnerBank->bank->name ?? 'N/A').'</strong>.</p>
                    <p>If you have any questions, feel free to reach out.</p>
                    <p><strong>Best regards,</strong><br>Administrator</p>
                '
                : "
                    <p>Dear {$record->partner->name},</p>
                    <p>Please find attached bill <strong>{$record->name}</strong> from <strong>{$record->company->name}</strong> for <strong>{$money($record->amount_total, $record->currency->name)}</strong>, recorded on our side for reference.</p>
                    <p>If you have any questions, feel free to reach out.</p>
                    <p><strong>Best regards,</strong><br>Administrator</p>
                ";

            $action->fillForm([
                'files'         => $this->prepareInvoice($record),
                'partners'      => [$record->partner_id],
                'subject'       => $record->partner->name.' Invoice (Ref '.$record->name.')',
                'description'   => $description,
                'sync_to_drive' => true,
            ]);
        });

        $this->schema(
            function (Schema $schema) {
                return $schema->components([
                    Select::make('partners')
                        ->options(Partner::all()->pluck('name', 'id'))
                        ->multiple()
                        ->label(__('accounts::filament/resources/invoice/actions/print-and-send.modal.form.partners'))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->helperText(function (Get $get) {
                            $ids = $get('partners') ?? [];

                            if (empty($ids)) {
                                return null;
                            }

                            $override = trim((string) ($get('override_email') ?? ''));

                            $partners = Partner::whereIn('id', $ids)->get(['id', 'name', 'email']);

                            $lines = $partners->map(function (Partner $partner) use ($override) {
                                if ($override !== '') {
                                    return "{$partner->name} → {$override} (override)";
                                }

                                return $partner->email
                                    ? "{$partner->name} → {$partner->email}"
                                    : "{$partner->name} → ⚠ no email on file, will NOT receive anything";
                            });

                            return new HtmlString(implode('<br>', $lines->all()));
                        }),
                    TextInput::make('override_email')
                        ->label(__('Send to a different email instead (optional)'))
                        ->email()
                        ->live()
                        ->helperText(__('Leave blank to use each selected recipient\'s own email above. Fill this in to send to a specific person instead -- e.g. a particular contact at the vendor rather than their listed inbox.')),
                    TextInput::make('subject')
                        ->label(__('accounts::filament/resources/invoice/actions/print-and-send.modal.form.subject'))
                        ->hiddenLabel(),
                    RichEditor::make('description')
                        ->label(__('accounts::filament/resources/invoice/actions/print-and-send.modal.form.description'))
                        ->hiddenLabel(),
                    FileUpload::make('files')
                        ->label(__('accounts::filament/resources/invoice/actions/print-and-send.modal.form.files'))
                        ->helperText(__('Includes the generated invoice/bill by default. Drag in more files to attach alongside it -- e.g. a screenshot of the bank transfer or EasyPaisa transaction, as proof of payment for the vendor to confirm.'))
                        ->acceptedFileTypes([
                            'image/*',
                            'application/pdf',
                        ])
                        ->downloadable()
                        ->openable()
                        ->multiple()
                        ->disk('public')
                        ->hiddenLabel(),
                    Checkbox::make('sync_to_drive')
                        ->label(__('Also upload to linked Google Drive'))
                        ->helperText(__('Automatically save a copy of this invoice to your company\'s Google Drive.'))
                        ->default(true)
                        ->visible(fn () => (bool) config('accounting_drive.enabled', false)),
                ]);
            }
        );

        $this->modalSubmitActionLabel(__('accounts::filament/resources/invoice/actions/print-and-send.modal.action.submit.title'));
        $this->modalIcon('heroicon-m-paper-airplane');
        $this->icon('heroicon-o-envelope');
        $this->action(function (Move $record, array $data) {
            // printAndSendMove() silently skips any selected partner with no
            // email on file -- surface that here instead of it looking like
            // the send succeeded for everyone selected. Not relevant when an
            // override email is set, since that's used for every recipient.
            $partnersWithoutEmail = filled($data['override_email'] ?? null)
                ? collect()
                : Partner::whereIn('id', $data['partners'] ?? [])
                    ->whereNull('email')
                    ->pluck('name');

            if ($partnersWithoutEmail->isNotEmpty()) {
                Notification::make()
                    ->title(__('Some recipients were skipped'))
                    ->body(__(':names have no email on file, so they did not receive this invoice.', ['names' => $partnersWithoutEmail->implode(', ')]))
                    ->warning()
                    ->send();
            }

            AccountFacade::printAndSendMove($record, $data);

            if (! empty($data['sync_to_drive']) && config('accounting_drive.enabled', false)) {
                $files = ! empty($data['files'])
                    ? (is_array($data['files']) ? array_values($data['files']) : [$data['files']])
                    : [];

                $primaryFile = $files[0] ?? null;
                $extraFiles = array_slice($files, 1);
                $isFullyPaid = $record->payment_state === PaymentState::PAID;

                try {
                    // Fully paid records go into "Paid Invoices/{vendor or
                    // customer name}", keeping paid documents organised by
                    // counterparty and separate from unpaid ones -- an
                    // unpaid record still goes to the regular Invoices
                    // folder, matching exportInvoice()'s existing behaviour.
                    if ($isFullyPaid) {
                        app(InvoiceDriveExportService::class)->exportPaidInvoice(
                            user: Auth::user(),
                            invoice: $record,
                            pdfDiskPath: $primaryFile,
                        );

                        Notification::make()
                            ->title(__('Uploaded to Google Drive'))
                            ->body(__('A copy of :name has been saved to your Google Drive "Paid Invoices" folder.', ['name' => $record->name]))
                            ->success()
                            ->send();
                    } else {
                        app(InvoiceDriveExportService::class)->exportInvoice(
                            Auth::user(),
                            $record,
                            $primaryFile
                        );

                        Notification::make()
                            ->title(__('Invoice uploaded to Google Drive'))
                            ->body(__('A copy of invoice :name has been saved to your linked Google Drive folder.', ['name' => $record->name]))
                            ->success()
                            ->send();
                    }
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('Google Drive upload warning'))
                        ->body($e->getMessage())
                        ->warning()
                        ->send();
                }

                // Any file beyond the first (e.g. a payment-proof
                // screenshot) uploads as its own file into the exact same
                // folder the invoice/bill above just went into, so proof of
                // payment sits right next to the document it proves.
                foreach ($extraFiles as $index => $extraFile) {
                    try {
                        app(InvoiceDriveExportService::class)->exportSupportingFile(
                            Auth::user(),
                            $record,
                            $extraFile,
                            'Payment proof '.($index + 1).' - '.$record->name,
                        );
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title(__('Google Drive upload warning'))
                            ->body($e->getMessage())
                            ->warning()
                            ->send();
                    }
                }
            }
        });
        $this->modalSubmitAction(function ($action) {
            $action->label(__('accounts::filament/resources/invoice/actions/print-and-send.modal.action.submit.title'));
            $action->icon('heroicon-m-paper-airplane');
        });
    }

    private function prepareInvoice(Move $record): ?string
    {
        return $this->savePDF(
            view($this->getTemplate(), compact('record'))->render(),
            'invoice-'.$record->created_at->format('d-m-Y')
        );
    }
}
