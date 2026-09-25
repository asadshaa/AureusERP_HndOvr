<?php

namespace Webkul\Account\Filament\Resources\InvoiceResource\Actions;

use Closure;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use Throwable;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Models\PaymentRegister;
use Webkul\Accounting\Models\Journal;
use Webkul\Support\Models\Currency;

class PayAction extends Action
{
    protected bool|Closure $hasDatabaseTransactions = true;

    public static function getDefaultName(): ?string
    {
        return 'customers.invoice.pay';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('accounts::filament/resources/invoice/actions/pay-action.title'))
            ->color('success')
            // See ConfirmAction's comment: same PostJournal-tier gate on every
            // invoice/bill lifecycle action, not just posting itself.
            ->authorize('accounting_post_journal')
            ->schema(function (Schema $schema) {
                $paymentRegister = new PaymentRegister;

                try {
                    $paymentRegister->lines = $this->getRecord()->lines;
                    $paymentRegister->company = $this->getRecord()->company;
                    $paymentRegister->currency = $this->getRecord()->currency;
                    $paymentRegister->currency_id = $this->getRecord()->currency_id;
                    $paymentRegister->payment_type = $this->getRecord()->isInbound(true)
                        ? PaymentType::RECEIVE
                        : PaymentType::SEND;
                    $paymentRegister->computeBatches();
                    $paymentRegister->computeAvailableJournalIds();
                    $paymentRegister->journal_id = $paymentRegister->available_journal_ids[0] ?? null;
                    $paymentRegister->journal = Journal::find($paymentRegister->journal_id);

                    $paymentRegister->computePaymentMethodLineId();

                    $amountsToPay = $paymentRegister->getTotalAmountsToPay($paymentRegister->batches);
                    $paymentRegister->amount = $amountsToPay['amount_by_default'];
                    $paymentRegister->computeInstallmentsMode();
                } catch (Exception $e) {
                    Notification::make()
                        ->title(__('accounts::filament/resources/invoice/actions/pay-action.notifications.payment-failed.title'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }

                return $schema->components([
                    Group::make()
                        ->schema([
                            Select::make('journal_id')
                                ->relationship(
                                    'journal',
                                    'name',
                                    modifyQueryUsing: fn (Builder $query) => $query->whereIn('id', $paymentRegister->available_journal_ids)
                                )
                                ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.journal'))
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->default(fn () => $paymentRegister->available_journal_ids[0] ?? null)
                                ->afterStateUpdated(function (Set $set, Get $get) use ($paymentRegister) {
                                    $paymentRegister->journal_id = $get('journal_id');
                                    $paymentRegister->journal = Journal::find($get('journal_id'));
                                    $paymentRegister->computePaymentMethodLineId();

                                    $set('payment_method_line_id', $paymentRegister->payment_method_line_id);
                                    $set('partner_bank_id', null);
                                }),

                            Select::make('payment_method_line_id')
                                ->label(__('accounts::filament/resources/invoice.form.tabs.other-information.fieldset.accounting.fields.payment-method'))
                                ->required()
                                ->searchable()
                                ->preload()
                                ->live()
                                ->default($paymentRegister->payment_method_line_id)
                                ->relationship(
                                    name: 'paymentMethodLine',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: function (Builder $query, Get $get) {
                                        $journal = Journal::find($get('journal_id'));

                                        if (! $journal) {
                                            return $query->whereRaw('1 = 0');
                                        }

                                        $paymentMethodLineIds = $journal->getAvailablePaymentMethodLines(
                                            $this->getRecord()->isInbound(true)
                                                ? PaymentType::RECEIVE
                                                : PaymentType::SEND
                                        )->pluck('id');

                                        $query->whereIn('id', $paymentMethodLineIds);
                                    }
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name)
                                ->afterStateUpdated(function (Set $set, Get $get) use ($paymentRegister) {
                                    $paymentRegister->payment_method_line_id = $get('payment_method_line_id');
                                    $paymentRegister->paymentMethodLine = PaymentMethodLine::find($get('payment_method_line_id'));
                                    $paymentRegister->journal = Journal::find($get('journal_id'));
                                    $paymentRegister->computeShowRequirePartnerBank();
                                }),
                            Select::make('partner_bank_id')
                                ->relationship(
                                    'partnerBank',
                                    'account_number',
                                    modifyQueryUsing: function (Builder $query, Get $get) {
                                        $companyId = $get('company_id') ?? filament()->auth()->user()->default_company_id;

                                        $bankAccountIds = \Webkul\Account\Models\Journal::where('type', JournalType::BANK)
                                            ->where('company_id', $companyId)
                                            ->pluck('bank_account_id')
                                            ->filter();

                                        $query->whereIn('id', $bankAccountIds);
                                    }
                                )
                                ->getOptionLabelFromRecordUsing(function ($record): string {
                                    return $record->account_number.' - '.$record->bank->name.($record->trashed() ? ' (Deleted)' : '');
                                })
                                ->disableOptionWhen(function ($label) {
                                    return str_contains($label, ' (Deleted)');
                                })
                                ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.partner-bank-account'))
                                ->searchable()
                                ->preload()
                                ->required(function (Get $get) use ($paymentRegister) {
                                    $journal = Journal::find($get('journal_id'));

                                    if (! $journal) {
                                        return false;
                                    }

                                    $paymentRegister->journal = $journal;
                                    $paymentRegister->payment_method_line_id = $get('payment_method_line_id');
                                    $paymentRegister->paymentMethodLine = PaymentMethodLine::find($get('payment_method_line_id'));

                                    if (! $paymentRegister->paymentMethodLine) {
                                        return false;
                                    }

                                    $paymentRegister->computeShowRequirePartnerBank();

                                    return $paymentRegister->require_partner_bank_account && $paymentRegister->show_partner_bank_account;
                                })
                                ->visible(function (Get $get) use ($paymentRegister) {
                                    $journal = Journal::find($get('journal_id'));

                                    if (! $journal) {
                                        return false;
                                    }

                                    $paymentRegister->journal = $journal;
                                    $paymentRegister->payment_method_line_id = $get('payment_method_line_id');
                                    $paymentRegister->paymentMethodLine = PaymentMethodLine::find($get('payment_method_line_id'));

                                    if (! $paymentRegister->paymentMethodLine) {
                                        return false;
                                    }

                                    $paymentRegister->computeShowRequirePartnerBank();

                                    return $paymentRegister->show_partner_bank_account;
                                })
                                ->disabled(function (Get $get) use ($paymentRegister) {
                                    $journal = Journal::find($get('journal_id'));

                                    if (! $journal) {
                                        return true;
                                    }

                                    $paymentRegister->journal = $journal;
                                    $paymentRegister->payment_method_line_id = $get('payment_method_line_id');
                                    $paymentRegister->paymentMethodLine = PaymentMethodLine::find($get('payment_method_line_id'));

                                    if (! $paymentRegister->paymentMethodLine) {
                                        return true;
                                    }

                                    $paymentRegister->computeShowRequirePartnerBank();

                                    return ! $paymentRegister->require_partner_bank_account;
                                }),
                        ]),

                    Group::make()
                        ->schema([
                            Group::make()
                                ->schema([
                                    Hidden::make('installments_mode')
                                        ->default($paymentRegister->installments_mode),
                                    TextInput::make('amount')
                                        ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.amount'))
                                        ->prefix(fn ($record) => $record->currency->symbol ?? '')
                                        ->default($paymentRegister->amount)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, $state) use ($paymentRegister) {
                                            $paymentRegister->amount = $state;
                                            $paymentRegister->computeInstallmentsMode();
                                            $set('installments_mode', $paymentRegister->installments_mode);
                                        })
                                        ->helperText(function (Get $get) use ($paymentRegister) {
                                            $paymentRegister->amount = $get('amount') ?? $paymentRegister->amount;
                                            $paymentRegister->installments_mode = $get('installments_mode') ?? $paymentRegister->installments_mode;

                                            $switchValues = $paymentRegister->computeInstallmentsSwitchValues();

                                            if (! $switchValues['installments_switch_html']) {
                                                return null;
                                            }

                                            return new HtmlString($switchValues['installments_switch_html']);
                                        })
                                        ->hintAction(
                                            Action::make('toggleInstallments')
                                                ->label(function (Get $get) use ($paymentRegister) {
                                                    $installmentsMode = $get('installments_mode') ?? $paymentRegister->installments_mode;

                                                    return $installmentsMode === 'full' ? 'installments' : 'full amount';
                                                })
                                                ->link()
                                                ->action(function (Set $set, Get $get) use ($paymentRegister) {
                                                    $switchValues = $paymentRegister->computeInstallmentsSwitchValues();

                                                    if ($switchValues['installments_switch_amount'] > 0) {
                                                        $paymentRegister->amount = $switchValues['installments_switch_amount'];
                                                        $paymentRegister->computeInstallmentsMode();

                                                        $set('amount', $paymentRegister->amount);
                                                        $set('installments_mode', $paymentRegister->installments_mode);
                                                    }
                                                })
                                        ),
                                    Select::make('currency_id')
                                        ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.currency'))
                                        ->relationship(
                                            name: 'currency',
                                            titleAttribute: 'name',
                                            modifyQueryUsing: fn (Builder $query) => $query->active(),
                                        )
                                        ->default(function ($record, Get $get) {
                                            $journal = Journal::find($get('journal_id'));

                                            if (! $journal) {
                                                return $record->currency_id;
                                            }

                                            return $journal->currency_id ?? $record->currency_id;
                                        })
                                        ->required()
                                        ->searchable()
                                        ->preload()
                                        ->required(),
                                ])
                                ->columns(2),
                            DatePicker::make('payment_date')
                                ->native(false)
                                ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.payment-date'))
                                ->default(now())
                                ->required(),
                            TextInput::make('communication')
                                ->label(__('accounts::filament/resources/invoice/actions/pay-action.form.fields.communication'))
                                ->default(function ($record) {
                                    return $record->name;
                                })
                                ->required(),
                        ]),
                ])
                    ->columns(2);
            })
            ->action(function (Move $record, $data, Component $livewire): void {
                try {
                    // Hard server-side guard, independent of the button's
                    // own hidden() check or the browser having a stale page
                    // open -- re-reads the record's TRUE current state right
                    // before processing, so two tabs / a slow page / a
                    // second click a while later can never both succeed.
                    // Confirmed live: this exact gap let the same invoice
                    // get paid twice, creating a real duplicate journal
                    // entry that then had to be manually reversed.
                    $record->refresh();

                    if (! in_array($record->payment_state, [
                        PaymentState::NOT_PAID,
                        PaymentState::PARTIAL,
                        PaymentState::IN_PAYMENT,
                    ])) {
                        Notification::make()
                            ->title(__('Already Paid'))
                            ->body(__(':name is already :state -- refresh the page. No new payment was registered.', [
                                'name'  => $record->name,
                                'state' => $record->payment_state->getLabel(),
                            ]))
                            ->warning()
                            ->send();

                        $this->halt(shouldRollBackDatabaseTransaction: true);
                    }

                    $paymentCurrency = Currency::find($data['currency_id'] ?? $record->currency_id);

                    if ($record->currency && $paymentCurrency) {
                        $record->currency->getConversionRate(
                            $record->currency,
                            $paymentCurrency,
                            $record->company,
                            $data['payment_date'] ?? now(),
                            strict: true,
                        );
                    }

                    $lineIds = $record->paymentTermLines
                        ->filter(fn ($line) => ! $line->reconciled)
                        ->pluck('id')
                        ->toArray();

                    $paymentRegister = PaymentRegister::create($data);

                    $paymentRegister->lines()->sync($lineIds);

                    $paymentRegister->refresh();

                    $paymentRegister->computeFromLines();

                    $paymentRegister->save();

                    AccountFacade::createPayments($paymentRegister);

                    $record->refresh();

                    // Sending a receipt and exporting to Drive are deliberately NOT
                    // part of this action -- registering a payment is a distinct
                    // step from notifying the customer/vendor. Use "Print & Send"
                    // (PrintAndSendAction) whenever you actually want to send
                    // something, including after this payment is registered.
                    if (method_exists($livewire, 'refreshFormData')) {
                        $livewire->refreshFormData(['state', 'payment_state', 'amount_residual']);
                    }

                    $livewire->dispatch('refreshInvoiceSummary');

                    // refreshFormData() only updates the form's own fields --
                    // it does NOT re-evaluate this action's own hidden()
                    // condition (payment_state not in [NOT_PAID, PARTIAL,
                    // IN_PAYMENT]), so the header bar kept showing "Pay" as
                    // clickable even once a record was already fully paid.
                    // Confirmed live: this let the same bill/invoice be paid
                    // twice, creating two real duplicate payment moves. A
                    // full reload forces every header action to re-evaluate
                    // against the now-current payment_state.
                    if ($record->payment_state === PaymentState::PAID && method_exists($livewire, 'js')) {
                        $livewire->js('window.location.reload()');
                    }
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->body($e->getMessage())
                        ->send();

                    $this->halt(shouldRollBackDatabaseTransaction: true);
                }
            })
            ->hidden(function (Move $record) {
                return $record->state != MoveState::POSTED
                    || ! in_array($record->payment_state, [
                        PaymentState::NOT_PAID,
                        PaymentState::PARTIAL,
                        PaymentState::IN_PAYMENT,
                    ]);
            });
    }
}
