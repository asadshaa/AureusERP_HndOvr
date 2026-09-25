<?php

namespace Webkul\Account\Filament\Resources\InvoiceResource\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Component;
use Throwable;
use Webkul\Account\Enums\AutoPost;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move;

class ConfirmAction extends Action
{
    protected bool|Closure $hasDatabaseTransactions = true;

    public static function getDefaultName(): ?string
    {
        return 'customers.invoice.confirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('accounts::filament/resources/invoice/actions/confirm-action.title'))
            ->color('primary')
            // 'accounting_post_journal' is AccountingPermissions::PostJournal in the
            // accounting plugin -- used as a literal string here rather than importing
            // it, since accounting depends on accounts (not the other way around) and
            // importing it would invert that. Client decision: only whoever can post a
            // journal (Admin, Accounting Manager) may also post an invoice/bill --
            // not HR, not a plain employee, even if they can edit one.
            ->authorize('accounting_post_journal')
            ->action(function (Move $record, Component $livewire): void {
                $record->checked = $record->journal->auto_check_on_post;

                try {
                    $record = AccountFacade::confirmMove($record);

                    $livewire->refreshFormData(['state', 'parent_state']);

                    $livewire->dispatch('refreshInvoiceSummary');
                } catch (Throwable $e) {
                    Notification::make()
                        ->warning()
                        ->title(__('accounts::filament/resources/invoice/actions/confirm-action.notification.error.title'))
                        ->body($e->getMessage())
                        ->send();

                    $this->halt(shouldRollBackDatabaseTransaction: true);
                }
            })
            ->hidden(function (Move $record) {
                return
                    $record->state !== MoveState::DRAFT
                    || (
                        $record->auto_post !== AutoPost::NO
                        && $record->date > now()
                    );
            });
    }
}
