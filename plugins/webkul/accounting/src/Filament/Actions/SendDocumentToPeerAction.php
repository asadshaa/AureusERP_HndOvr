<?php

namespace Webkul\Accounting\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\Peers\DocumentExchangeService;
use Webkul\Accounting\Services\Peers\WebRtcSignalingService;
use Webkul\Accounting\Support\AccountingPermissions;

/**
 * Sends a supporting document (the actual file) to a peer instance or to an
 * outside recipient.
 *
 * Mirrors SendInvoiceToPeerAction deliberately: same channels, same
 * permission, same manual-only trigger. A user should not have to learn two
 * different flows depending on whether the thing being sent is an invoice
 * or a file.
 */
class SendDocumentToPeerAction
{
    /**
     * @param  ?Closure  $resolveDocument  Maps the table row to a Document.
     *                                     Needed because this action is used
     *                                     both on DocumentResource (rows ARE
     *                                     Documents) and on the attachments
     *                                     relation manager (rows are
     *                                     DocumentAttachments pointing at one).
     */
    public static function make(?Closure $resolveDocument = null): Action
    {
        return Action::make('sendDocumentToPeer')
            ->label('Send to...')
            ->icon('heroicon-o-paper-airplane')
            ->authorize(AccountingPermissions::SendTransmissions)
            ->schema([
                Radio::make('channel')
                    ->label('Send to')
                    ->options(fn (): array => array_filter([
                        'peer'     => 'A paired AureusERP instance',
                        'external' => 'Someone without AureusERP (secure link)',
                        'direct'   => config('webrtc.enabled', true)
                            ? 'Someone who is online right now (direct encrypted browser transfer)'
                            : null,
                    ]))
                    ->default('peer')
                    ->live()
                    ->required(),

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
            ->action(function (Model $record, array $data) use ($resolveDocument) {
                $exchange = app(DocumentExchangeService::class);

                /** @var Document $document */
                $document = $resolveDocument ? $resolveDocument($record) : $record;

                try {
                    if ($data['channel'] === 'direct') {
                        $session = app(WebRtcSignalingService::class)->createSession(
                            actor: Auth::user(),
                            transmittable: $document,
                            ipAddress: request()->ip(),
                        );

                        return redirect()->to(route('accounting.webrtc.send', ['code' => $session->code]));
                    }

                    if ($data['channel'] === 'peer') {
                        $peer = Peer::query()->findOrFail($data['peer_id']);

                        $exchange->sendDocumentToPeer(Auth::user(), $document, $peer, request()->ip());

                        Notification::make()->success()
                            ->title('File queued for delivery')
                            ->body("Sending \"{$document->title}\" to {$peer->name}.")
                            ->send();

                        return;
                    }

                    $result = $exchange->sendDocumentToEmail(Auth::user(), $document, $data['email'], request()->ip());

                    $url = route('accounting.claim.show', ['token' => $result['claim_token']]);

                    Notification::make()->success()
                        ->title('Secure link created')
                        // Shown once -- only the hash is stored.
                        ->body("Send this to {$data['email']}: {$url}")
                        ->persistent()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Could not send')->body($e->getMessage())->send();
                }
            });
    }
}
