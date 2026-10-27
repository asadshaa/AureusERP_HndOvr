<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Models\Department;
use Webkul\Recruitment\Filament\Clusters\Applications;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Services\Reports\RecruitmentReportService;
use Webkul\Security\Services\CurrentCompany;

/**
 * Recruitment reports (roadmap Stage 6.1) for the active company: summary,
 * source effectiveness, jobs, pipeline and monthly trend, each downloadable
 * as CSV. Needs the right to list applicants.
 */
class RecruitmentReports extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'recruitments::filament.clusters.applications.pages.recruitment-reports';

    protected static ?string $cluster = Applications::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Recruitment reports';

    protected static ?string $slug = 'reports';

    protected static ?int $navigationSort = 90;

    /** @var array{from?: ?string, to?: ?string, job_ids?: array<int, int>, department_ids?: array<int, int>} */
    public ?array $filters = [];

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return $user !== null && CurrentCompany::id() !== null && $user->can('view_any_recruitment_applicant');
    }

    public function mount(): void
    {
        $this->form->fill([
            'from'           => now()->subMonths(6)->startOfMonth()->toDateString(),
            'to'             => now()->toDateString(),
            'job_ids'        => [],
            'department_ids' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->components([
            Section::make()
                ->schema([
                    DatePicker::make('from')->label('Applications from')->native(false)->maxDate(now())->live(),
                    DatePicker::make('to')->label('to')->native(false)->maxDate(now())->live(),
                    Select::make('job_ids')
                        ->label('Jobs')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => JobPosition::query()->where('company_id', CurrentCompany::id())->orderBy('name')->pluck('name', 'id')->all())
                        ->live(),
                    Select::make('department_ids')
                        ->label('Departments')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => Department::query()->where('company_id', CurrentCompany::id())->orderBy('name')->pluck('name', 'id')->all())
                        ->live(),
                ])
                ->columns(['default' => 1, 'md' => 4]),
        ]);
    }

    /** @return array{company_id: int, from: ?string, to: ?string, job_ids: array<int, int>, department_ids: array<int, int>} */
    public function reportFilters(): array
    {
        $companyId = (int) CurrentCompany::id();
        $allowedJobs = JobPosition::query()->where('company_id', $companyId)->pluck('id')->all();
        $allowedDepartments = Department::query()->where('company_id', $companyId)->pluck('id')->all();

        return [
            'company_id'     => $companyId,
            'from'           => $this->date($this->filters['from'] ?? null),
            'to'             => $this->date($this->filters['to'] ?? null),
            'job_ids'        => array_values(array_intersect(array_map('intval', (array) ($this->filters['job_ids'] ?? [])), $allowedJobs)),
            'department_ids' => array_values(array_intersect(array_map('intval', (array) ($this->filters['department_ids'] ?? [])), $allowedDepartments)),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $service = app(RecruitmentReportService::class);
        $filters = $this->reportFilters();

        return [
            'summary'  => $service->summary($filters),
            'sources'  => $service->bySource($filters),
            'jobs'     => $service->byJob($filters),
            'funnel'   => $service->funnel($filters),
            'monthly'  => $service->monthly($filters),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->csvAction('exportSources', 'Sources (CSV)', 'sources', fn (RecruitmentReportService $s, array $f) => $s->bySource($f)),
                $this->csvAction('exportJobs', 'Jobs (CSV)', 'jobs', fn (RecruitmentReportService $s, array $f) => $s->byJob($f)),
                $this->csvAction('exportFunnel', 'Pipeline (CSV)', 'pipeline', fn (RecruitmentReportService $s, array $f) => $s->funnel($f)),
                $this->csvAction('exportMonthly', 'Monthly trend (CSV)', 'monthly', fn (RecruitmentReportService $s, array $f) => $s->monthly($f)),
            ])
                ->label('Download')
                ->icon('heroicon-o-arrow-down-tray')
                ->button()
                ->color('gray'),
        ];
    }

    /** @param  callable(RecruitmentReportService, array): Collection  $rows */
    protected function csvAction(string $name, string $label, string $file, callable $rows): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-table-cells')
            ->action(function () use ($rows, $file): StreamedResponse {
                abort_unless(static::canAccess(), 403);

                $filters = $this->reportFilters();
                $data = $rows(app(RecruitmentReportService::class), $filters);
                $name = sprintf('recruitment-%s-%s-%s.csv', $file, Str::slug((string) CurrentCompany::user()?->defaultCompany?->name), now()->format('Ymd'));

                return response()->streamDownload(function () use ($data): void {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF");

                    if ($data->isNotEmpty()) {
                        fputcsv($out, array_map(fn (string $key): string => Str::headline($key), array_keys($data->first())));
                    }

                    foreach ($data as $row) {
                        fputcsv($out, array_map(fn ($value) => is_bool($value) ? ($value ? 'yes' : 'no') : $value, $row));
                    }

                    fclose($out);
                }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
            });
    }

    protected function date(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
