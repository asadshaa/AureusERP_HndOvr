<?php

namespace Webkul\Account\Filament\Resources\PaymentResource\Actions;

use Filament\Actions\Action;
use Livewire\Component;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move as AccountMove;
use Webkul\Account\Models\Payment;

class CancelAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'customers.payment.cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('accounts::filament/resources/payment/actions/cancel-action.title'))
            ->color('gray')
            ->requiresConfirmation()
            ->action(function (Payment $record, Component $livewire): void {
                // Deleting the payment's move used to leave the reconciliation
                // link (accounts_partial_reconciles) dangling/inconsistent --
                // confirmed live: this corrupted the matched invoice's own
                // payment-term line (still "reconciled" against nothing),
                // permanently breaking its payment_state no matter how many
                // times computePaymentState() was re-run afterward. Properly
                // unreconciling every partial-reconcile row FIRST (the same
                // path AccountManager::unReconcile() already exists for) is
                // what actually keeps the invoice's own lines consistent.
                $record->move?->lines->each(function ($line): void {
                    $line->matchedDebits->each(fn ($partial) => AccountFacade::unReconcile($partial));
                    $line->matchedCredits->each(fn ($partial) => AccountFacade::unReconcile($partial));
                });

                $affectedInvoiceIds = $record->invoices()->pluck('accounts_account_moves.id');

                $record->state = PaymentStatus::CANCELED;
                $record->save();

                $record->move?->delete();

                // Belt-and-braces: unReconcile() above already recomputes
                // via computeAccountMove(), but payment_state itself lives
                // outside that call chain (see AccountManager::createPayments()),
                // so it still needs its own explicit recompute here.
                AccountMove::whereIn('id', $affectedInvoiceIds)->get()->each(function (AccountMove $invoice): void {
                    $invoice->refresh();
                    $invoice->computePaymentState();
                    $invoice->save();
                });

                $livewire->refreshFormData(['state']);
            })
            ->visible(fn (Payment $record) => $record->state === PaymentStatus::DRAFT);
    }
}
