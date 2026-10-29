<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Services\Payroll\PayrollInputExcel;
use Webkul\Employee\Services\Payroll\PayrollInputReport;
use Webkul\Employee\Services\Payroll\RequestInstalmentService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Models\Company;

/**
 * Attendance -> Payroll input: the monthly payroll-input file for one
 * company (PayrollInputReport), on screen and as Excel. A month that is not
 * finalised shows a warning and exports as a DRAFT. After payroll has been
 * run with the file, "Mark instalments as deducted" closes the month's due
 * loan instalments and advance deductions (audited, idempotent).
 */
class PayrollInput extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'payroll-input';

    protected string $view = 'employees::filament.pages.payroll-input';

    /** @var array{company_id?: ?int, month?: ?string} */
    public ?array $filters = [];

    /** @var array<string, array<string, mixed>> the report per company|month, for this request */
    protected array $reports = [];

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return PayrollInputReport::canExport($user) && CurrentCompany::membershipIds($user) !== [];
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return 'Payroll input';
    }

    public function getTitle(): string
    {
        return 'Payroll input';
    }

    public function mount(): void
    {
        $now = now(config('attendance.monthly_report.timezone'));

        $this->form->fill([
            'company_id' => CurrentCompany::id() ?? (CurrentCompany::membershipIds()[0] ?? null),
            // Late in the month payroll is usually for this month, early in it for the last one.
            'month'      => ($now->day >= 20 ? $now : $now->copy()->subMonthNoOverflow())->format('Y-m'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->components([
            Section::make()
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(fn (): array => Company::query()->whereIn('id', CurrentCompany::membershipIds())->orderBy('name')->pluck('name', 'id')->all())
                        ->required()
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make('month')
                        ->label('Month')
                        ->options(fn (): array => $this->monthOptions())
                        ->required()
                        ->selectablePlaceholder(false)
                        ->live(),
                ])
                ->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function report(): ?array
    {
        $user = CurrentCompany::user();
        $companyId = $this->companyId();

        if (! $user instanceof User || $companyId === null) {
            return null;
        }

        return $this->reports[$companyId.'|'.$this->month()] ??= app(PayrollInputReport::class)->build($user, $companyId, $this->month());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        try {
            $report = $this->report();
        } catch (AuthorizationException|InvalidArgumentException $exception) {
            return ['report' => null, 'error' => $exception->getMessage()];
        }

        return ['report' => $report, 'error' => null];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadExcel')
                ->label(fn (): string => $this->isDraft() ? 'Download DRAFT Excel' : 'Download Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color(fn (): string => $this->isDraft() ? 'warning' : 'success')
                ->action(function (): StreamedResponse {
                    abort_unless(static::canAccess(), 403);

                    try {
                        $report = $this->report();
                    } catch (AuthorizationException) {
                        abort(403);
                    }

                    abort_if($report === null, 403);

                    return app(PayrollInputExcel::class)->download($report);
                }),
            Action::make('markDeducted')
                ->label('Mark instalments as deducted')
                ->icon('heroicon-o-check-badge')
                ->color('gray')
                ->visible(fn (): bool => $this->dueCount() > 0
                    && RequestInstalmentService::canManageCompany(CurrentCompany::user(), (int) $this->companyId()))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => 'Mark '.Carbon::createFromFormat('!Y-m', $this->month())->format('F Y').' instalments as deducted?')
                ->modalDescription(fn (): string => $this->markDescription())
                ->modalSubmitActionLabel('Mark as deducted')
                ->action(function (): void {
                    try {
                        $report = $this->report();
                        $result = app(RequestInstalmentService::class)->markMonthDeducted(
                            CurrentCompany::user(),
                            (int) $this->companyId(),
                            $this->month(),
                            (array) ($report['employee_ids'] ?? []),
                        );
                        $this->reports = [];

                        Notification::make()->success()
                            ->title($result['count'] > 0 ? $result['count'].' instalment(s) marked as deducted' : 'Nothing left to mark')
                            ->body($result['count'] > 0 ? 'Total '.number_format((float) $result['amount'], 2).'. The change is recorded in each request\'s instalment history.' : 'Every instalment of this month was already marked.')
                            ->send();
                    } catch (AuthorizationException|InvalidArgumentException|RuntimeException $e) {
                        Notification::make()->danger()->title('Not marked')->body($e->getMessage())->send();
                    }
                }),
        ];
    }

    protected function isDraft(): bool
    {
        try {
            return (bool) ($this->report()['draft'] ?? true);
        } catch (AuthorizationException|InvalidArgumentException) {
            return true;
        }
    }

    protected function dueCount(): int
    {
        try {
            return (int) ($this->report()['deductions']['due_count'] ?? 0);
        } catch (AuthorizationException|InvalidArgumentException) {
            return 0;
        }
    }

    protected function markDescription(): string
    {
        $report = $this->report();
        $text = ($report['deductions']['due_count'] ?? 0).' due loan instalment(s) / advance deduction(s) of '.($report['month_label'] ?? '')
            .', total '.number_format((float) ($report['deductions']['due_amount'] ?? 0), 2).', will be marked as deducted.';

        if (! empty($report['draft'])) {
            $text .= ' Attendance for this month is not finalised yet.';
        }

        return $text.' Do this once payroll has been run with this month\'s file. Instalments already marked are left as they are; each change is recorded with your name.';
    }

    /** The selected company, only when the user belongs to it. */
    protected function companyId(): ?int
    {
        $companyId = (int) ($this->filters['company_id'] ?? 0);

        return $companyId > 0 && CurrentCompany::canAccess($companyId) ? $companyId : null;
    }

    protected function month(): string
    {
        $month = (string) ($this->filters['month'] ?? '');

        return RequestInstalmentService::isMonth($month) ? $month : now(config('attendance.monthly_report.timezone'))->format('Y-m');
    }

    /** @return array<string, string> */
    protected function monthOptions(): array
    {
        $current = Carbon::now(config('attendance.monthly_report.timezone'))->startOfMonth();
        $options = [];

        for ($offset = 0; $offset < 24; $offset++) {
            $month = $current->copy()->subMonthsNoOverflow($offset);
            $options[$month->format('Y-m')] = $month->format('F Y');
        }

        return $options;
    }
}
