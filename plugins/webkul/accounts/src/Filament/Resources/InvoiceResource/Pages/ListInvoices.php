<?php

namespace Webkul\Account\Filament\Resources\InvoiceResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Filament\Resources\InvoiceResource;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Support\Models\Company;
use Webkul\TableViews\Filament\Components\PresetView;
use Webkul\TableViews\Filament\Concerns\HasTableViews;

class ListInvoices extends ListRecords
{
    use HasTableViews;

    protected static string $resource = InvoiceResource::class;

    public function getTablePollingInterval(): ?string
    {
        return '15s';
    }

    /**
     * Filament's default hydrates every filtered record and runs the
     * selectability callback in PHP whenever one is set; this counts in SQL
     * instead, through the resource's equivalent scope.
     */
    public function getAllSelectableTableRecordsCount(): int
    {
        if (! $this->selectsAllTableRecordsInSql()) {
            return parent::getAllSelectableTableRecordsCount();
        }

        return $this->getSelectableTableRecordsQuery()->count();
    }

    /**
     * @return array<string>
     */
    public function getAllSelectableTableRecordKeys(): array
    {
        if (! $this->selectsAllTableRecordsInSql()) {
            return parent::getAllSelectableTableRecordKeys();
        }

        return $this->pluckSelectableTableRecordKeys($this->getSelectableTableRecordsQuery());
    }

    /**
     * @return array<string>
     */
    public function getGroupedSelectableTableRecordKeys(?string $group): array
    {
        if (! $this->selectsAllTableRecordsInSql()) {
            return parent::getGroupedSelectableTableRecordKeys($group);
        }

        $query = $this->getSelectableTableRecordsQuery();

        $this->getTableGrouping()->scopeQueryByKey($query, $group);

        return $this->pluckSelectableTableRecordKeys($query);
    }

    protected function selectsAllTableRecordsInSql(): bool
    {
        $table = $this->getTable();

        return $table->hasQuery()
            && $table->checksIfRecordIsSelectable()
            && (! $table->selectsCurrentPageOnly())
            && method_exists(static::getResource(), 'scopeSelectableTableRecords');
    }

    protected function getSelectableTableRecordsQuery(): Builder
    {
        return static::getResource()::scopeSelectableTableRecords($this->getFilteredTableQuery());
    }

    /**
     * @return array<string>
     */
    protected function pluckSelectableTableRecordKeys(Builder $query): array
    {
        return $query->toBase()
            ->pluck($query->getModel()->getQualifiedKeyName())
            ->map(fn (mixed $key): string => (string) $key)
            ->all();
    }

    public function getPresetTableViews(): array
    {
        return [
            'ready_for_review' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.ready-for-review'))
                ->favorite()
                ->icon('heroicon-s-clipboard-document-check')
                ->badge(function () {
                    $query = static::getResource()::getEloquentQuery();

                    return $query
                        ->where('state', MoveState::DRAFT)
                        ->where('checked', true)
                        ->count() ?: null;
                })
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('state', MoveState::DRAFT)->where('checked', true)),
            'my_drafts' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.my-drafts'))
                ->favorite()
                ->icon('heroicon-s-user')
                ->badge(function () {
                    $query = static::getResource()::getEloquentQuery();

                    return $query
                        ->where('state', MoveState::DRAFT)
                        ->where('creator_id', Auth::id())
                        ->count() ?: null;
                })
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('state', MoveState::DRAFT)->where('creator_id', Auth::id())),
            'draft' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.draft'))
                ->favorite()
                ->icon('heroicon-s-stop')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('state', MoveState::DRAFT)),
            'posted' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.posted'))
                ->favorite()
                ->icon('heroicon-s-play')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('state', MoveState::POSTED)),
            'cancelled' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.cancelled'))
                ->favorite()
                ->icon('heroicon-s-x-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('state', MoveState::CANCEL)),
            'not_secured' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.not-secured'))
                ->favorite()
                ->icon('heroicon-s-shield-exclamation')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('inalterable_hash')),
            'to_check' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.to-check'))
                ->icon('heroicon-s-check-badge')
                ->modifyQueryUsing(function (Builder $query) {
                    $query->whereNot('state', MoveState::DRAFT)
                        ->where('checked', false);
                }),
            'to_pay' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.to-pay'))
                ->icon('heroicon-s-banknotes')
                ->modifyQueryUsing(function (Builder $query) {
                    $query->where('state', MoveState::POSTED)
                        ->whereIn('payment_state', [
                            PaymentState::NOT_PAID,
                            PaymentState::PARTIAL,
                        ]);
                }),
            'unpaid' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.unpaid'))
                ->icon('heroicon-s-banknotes')
                ->modifyQueryUsing(function (Builder $query) {
                    $query->where('state', MoveState::POSTED)
                        ->where('amount_residual', '>', 0)
                        ->whereNotIn('payment_state', [
                            PaymentState::PAID,
                            PaymentState::IN_PAYMENT,
                        ]);
                }),
            'in_payment' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.in-payment'))
                ->icon('heroicon-s-banknotes')
                ->modifyQueryUsing(function (Builder $query) {
                    $query->where('state', MoveState::POSTED)
                        ->where('payment_state', PaymentState::IN_PAYMENT);
                }),
            'overdue' => PresetView::make(__('accounts::filament/resources/invoice/pages/list-invoice.tabs.overdue'))
                ->icon('heroicon-s-banknotes')
                ->modifyQueryUsing(function (Builder $query) {
                    $query->where('state', MoveState::POSTED)
                        ->where('payment_state', PaymentState::NOT_PAID)
                        ->where('invoice_date_due', '<', today());
                }),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus-circle'),
            Action::make('syncDrive')
                ->label('Sync from Drive')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => config('accounting_drive.enabled', false))
                ->action(function () {
                    $companyId = Auth::user()?->default_company_id;
                    $company = $companyId ? Company::query()->find($companyId) : null;
                    if (! $company) {
                        Notification::make()->danger()->title('No default company set')->send();

                        return;
                    }

                    try {
                        $touched = app(DriveIngestionService::class)->syncInbound($company);
                        $count = count($touched);

                        Notification::make()
                            ->success()
                            ->title('Google Drive Sync Complete')
                            ->body("Retrieved {$count} document(s) across all company Drive folders. Open Drive Ingestion Review to confirm or reject them.")
                            ->actions([
                                Action::make('review')
                                    ->label('Review Documents')
                                    ->button()
                                    ->url(url('/admin/accounting/configuration/drive-ingestion-classifications')),
                            ])
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Drive Sync Failed')->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
