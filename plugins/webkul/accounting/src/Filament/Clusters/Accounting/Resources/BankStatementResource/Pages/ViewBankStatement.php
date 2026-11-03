<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankStatementResource;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Support\AccountingPermissions;

class ViewBankStatement extends ViewRecord
{
    protected static string $resource = BankStatementResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Statement')
                ->schema(BankStatementResource::infolistSchema()),
        ]);
    }

    /**
     * The two supported ways out of ReconciliationFailed (see
     * BankStatementImportService::revalidate() and
     * ::acceptReconciliationDifferences(), which enforce the same
     * permission server-side).
     */
    protected function getHeaderActions(): array
    {
        $failedReconciliation = fn (): bool => $this->record->import_status === BankImportStatus::ReconciliationFailed->value;

        return [
            Action::make('revalidate')
                ->label('Re-validate')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->authorize(AccountingPermissions::ReviewBankTransactions)
                ->visible($failedReconciliation)
                ->action(function (): void {
                    $statement = app(BankStatementImportService::class)->revalidate($this->record);
                    $this->record->refresh();

                    if ($statement->import_status === BankImportStatus::Validated->value) {
                        Notification::make()->success()->title('Statement validated')->send();

                        return;
                    }

                    Notification::make()
                        ->warning()
                        ->title('Still not reconciled')
                        ->body(collect($statement->validation_errors)->pluck('message')->take(5)->implode(' '))
                        ->send();
                }),
            Action::make('acceptDifferences')
                ->label('Accept differences & validate')
                ->icon('heroicon-o-shield-check')
                ->color('warning')
                ->authorize(AccountingPermissions::ReviewBankTransactions)
                ->visible($failedReconciliation)
                ->requiresConfirmation()
                ->modalDescription('The reported reconciliation differences will be accepted as they are and the statement becomes postable. Your name, the time and your reason are recorded.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->minLength(10),
                ])
                ->action(function (array $data): void {
                    try {
                        app(BankStatementImportService::class)->acceptReconciliationDifferences($this->record, Auth::user(), (string) $data['reason']);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Could not validate the statement')->body($e->getMessage())->send();

                        return;
                    }

                    $this->record->refresh();

                    Notification::make()->success()->title('Differences accepted; statement validated')->send();
                }),
        ];
    }
}
