<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports\AgedReceivableExport;
use Webkul\Accounting\Services\AgedPartnerBalanceService;
use Webkul\Partner\Models\Partner;

class AgedReceivable extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.reporting.pages.aged-receivable';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?int $navigationSort = 96;

    public ?array $data = [];

    public array $expandedPartners = [];

    public array $partnerLines = [];

    public ?int $loadingPartnerId = null;

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_aged_receivable';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/reporting.pages.aged-receivable.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/reporting.pages.aged-receivable.navigation.title');
    }

    public function getTitle(): string
    {
        return __('accounting::filament/clusters/reporting.pages.aged-receivable.navigation.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'as_of_date' => now()->toDateString(),
            'basis'      => 'due_date',
            'period'     => 30,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label(__('accounting::filament/clusters/reporting.pages.aged-receivable.actions.export-excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function () {
                    $data = $this->agedReceivableData;
                    $partners = $data['partners'];
                    $asOfDate = $data['as_of_date']->toDateString();
                    $period = $data['period'];
                    $state = $this->form->getState();
                    $basis = $state['basis'] ?? 'due_date';

                    foreach ($this->expandedPartners as $partnerId) {
                        if (isset($partners[$partnerId])) {
                            $partners[$partnerId]['lines'] = $this->getPartnerLines($partnerId);
                        }
                    }

                    return Excel::download(
                        new AgedReceivableExport($partners, $asOfDate, $period, $basis, $this->expandedPartners),
                        'aged-receivable-'.$asOfDate.'.xlsx'
                    );
                }),

            Action::make('pdf')
                ->label(__('accounting::filament/clusters/reporting.pages.aged-receivable.actions.export-pdf'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('danger')
                ->action(function () {
                    $data = $this->agedReceivableData;
                    $partners = $data['partners'];
                    $asOfDate = $data['as_of_date']->toDateString();
                    $period = $data['period'];

                    $partnerLines = [];

                    foreach ($this->expandedPartners as $partnerId) {
                        if (isset($partners[$partnerId])) {
                            $partnerLines[$partnerId] = $this->getPartnerLines($partnerId);
                        }
                    }

                    $pdf = Pdf::loadView('accounting::filament.clusters.reporting.pages.pdfs.aged-receivable', [
                        'partners'         => $partners,
                        'asOfDate'         => $asOfDate,
                        'period'           => $period,
                        'expandedPartners' => $this->expandedPartners,
                        'partnerLines'     => $partnerLines,
                    ])->setPaper('a4', 'landscape');

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf->output();
                    }, 'aged-receivable-'.$asOfDate.'.pdf');
                }),
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make()
                ->columns([
                    'default' => 1,
                    'sm'      => 3,
                ])
                ->schema([
                    DatePicker::make('as_of_date')
                        ->label(__('accounting::filament/clusters/reporting.pages.aged-receivable.filters.as-of'))
                        ->default(now())
                        ->native(false)
                        ->suffixIcon('heroicon-o-calendar')
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Select::make('basis')
                        ->label(__('accounting::filament/clusters/reporting.pages.aged-receivable.filters.based-on'))
                        ->options([
                            'due_date'     => __('accounting::filament/clusters/reporting.pages.aged-receivable.filters.options.due-date'),
                            'invoice_date' => __('accounting::filament/clusters/reporting.pages.aged-receivable.filters.options.invoice-date'),
                        ])
                        ->default('due_date')
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Select::make('period')
                        ->label(__('accounting::filament/clusters/reporting.pages.aged-receivable.filters.period-length'))
                        ->options([
                            30 => __('accounting::filament/clusters/reporting.pages.aged-receivable.filters.options.days-30'),
                            60 => __('accounting::filament/clusters/reporting.pages.aged-receivable.filters.options.days-60'),
                            90 => __('accounting::filament/clusters/reporting.pages.aged-receivable.filters.options.days-90'),
                        ])
                        ->default(30)
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Select::make('journals')
                        ->label(__('accounting::filament/clusters/reporting.pages.aged-receivable.filters.journals'))
                        ->multiple()
                        ->options(fn (): array => Journal::query()
                            ->where('company_id', Auth::user()?->default_company_id)
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Select::make('partners')
                        ->label(__('accounting::filament/clusters/reporting.pages.aged-receivable.filters.partners'))
                        ->multiple()
                        ->options(fn (): array => Partner::query()
                            ->where('company_id', Auth::user()?->default_company_id)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),

                    Select::make('posted_entries')
                        ->label(__('accounting::filament/clusters/reporting.pages.aged-receivable.filters.entries'))
                        ->options([
                            'posted' => __('accounting::filament/clusters/reporting.pages.aged-receivable.filters.options.posted-entries'),
                            'all'    => __('accounting::filament/clusters/reporting.pages.aged-receivable.filters.options.all-entries'),
                        ])
                        ->default('posted')
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetExpandedState()),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    public function togglePartnerLines($partnerId): void
    {
        if (in_array($partnerId, $this->expandedPartners)) {
            $this->expandedPartners = array_values(array_diff($this->expandedPartners, [$partnerId]));
        } else {
            $this->expandedPartners[] = $partnerId;

            if (! isset($this->partnerLines[$partnerId])) {
                $this->partnerLines[$partnerId] = $this->fetchPartnerLines($partnerId);
            }
        }
    }

    public function isPartnerExpanded($partnerId): bool
    {
        return in_array($partnerId, $this->expandedPartners);
    }

    public function expandAll(): void
    {
        $data = $this->agedReceivableData;
        $this->expandedPartners = array_keys($data['partners']);

        foreach ($this->expandedPartners as $partnerId) {
            if (! isset($this->partnerLines[$partnerId])) {
                $this->partnerLines[$partnerId] = $this->fetchPartnerLines($partnerId);
            }
        }
    }

    public function collapseAll(): void
    {
        $this->expandedPartners = [];
    }

    public function resetExpandedState(): void
    {
        $this->expandedPartners = [];
        $this->partnerLines = [];
    }

    public function getPartnerLines($partnerId): array
    {
        if (! isset($this->partnerLines[$partnerId])) {
            $this->partnerLines[$partnerId] = $this->fetchPartnerLines($partnerId);
        }

        return $this->partnerLines[$partnerId];
    }

    protected function fetchPartnerLines($partnerId): array
    {
        $state = $this->form->getState();
        $asOfDate = Carbon::parse($state['as_of_date'] ?? now());
        $period = (int) ($state['period'] ?? 30);

        $lines = [];

        foreach ($this->openItems($state, $asOfDate, [(int) $partnerId]) as $item) {
            $lineData = [
                'move_name'        => $item['move_name'],
                'invoice_date'     => $item['invoice_date'],
                'invoice_date_due' => $item['invoice_date_due'],
                'reference'        => $item['reference'],
                'journal_name'     => $item['journal_name'],
                'days_overdue'     => $item['days_overdue'],
                'at_date'          => 0,
                'period_1'         => 0,
                'period_2'         => 0,
                'period_3'         => 0,
                'period_4'         => 0,
                'older'            => 0,
            ];

            $lineData[AgedPartnerBalanceService::bucketFor($item['days_overdue'], $period)] = $item['amount'];

            $lines[] = $lineData;
        }

        return $lines;
    }

    /**
     * Open items as they stood at the end of the as-of date: moves dated
     * after it are excluded, later reconciliations are added back, and
     * ageing is measured to the as-of date (AgedPartnerBalanceService).
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, int>  $partnerIds
     */
    protected function openItems(array $state, Carbon $asOfDate, array $partnerIds = []): Collection
    {
        return app(AgedPartnerBalanceService::class)->openItems(
            (int) Auth::user()->default_company_id,
            AgedPartnerBalanceService::RECEIVABLE,
            $asOfDate,
            [
                'basis'       => $state['basis'] ?? AgedPartnerBalanceService::BASIS_DUE_DATE,
                'journal_ids' => $state['journals'] ?? [],
                'partner_ids' => $partnerIds,
                'posted_only' => ($state['posted_entries'] ?? 'posted') === 'posted',
            ],
        );
    }

    #[Computed]
    public function agedReceivableData(): array
    {
        $state = $this->form->getState();
        $asOfDate = Carbon::parse($state['as_of_date'] ?? now());
        $period = (int) ($state['period'] ?? 30);
        $companyId = Auth::user()->default_company_id;

        $partnerData = [];

        foreach ($this->openItems($state, $asOfDate, $state['partners'] ?? []) as $item) {
            $partnerId = $item['partner_id'];

            if (! isset($partnerData[$partnerId])) {
                $partnerData[$partnerId] = [
                    'id'           => $partnerId,
                    'partner_name' => $item['partner_name'],
                    'at_date'      => 0,
                    'period_1'     => 0,
                    'period_2'     => 0,
                    'period_3'     => 0,
                    'period_4'     => 0,
                    'older'        => 0,
                    'total'        => 0,
                ];
            }

            $partnerData[$partnerId][AgedPartnerBalanceService::bucketFor($item['days_overdue'], $period)] += $item['amount'];
            $partnerData[$partnerId]['total'] += $item['amount'];
        }

        $partnerData = array_filter($partnerData, fn ($partner) => abs($partner['total']) > 0.01);

        $hasUnposted = MoveLine::join('accounts_account_moves', 'accounts_account_move_lines.move_id', '=', 'accounts_account_moves.id')
            ->join('accounts_accounts', 'accounts_account_move_lines.account_id', '=', 'accounts_accounts.id')
            ->where('accounts_accounts.account_type', AccountType::ASSET_RECEIVABLE)
            ->where('accounts_account_moves.company_id', $companyId)
            ->where('accounts_account_moves.date', '<=', $asOfDate->toDateString())
            ->where('accounts_account_moves.state', '!=', MoveState::POSTED)
            ->exists();

        return [
            'partners'     => $partnerData,
            'as_of_date'   => $asOfDate,
            'period'       => $period,
            'has_unposted' => $hasUnposted,
        ];
    }
}
