<?php

namespace Webkul\Account\Filament\Resources\InvoiceResource\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Services\Drive\InvoiceDriveExportService;
use Webkul\Support\Traits\PDFHandler;

class PrintAndSendAction extends Action
{
    use PDFHandler;

    public static function getDefaultName(): ?string
    {
        return 'customers.invoice.print-and-send';
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

            $description = "
                    <p>Dear {$record->partner->name},</p>
                    <p>Your invoice <strong>{$record->name}</strong> from <strong>{$record->company->name}</strong> for <strong>{$money($record->amount_total, $record->currency->name)}</strong> is now available. Kindly arrange payment at your earliest convenience.</p>
                    <p>When making the payment, please reference <strong>{$record->name}</strong> for account <strong>".($record->partnerBank->bank->name ?? 'N/A').'</strong>.</p>
                    <p>If you have any questions, feel free to reach out.</p>
                    <p><strong>Best regards,</strong><br>Administrator</p>
                ';

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
                        ->preload(),
                    TextInput::make('subject')
                        ->label(__('accounts::filament/resources/invoice/actions/print-and-send.modal.form.subject'))
                        ->hiddenLabel(),
                    RichEditor::make('description')
                        ->label(__('accounts::filament/resources/invoice/actions/print-and-send.modal.form.description'))
                        ->hiddenLabel(),
                    FileUpload::make('files')
                        ->label(__('accounts::filament/resources/invoice/actions/print-and-send.modal.form.files'))
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
            AccountFacade::printAndSendMove($record, $data);

            if (! empty($data['sync_to_drive']) && config('accounting_drive.enabled', false)) {
                try {
                    $primaryFile = null;
                    if (! empty($data['files'])) {
                        $primaryFile = is_array($data['files']) ? reset($data['files']) : $data['files'];
                    }

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
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('Google Drive upload warning'))
                        ->body($e->getMessage())
                        ->warning()
                        ->send();
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
            view('accounts::invoice/actions/preview.index', compact('record'))->render(),
            'invoice-'.$record->created_at->format('d-m-Y')
        );
    }
}
