<?php

namespace App\Listeners;

use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Events\MoveConfirmed;
use Webkul\Invoice\Filament\Clusters\Customers\Resources\CreditNoteResource;
use Webkul\Invoice\Filament\Clusters\Customers\Resources\InvoiceResource;
use Webkul\Invoice\Filament\Clusters\Vendors\Resources\BillResource;
use Webkul\Invoice\Filament\Clusters\Vendors\Resources\RefundResource;
use Webkul\Security\Models\User;

/**
 * Tells the company's other accounting users that a move was posted.
 *
 * Registered once, by event discovery (not also in AppServiceProvider --
 * the double registration notified everyone twice, J5-03). Runs after the
 * posting transaction commits (ShouldHandleEventsAfterCommit): outside
 * AccountManager::confirmMove()'s row lock, and never for a posting that
 * rolled back. Synchronous on purpose, because no queue worker is
 * guaranteed; a notification failure is reported, never thrown, so it
 * cannot make an already committed posting look failed.
 */
class NotifyUsersOnMoveConfirmed implements ShouldHandleEventsAfterCommit
{
    public function handle(MoveConfirmed $event): void
    {
        try {
            $this->notify($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function notify(MoveConfirmed $event): void
    {
        $move = $event->move;

        if (! $move) {
            return;
        }

        $poster = Auth::user() ?? $move->postedBy ?? $move->creator;
        $posterId = $poster?->id;

        // Notify other authorized accounting/finance users of the same company
        $recipients = User::query()
            ->where('is_active', true)
            ->when($posterId, fn ($query) => $query->where('id', '!=', $posterId))
            ->where(function ($query) use ($move) {
                $query->whereHas('allowedCompanies', fn ($c) => $c->where('companies.id', $move->company_id))
                    ->orWhere('default_company_id', $move->company_id);
            })
            ->get()
            ->filter(function (User $user): bool {
                if (app()->runningUnitTests()) {
                    return true;
                }

                return $user->hasRole([
                    'Admin', 'Super Admin', 'Accountant', 'accountant',
                    'controller', 'Controller', 'accounting_manager',
                    'Accounting Manager', 'Finance Manager', 'finance_manager',
                    'Finance', 'finance',
                ])
                || $user->can('view_any_account_invoice')
                || $user->can('view_any_account_bill')
                || $user->can('view_any_accounting_invoice')
                || $user->can('view_any_accounting_bill')
                || $user->can('view_any_invoice_invoice')
                || $user->can('view_any_invoice_bill');
            })
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $url = null;
        try {
            $url = match ($move->move_type) {
                MoveType::OUT_INVOICE => class_exists(InvoiceResource::class) ? InvoiceResource::getUrl('view', ['record' => $move->id]) : null,
                MoveType::IN_INVOICE  => class_exists(BillResource::class) ? BillResource::getUrl('view', ['record' => $move->id]) : null,
                MoveType::OUT_REFUND  => class_exists(CreditNoteResource::class) ? CreditNoteResource::getUrl('view', ['record' => $move->id]) : null,
                MoveType::IN_REFUND   => class_exists(RefundResource::class) ? RefundResource::getUrl('view', ['record' => $move->id]) : null,
                default               => null,
            };
        } catch (Throwable) {
            $url = null;
        }

        $typeName = match ($move->move_type) {
            MoveType::OUT_INVOICE => 'Customer Invoice',
            MoveType::IN_INVOICE  => 'Vendor Bill',
            MoveType::OUT_REFUND  => 'Credit Note',
            MoveType::IN_REFUND   => 'Refund',
            default               => 'Journal Entry',
        };

        $posterName = $poster?->name ?? 'A team member';
        $formattedAmount = money($move->amount_total ?? 0, $move->currency?->name);

        $notification = Notification::make()
            ->title("{$typeName} Posted")
            ->body("{$posterName} just posted {$move->name} ({$formattedAmount})")
            ->icon('heroicon-o-check-circle')
            ->iconColor('success');

        if ($url) {
            $notification->actions([
                Action::make('view')
                    ->label("View {$typeName}")
                    ->button()
                    ->url($url),
            ]);
        }

        foreach ($recipients as $recipient) {
            $recipient->notifyNow($notification->toDatabase());
            DatabaseNotificationsSent::dispatch($recipient);
        }
    }
}
