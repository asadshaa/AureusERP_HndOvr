<?php

namespace Webkul\Accounting\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\Drive\InvoiceDriveExportService;
use Webkul\Accounting\Services\Peers\DocumentExchangeService;
use Webkul\Accounting\Services\Peers\WebRtcSignalingService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Support\DriveFolderPathResolver;

/**
 * The one entry point for sending an invoice outside this instance.
 *
 * Deliberately a manual action rather than something that fires on post:
 * posting is an internal accounting event, and it should never silently
 * transmit data to another organisation as a side effect.
 */
class SendInvoiceToPeerAction
{
    public static function make(): Action
    {
        return Action::make('sendToPeer')
            ->label('Send to...')
            ->icon('heroicon-o-paper-airplane')
            ->authorize(AccountingPermissions::SendTransmissions)
            ->schema([
                Radio::make('channel')
                    ->label('Send to')
                    ->options(fn (): array => array_filter([
                        'peer'     => 'A paired AureusERP instance',
                        'external' => 'Someone without AureusERP (email + secure link)',
                        // Browser-to-browser. Needs the recipient online now,
                        // which is why it is never the default.
                        'direct'   => config('webrtc.enabled', true)
                            ? 'Someone who is online right now (direct encrypted browser transfer)'
                            : null,
                        'drive'    => config('accounting_drive.enabled', false)
                            ? 'Linked Google Drive'
                            : null,
                    ]))
                    ->default('peer')
                    ->live()
                    ->required(),

                Placeholder::make('drive_info')
                    ->label('Destination')
                    ->content(function (Move $record): string {
                        $company = $record->company;
                        $path = $company
                            ? app(DriveFolderPathResolver::class)->resolveInboundFolder($company)
                            : [config('accounting_drive.root_folder_name', 'Aureus'), 'Company', config('accounting_drive.inbound_folder_name', 'Inbound')];

                        return 'Will be uploaded to your company Google Drive folder: '.implode(' > ', $path);
                    })
                    ->visible(fn (Get $get) => $get('channel') === 'drive'),

                Select::make('peer_id')
                    ->label('Peer')
                    ->options(fn () => Peer::query()
                        ->where('company_id', Auth::user()?->default_company_id)
                        ->active()
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn (Get $get) => $get('channel') === 'peer')
                    ->required(fn (Get $get) => $get('channel') === 'peer'),

                TextInput::make('email')
                    ->label('Recipient email')
                    ->email()
                    ->visible(fn (Get $get) => $get('channel') === 'external')
                    ->required(fn (Get $get) => $get('channel') === 'external'),
            ])
            ->action(function (Move $record, array $data) {
                $exchange = app(DocumentExchangeService::class);

                try {
                    if ($data['channel'] === 'drive') {
                        app(InvoiceDriveExportService::class)->exportInvoice(Auth::user(), $record);

                        Notification::make()->success()
                            ->title('Exported to Google Drive')
                            ->body("Invoice {$record->name} was successfully uploaded to your linked Google Drive folder.")
                            ->send();

                        return;
                    }
                    // Hands the sender off to their own transfer console,
                    // where their browser makes the offer. Nothing is queued:
                    // this transport has no store-and-forward, by design.
                    if ($data['channel'] === 'direct') {
                        $session = app(WebRtcSignalingService::class)->createSession(
                            actor: Auth::user(),
                            transmittable: $record,
                            ipAddress: request()->ip(),
                        );

                        return redirect()->to(route('accounting.webrtc.send', ['code' => $session->code]));
                    }

                    if ($data['channel'] === 'peer') {
                        $peer = Peer::query()->findOrFail($data['peer_id']);

                        $exchange->sendInvoiceToPeer(Auth::user(), $record, $peer, request()->ip());

                        Notification::make()->success()
                            ->title('Queued for delivery')
                            ->body("Sending to {$peer->name}. Delivery is retried automatically if they are briefly unreachable.")
                            ->send();

                        return;
                    }

                    $result = $exchange->sendInvoiceToEmail(Auth::user(), $record, $data['email'], request()->ip());

                    $url = route('accounting.claim.show', ['token' => $result['claim_token']]);

                    Notification::make()->success()
                        ->title('Secure link created')
                        // Shown once -- only the hash is stored, so this URL
                        // cannot be recovered later.
                        ->body("Send this to {$data['email']}: {$url}")
                        ->persistent()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Could not send')->body($e->getMessage())->send();
                }
            });
    }
}
