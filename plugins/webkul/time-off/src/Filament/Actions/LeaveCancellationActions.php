<?php

namespace Webkul\TimeOff\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Security\Models\User;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\TimeOff\Services\LeaveCancellationService;

/**
 * Withdraw / request cancellation / cancel / keep approved, for the Time
 * Off tables and view pages. Visibility only mirrors the rules;
 * LeaveCancellationService re-checks every rule on the locked row.
 */
class LeaveCancellationActions
{
    /** @return array<int, Action> */
    public static function make(): array
    {
        return [
            Action::make('withdraw_leave')
                ->label('Withdraw')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('The request is taken back and nobody needs to approve it any more. This cannot be undone; submit a new request if needed.')
                ->schema([
                    Textarea::make('reason')->label('Reason (optional)')->maxLength(2000),
                ])
                ->visible(fn (Leave $record): bool => static::allowed(fn (LeaveCancellationService $service, User $user): bool => $service->canWithdraw($record, $user)))
                ->action(fn (Leave $record, array $data, $livewire) => static::run(
                    fn (LeaveCancellationService $service, User $user) => $service->withdraw($record, $user, $data['reason'] ?? null),
                    'Leave request withdrawn',
                    $livewire,
                )),

            Action::make('request_leave_cancellation')
                ->label('Request cancellation')
                ->icon('heroicon-o-no-symbol')
                ->color('warning')
                ->modalDescription('HR is asked to cancel this approved leave. It stays approved until HR cancels it.')
                ->schema([
                    Textarea::make('reason')->label('Reason')->required()->maxLength(2000),
                ])
                ->visible(fn (Leave $record): bool => static::allowed(fn (LeaveCancellationService $service, User $user): bool => $service->canRequestCancellation($record, $user)))
                ->action(fn (Leave $record, array $data, $livewire) => static::run(
                    fn (LeaveCancellationService $service, User $user) => $service->requestCancellation($record, $user, (string) $data['reason']),
                    'Cancellation requested from HR',
                    $livewire,
                )),

            Action::make('cancel_leave')
                ->label(fn (Leave $record): string => $record->cancellation_requested_at ? 'Approve cancellation' : 'Cancel leave')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->modalDescription('The approved leave is cancelled: the days go back to the balance and attendance no longer counts it. The employee and line manager are notified.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(2000)
                        ->default(fn (?Leave $record): ?string => $record?->cancellation_request_reason),
                ])
                ->visible(fn (Leave $record): bool => static::allowed(fn (LeaveCancellationService $service, User $user): bool => $service->canCancel($record, $user)))
                ->action(fn (Leave $record, array $data, $livewire) => static::run(
                    fn (LeaveCancellationService $service, User $user) => $service->cancel($record, $user, (string) $data['reason']),
                    'Leave cancelled',
                    $livewire,
                )),

            Action::make('decline_leave_cancellation')
                ->label('Keep approved')
                ->icon('heroicon-o-check')
                ->color('gray')
                ->schema([
                    Textarea::make('reason')->label('Reason')->required()->maxLength(2000),
                ])
                ->visible(fn (Leave $record): bool => static::allowed(fn (LeaveCancellationService $service, User $user): bool => $service->canDeclineCancellation($record, $user)))
                ->action(fn (Leave $record, array $data, $livewire) => static::run(
                    fn (LeaveCancellationService $service, User $user) => $service->declineCancellation($record, $user, (string) $data['reason']),
                    'Cancellation declined; the leave stays approved',
                    $livewire,
                )),
        ];
    }

    /**
     * Status and cancellation details for the Time Off infolists.
     *
     * @return array<int, TextEntry>
     */
    public static function infolistEntries(): array
    {
        return [
            TextEntry::make('state')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (State $state, ?Leave $record): string => LeaveApprovalService::isReturned($record) ? 'Returned for changes' : (string) $state->getLabel())
                ->color(fn (State $state, ?Leave $record): string|array|null => LeaveApprovalService::isReturned($record) ? 'info' : $state->getColor())
                ->icon('heroicon-o-flag'),
            TextEntry::make('returned_for_changes')
                ->label('Returned for changes')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (?Leave $record): bool => LeaveApprovalService::isReturned($record))
                ->getStateUsing(function (?Leave $record): ?string {
                    $return = $record?->approvalRequest?->decisions()->with('actor')->where('decision', 'returned')->latest('id')->first();

                    return $return ? trim(($return->actor?->name ?? 'An approver').' on '.$return->decided_at?->format('d M Y H:i').': '.$return->reason).' Edit the request and resubmit it.' : null;
                }),
            TextEntry::make('cancellation_request_reason')
                ->label('Cancellation requested')
                ->icon('heroicon-o-no-symbol')
                ->visible(fn (?Leave $record): bool => $record?->cancellation_requested_at !== null)
                ->formatStateUsing(fn (?Leave $record): string => trim(($record?->cancellationRequestedBy?->name ?? 'The employee').' on '.$record?->cancellation_requested_at?->format('d M Y H:i').': '.$record?->cancellation_request_reason)),
            TextEntry::make('cancellation_reason')
                ->label('Cancelled')
                ->icon('heroicon-o-x-mark')
                ->visible(fn (?Leave $record): bool => $record?->cancelled_at !== null)
                ->formatStateUsing(fn (?Leave $record): string => trim(($record?->cancelledBy?->name ?? '').' on '.$record?->cancelled_at?->format('d M Y H:i').': '.$record?->cancellation_reason)),
        ];
    }

    private static function allowed(callable $check): bool
    {
        $user = Auth::user();

        return $user instanceof User && (bool) $check(app(LeaveCancellationService::class), $user);
    }

    private static function run(callable $operation, string $successTitle, mixed $livewire): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        try {
            $operation(app(LeaveCancellationService::class), $user);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title('Not possible')->body($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($successTitle)->send();

        if ($livewire instanceof ViewRecord) {
            $livewire->getRecord()->refresh();
        }
    }
}
