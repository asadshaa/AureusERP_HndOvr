<?php

namespace Webkul\Account\Filament\Resources\InvoiceResource\Actions;

use Filament\Actions\Action;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move;

class SetAsCheckedAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'customers.invoice.set-as-checked';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Set as checked'))
            ->label(__('accounts::filament/resources/invoice/actions/set-as-checked-action.title'))
            ->color('gray')
            // See ConfirmAction's comment: same PostJournal-tier gate on every
            // invoice/bill lifecycle action, not just posting itself.
            ->authorize('accounting_post_journal')
            ->action(function (Move $record, $livewire): void {
                $record = AccountFacade::setAsCheckedMove($record);

                $livewire->refreshFormData(['checked']);
            })
            ->hidden(function (Move $record) {
                return
                    $record->checked
                    || $record->state == MoveState::DRAFT;
            });
    }
}
