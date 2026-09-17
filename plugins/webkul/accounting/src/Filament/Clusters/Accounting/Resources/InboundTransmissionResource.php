<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Enums\InboundTransmissionStatus;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\InboundTransmissionResource\Pages\ListInboundTransmissions;
use Webkul\Accounting\Models\InboundTransmission;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Peers\DocumentExchangeService;
use Webkul\Accounting\Services\Peers\DocumentPayloadBuilder;
use Webkul\Accounting\Support\AccountingPermissions;

/**
 * The review queue for invoices sent by peers.
 *
 * Nothing here has touched the ledger yet. Accepting is what creates a draft
 * bill, which is why this is gated on its own permission rather than being
 * folded into "can see accounting".
 */
class InboundTransmissionResource extends Resource
{
    protected static ?string $model = InboundTransmission::class;

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return 'Inbound Invoices';
    }

    /** Surfaces how many are waiting, so the queue is not silently ignored. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->awaitingReview()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ReviewInboundTransmissions) ?? false;
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
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('peer.name')->label('From peer')->searchable(),
                TextColumn::make('payload_type')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === DocumentPayloadBuilder::FORMAT ? 'File' : 'Invoice')
                    ->color(fn (string $state): string => $state === DocumentPayloadBuilder::FORMAT ? 'info' : 'primary'),
                TextColumn::make('payload.invoice.number')
                    ->label('Reference')
                    // Falls back to the filename, so a file row is not blank.
                    ->getStateUsing(fn (InboundTransmission $record) => $record->payload['invoice']['number']
                        ?? $record->payload['document']['filename']
                        ?? null)
                    ->placeholder('—'),
                TextColumn::make('payload.invoice.totals.total')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, InboundTransmission $record) => $state === null
                        ? '—'
                        : number_format((float) $state, 2).' '.($record->payload['invoice']['currency'] ?? '')),
                TextColumn::make('status')->badge(),
                TextColumn::make('createdMove.name')->label('Draft bill')->placeholder('—'),
                TextColumn::make('created_at')->label('Received')->dateTime(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (InboundTransmission $record) => 'Invoice from '.$record->peer->name)
                    ->modalContent(fn (InboundTransmission $record) => view(
                        'accounting::peers.inbound-detail',
                        ['record' => $record],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('downloadFile')
                    ->label('Download file')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (InboundTransmission $record): bool => $record->document_id !== null)
                    ->authorize(AccountingPermissions::DownloadDocuments)
                    ->action(function (InboundTransmission $record) {
                        try {
                            $result = app(DocumentService::class)->retrieveContents(
                                Auth::user(),
                                $record->document_id,
                                request()->ip(),
                            );

                            return response()->streamDownload(
                                fn () => print ($result['contents']),
                                $result['version']->original_filename,
                            );
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not download')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('accept')
                    ->label('Accept')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (InboundTransmission $record): string => $record->payload_type === DocumentPayloadBuilder::FORMAT
                        ? 'This marks the file as accepted. The file itself is already stored and checksum-verified; nothing is posted to the ledger.'
                        : 'This creates a DRAFT vendor bill. Nothing is posted, and the amounts are recomputed locally rather than taken on trust.')
                    ->authorize(AccountingPermissions::ReviewInboundTransmissions)
                    ->visible(fn (InboundTransmission $record) => $record->status === InboundTransmissionStatus::Received)
                    ->action(function (InboundTransmission $record) {
                        try {
                            $bill = app(DocumentExchangeService::class)->accept(Auth::user(), $record, request()->ip());

                            Notification::make()->success()
                                ->title($bill ? 'Draft bill created' : 'File accepted')
                                ->body($bill
                                    ? "Bill #{$bill->id} is in Vendors > Bills as a draft."
                                    : 'The file is in Accounting > Documents.')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not accept')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->authorize(AccountingPermissions::ReviewInboundTransmissions)
                    ->visible(fn (InboundTransmission $record) => $record->status === InboundTransmissionStatus::Received)
                    ->schema([
                        Textarea::make('reason')->label('Why are you rejecting this?')->required()->maxLength(1000),
                    ])
                    ->action(function (InboundTransmission $record, array $data) {
                        try {
                            app(DocumentExchangeService::class)->reject(Auth::user(), $record, $data['reason'], request()->ip());

                            Notification::make()->success()->title('Rejected')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not reject')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInboundTransmissions::route('/'),
        ];
    }
}
