<?php

namespace Webkul\Account\Filament\Resources\PaymentResource\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Throwable;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Payment;

class ResetToDraftAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'customers.payment.reset-to-draft';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('accounts::filament/resources/payment/actions/reset-to-draft.title'))
            ->color('gray')
            ->action(function (Payment $record, Component $livewire): void {
                // The payment's journal entry is reset first, in the same
                // transaction: resetToDraftMove() can refuse (locked period,
                // source-owned entry), and the payment must then stay as it
                // was instead of reading Draft against a still-posted entry.
                try {
                    DB::transaction(function () use ($record): void {
                        if ($record->move) {
                            AccountFacade::resetToDraftMove($record->move);

                            $record->unsetRelation('move');
                        }

                        $record->state = PaymentStatus::DRAFT;
                        $record->save();
                    });
                } catch (Throwable $e) {
                    $record->refresh();

                    Notification::make()
                        ->warning()
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                $livewire->refreshFormData(['state']);
            })
            ->hidden(fn (Payment $record) => $record->state == PaymentStatus::DRAFT);
    }
}
