<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\PeriodLock;
use Webkul\Account\Services\PeriodLockService;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;

/**
 * Month-end / period-close control. Everything dated on or before the lock
 * date is protected from new postings across every posting path in the app
 * (see PeriodLockService's own doc for exactly which). Deliberately a
 * single-page toggle, not a resource: there's one lock per company, not a
 * list of records to browse.
 */
class ManagePeriodLock extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.configuration.pages.manage-period-lock';

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?int $navigationSort = 5;

    public ?array $data = [];

    protected static function getPagePermission(): ?string
    {
        return AccountingPermissions::PeriodLockPage;
    }

    public static function getNavigationLabel(): string
    {
        return 'Period Lock';
    }

    public function getTitle(): string
    {
        return 'Period Lock';
    }

    public function mount(): void
    {
        $this->form->fill([
            'locked_through_date' => $this->currentLock()?->locked_through_date?->toDateString(),
        ]);
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        $lock = $this->currentLock();

        return [
            Section::make('Current status')->schema([
                Placeholder::make('status')
                    ->hiddenLabel()
                    ->content($lock?->locked_through_date
                        ? "Locked through {$lock->locked_through_date->toDateString()}".($lock->lockedByUser?->name ? " (set by {$lock->lockedByUser->name}, {$lock->locked_at?->diffForHumans()})" : '').'. Nothing dated on or before this date can be posted anywhere in the app.'
                        : 'Nothing is locked. Every date is open to posting.'),
            ]),
            Section::make('Set the lock date')
                ->description('Everything dated on or before this date will be protected from new postings, reversals, and bank/manual-adjustment posting -- everywhere in the app.')
                ->schema([
                    DatePicker::make('locked_through_date')->label('Locked through')->native(false),
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save lock date')
                ->authorize(AccountingPermissions::ManagePeriodLock)
                ->requiresConfirmation()
                ->modalDescription('This immediately blocks posting for every date on or before the date you set, for every user.')
                ->action(function (): void {
                    $state = $this->form->getState();
                    if (! $state['locked_through_date']) {
                        Notification::make()->warning()->title('Pick a date first, or use Unlock to clear an existing lock.')->send();

                        return;
                    }

                    $company = Company::query()->findOrFail(Auth::user()?->default_company_id);
                    app(PeriodLockService::class)->lockThrough($company, Auth::user(), Carbon::parse($state['locked_through_date']));

                    Notification::make()->success()->title("Locked through {$state['locked_through_date']}.")->send();
                }),
            Action::make('unlock')
                ->label('Unlock')
                ->color('danger')
                ->authorize(AccountingPermissions::ManagePeriodLock)
                ->requiresConfirmation()
                ->modalDescription('This reopens every previously-locked date to posting again.')
                ->visible(fn (): bool => (bool) $this->currentLock()?->locked_through_date)
                ->action(function (): void {
                    $company = Company::query()->findOrFail(Auth::user()?->default_company_id);
                    app(PeriodLockService::class)->unlock($company);

                    $this->form->fill(['locked_through_date' => null]);
                    Notification::make()->success()->title('Period unlocked.')->send();
                }),
        ];
    }

    private function currentLock(): ?PeriodLock
    {
        return PeriodLock::query()->where('company_id', Auth::user()?->default_company_id)->first();
    }
}
