<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Webkul\Accounting\Enums\PeerStatus;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\PeerResource\Pages\ListPeers;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\Peers\PeerPairingService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Partner\Models\Partner;

/**
 * Managing trust relationships with other AureusERP instances.
 *
 * There is intentionally no create/edit form: a peer is not a record you
 * type in, it is the product of a two-sided handshake. You either issue an
 * invitation or redeem one, which is why both entry points are header
 * actions rather than a CreateAction.
 */
class PeerResource extends Resource
{
    protected static ?string $model = Peer::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string
    {
        return 'Peer Instances';
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManagePeers) ?? false;
    }

    /** Peers are established by handshake, never typed in directly. */
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Peer')->searchable(),
                TextColumn::make('endpoint_url')->label('Endpoint')->limit(40)->placeholder('—'),
                TextColumn::make('partner.name')
                    ->label('Linked vendor')
                    ->placeholder('Not linked')
                    // Without this link an inbound invoice cannot be accepted,
                    // so it is worth shouting about on the list itself.
                    ->color(fn ($state) => $state ? null : 'danger'),
                TextColumn::make('status')->badge(),
                TextColumn::make('last_seen_at')->label('Last seen')->dateTime()->placeholder('Never'),
            ])
            ->headerActions([
                Action::make('invite')
                    ->label('Invite a peer')
                    ->icon('heroicon-o-paper-airplane')
                    ->authorize(AccountingPermissions::ManagePeers)
                    ->schema([
                        TextInput::make('name')->label('Their organisation')->required()->maxLength(255),
                        Select::make('partner_id')
                            ->label('Linked vendor (optional for now)')
                            ->options(fn () => Partner::query()
                                ->where('company_id', Auth::user()?->default_company_id)
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Required before you can accept invoices from them.'),
                    ])
                    ->action(function (array $data) {
                        $result = app(PeerPairingService::class)->invite(
                            Auth::user(),
                            $data['name'],
                            $data['partner_id'] ?? null,
                        );

                        Notification::make()
                            ->success()
                            ->title('Pairing code created')
                            // Shown once: only the hash is stored.
                            ->body("Give this to the other instance: {$result['code']}")
                            ->persistent()
                            ->send();
                    }),

                Action::make('redeem')
                    ->label('Enter a pairing code')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->authorize(AccountingPermissions::ManagePeers)
                    ->schema([
                        TextInput::make('name')->label('Their organisation')->required(),
                        TextInput::make('endpoint_url')
                            ->label('Their AureusERP URL')
                            ->required()
                            ->helperText('e.g. https://erp.theircompany.com'),
                        TextInput::make('code')->label('Pairing code')->required(),
                        TextInput::make('our_url')
                            ->label('This instance\'s URL')
                            ->required()
                            ->default(config('app.url'))
                            ->helperText('What they should send invoices back to.'),
                        Select::make('partner_id')
                            ->label('Linked vendor')
                            ->options(fn () => Partner::query()
                                ->where('company_id', Auth::user()?->default_company_id)
                                ->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function (array $data) {
                        try {
                            $response = Http::acceptJson()
                                ->post(rtrim($data['endpoint_url'], '/').'/api/v1/peer/pair', [
                                    'code'         => $data['code'],
                                    'endpoint_url' => $data['our_url'],
                                    'name'         => config('app.name'),
                                ]);

                            if (! $response->successful()) {
                                Notification::make()->danger()
                                    ->title('Pairing failed')
                                    ->body($response->json('message') ?? 'The other instance rejected the code.')
                                    ->send();

                                return;
                            }

                            // The token mapping is deliberately NOT done here --
                            // it lives in acceptPairingResponse(), once. This
                            // resource had it backwards and every send 401'd.
                            app(PeerPairingService::class)->acceptPairingResponse(
                                actor: Auth::user(),
                                name: $data['name'],
                                endpointUrl: $data['endpoint_url'],
                                response: $response->json(),
                                partnerId: $data['partner_id'] ?? null,
                            );

                            Notification::make()->success()->title('Paired successfully')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Pairing failed')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('linkVendor')
                    ->label('Link vendor')
                    ->icon('heroicon-o-user')
                    ->authorize(AccountingPermissions::ManagePeers)
                    ->schema([
                        Select::make('partner_id')
                            ->label('Vendor')
                            ->options(fn () => Partner::query()
                                ->where('company_id', Auth::user()?->default_company_id)
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Peer $record, array $data) {
                        $record->forceFill(['partner_id' => $data['partner_id']])->save();

                        Notification::make()->success()->title('Vendor linked')->send();
                    }),

                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The peer will immediately stop being able to send you anything. Invoices already received are kept as audit evidence.')
                    ->authorize(AccountingPermissions::ManagePeers)
                    ->visible(fn (Peer $record) => $record->status !== PeerStatus::Revoked)
                    ->action(function (Peer $record) {
                        app(PeerPairingService::class)->revoke(Auth::user(), $record);

                        Notification::make()->success()->title('Peer revoked')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeers::route('/'),
        ];
    }
}
