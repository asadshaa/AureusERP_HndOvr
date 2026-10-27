<?php

namespace Webkul\Recruitment\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;

/**
 * Recruitment reports (roadmap Stage 6.1): where applications come from and
 * which channels lead to hires, how long hiring takes, how each job is doing,
 * where applications sit in the pipeline, and the monthly trend.
 *
 * Every figure is computed from recruitments_applicants of ONE company and an
 * application-date range, with the same status rules as
 * Applicant::getApplicationStatusAttribute(): refused (a refuse reason), else
 * archived (inactive or deleted), else hired (a close date), else in progress.
 * The per-source and per-job rows therefore always add up to the summary.
 */
class RecruitmentReportService
{
    private const STATUS_SQL = "CASE
        WHEN a.refuse_reason_id IS NOT NULL THEN 'refused'
        WHEN a.is_active = 0 OR a.deleted_at IS NOT NULL THEN 'archived'
        WHEN a.date_closed IS NOT NULL THEN 'hired'
        ELSE 'in_progress' END";

    /** Days from application to hire, for hired applications only. */
    private const DAYS_TO_HIRE_SQL = 'CASE WHEN a.refuse_reason_id IS NULL AND a.is_active = 1 AND a.deleted_at IS NULL AND a.date_closed IS NOT NULL
        THEN DATEDIFF(a.date_closed, COALESCE(a.create_date, DATE(a.created_at))) END';

    public function __construct(
        protected JobPostingChannelRegistry $channels,
    ) {}

    /**
     * @param  array{company_id: int, from?: ?string, to?: ?string, job_ids?: array<int, int>, department_ids?: array<int, int>}  $filters
     * @return array{applications: int, in_progress: int, hired: int, refused: int, archived: int, hire_rate: ?float, avg_days_to_hire: ?float, median_days_to_hire: ?float, avg_cv_score: ?float, flagged: int, open_jobs: int}
     */
    public function summary(array $filters): array
    {
        $row = $this->base($filters)
            ->selectRaw('COUNT(*) as applications')
            ->selectRaw($this->countStatus('in_progress').' as in_progress')
            ->selectRaw($this->countStatus('hired').' as hired')
            ->selectRaw($this->countStatus('refused').' as refused')
            ->selectRaw($this->countStatus('archived').' as archived')
            ->selectRaw('AVG('.self::DAYS_TO_HIRE_SQL.') as avg_days_to_hire')
            ->selectRaw('AVG(a.screening_score) as avg_cv_score')
            ->selectRaw("SUM(CASE WHEN a.screening_status = 'flagged' THEN 1 ELSE 0 END) as flagged")
            ->first();

        $days = $this->base($filters)
            ->selectRaw(self::DAYS_TO_HIRE_SQL.' as days')
            ->get()
            ->pluck('days')
            ->filter(fn ($value): bool => $value !== null)
            ->map(fn ($value): int => (int) $value)
            ->sort()
            ->values();

        $openJobs = DB::table('employees_job_positions')
            ->where('company_id', $filters['company_id'])
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('posting_status', 'published')
            ->when($filters['job_ids'] ?? [], fn (Builder $query, array $ids) => $query->whereIn('id', $ids))
            ->when($filters['department_ids'] ?? [], fn (Builder $query, array $ids) => $query->whereIn('department_id', $ids))
            ->count();

        $applications = (int) $row->applications;
        $hired = (int) $row->hired;

        return [
            'applications'        => $applications,
            'in_progress'         => (int) $row->in_progress,
            'hired'               => $hired,
            'refused'             => (int) $row->refused,
            'archived'            => (int) $row->archived,
            'hire_rate'           => $applications > 0 ? round($hired / $applications * 100, 1) : null,
            'avg_days_to_hire'    => $row->avg_days_to_hire !== null ? round((float) $row->avg_days_to_hire, 1) : null,
            'median_days_to_hire' => $this->median($days),
            'avg_cv_score'        => $row->avg_cv_score !== null ? round((float) $row->avg_cv_score, 1) : null,
            'flagged'             => (int) $row->flagged,
            'open_jobs'           => $openJobs,
        ];
    }

    /**
     * Source effectiveness: one row per channel. The channel is the job ad the
     * application came through (LinkedIn, Rozee.pk, Company website…), else
     * its UTM source, else "Direct / not recorded".
     *
     * @return Collection<int, array{key: string, label: string, applications: int, in_progress: int, hired: int, refused: int, archived: int, hire_rate: ?float, avg_days_to_hire: ?float, avg_cv_score: ?float, flagged: int}>
     */
    public function bySource(array $filters): Collection
    {
        $rows = $this->base($filters)
            ->leftJoin('recruitments_job_postings as p', 'p.id', '=', 'a.job_posting_id')
            ->leftJoin('utm_sources as s', 's.id', '=', 'a.source_id')
            ->selectRaw("CASE WHEN p.channel IS NOT NULL THEN CONCAT('channel:', p.channel)
                WHEN s.id IS NOT NULL THEN CONCAT('source:', s.id) ELSE 'none' END as source_key")
            ->selectRaw('MAX(s.name) as source_name')
            ->tap(fn (Builder $query) => $this->outcomeColumns($query))
            ->groupBy('source_key')
            ->get();

        return $rows->map(function (object $row): array {
            $label = match (true) {
                str_starts_with($row->source_key, 'channel:')   => $this->channels->labelFor(substr($row->source_key, 8)),
                str_starts_with($row->source_key, 'source:')    => (string) ($row->source_name ?: 'Source'),
                default                                         => 'Direct / not recorded',
            };

            return ['key' => $row->source_key, 'label' => $label] + $this->outcomeRow($row);
        })->sortByDesc('applications')->values();
    }

    /**
     * One row per job: volume, outcomes, speed, and how long the job has been open.
     *
     * @return Collection<int, array{job_id: ?int, label: string, posting_status: ?string, days_open: ?int, applications: int, in_progress: int, hired: int, refused: int, archived: int, hire_rate: ?float, avg_days_to_hire: ?float, avg_cv_score: ?float, flagged: int}>
     */
    public function byJob(array $filters): Collection
    {
        $rows = $this->base($filters)
            ->leftJoin('employees_job_positions as j', 'j.id', '=', 'a.job_id')
            ->selectRaw('a.job_id')
            ->selectRaw('MAX(COALESCE(j.public_title, j.name)) as job_name')
            ->selectRaw('MAX(j.posting_status) as posting_status')
            ->selectRaw('MAX(j.published_at) as published_at')
            ->tap(fn (Builder $query) => $this->outcomeColumns($query))
            ->groupBy('a.job_id')
            ->get();

        return $rows->map(fn (object $row): array => [
            'job_id'         => $row->job_id !== null ? (int) $row->job_id : null,
            'label'          => $row->job_id === null ? 'No job' : (string) ($row->job_name ?: "Job #{$row->job_id}"),
            'posting_status' => $row->posting_status,
            'days_open'      => $row->published_at && $row->posting_status === 'published' ? (int) Carbon::parse($row->published_at)->startOfDay()->diffInDays(today()) : null,
        ] + $this->outcomeRow($row))->sortByDesc('applications')->values();
    }

    /**
     * Where the in-progress applications are now, stage by stage (pipeline
     * order). Refused, archived and hired applications are left out.
     *
     * @return Collection<int, array{stage_id: ?int, label: string, applications: int, avg_days_in_stage: ?float}>
     */
    public function funnel(array $filters): Collection
    {
        return $this->base($filters)
            ->leftJoin('recruitments_stages as st', 'st.id', '=', 'a.stage_id')
            ->whereRaw(self::STATUS_SQL." = 'in_progress'")
            ->selectRaw('a.stage_id, MAX(st.name) as stage_name, MAX(COALESCE(st.sort, 9999)) as stage_sort, COUNT(*) as applications')
            ->selectRaw('AVG(DATEDIFF(CURDATE(), COALESCE(a.date_last_stage_updated, a.create_date, DATE(a.created_at)))) as avg_days')
            ->groupBy('a.stage_id')
            ->orderBy('stage_sort')
            ->get()
            ->map(fn (object $row): array => [
                'stage_id'          => $row->stage_id !== null ? (int) $row->stage_id : null,
                'label'             => (string) ($row->stage_name ?: 'No stage'),
                'applications'      => (int) $row->applications,
                'avg_days_in_stage' => $row->avg_days !== null ? round((float) $row->avg_days, 1) : null,
            ])
            ->values();
    }

    /**
     * Applications received and hires made per month (by close date), over the range.
     *
     * @return Collection<int, array{month: string, applications: int, hires: int}>
     */
    public function monthly(array $filters): Collection
    {
        $received = $this->base($filters)
            ->selectRaw("DATE_FORMAT(COALESCE(a.create_date, DATE(a.created_at)), '%Y-%m') as month, COUNT(*) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $hires = $this->base(array_merge($filters, ['from' => null, 'to' => null]))
            ->whereRaw(self::STATUS_SQL." = 'hired'")
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('a.date_closed', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('a.date_closed', '<=', $to))
            ->selectRaw("DATE_FORMAT(a.date_closed, '%Y-%m') as month, COUNT(*) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        return $received->keys()->merge($hires->keys())->unique()->sort()->values()
            ->map(fn (string $month): array => [
                'month'        => $month,
                'applications' => (int) ($received[$month] ?? 0),
                'hires'        => (int) ($hires[$month] ?? 0),
            ]);
    }

    /** Applications of the company in the date range (deleted ones included: they count as archived). */
    protected function base(array $filters): Builder
    {
        return DB::table('recruitments_applicants as a')
            ->where('a.company_id', $filters['company_id'])
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereRaw('COALESCE(a.create_date, DATE(a.created_at)) >= ?', [$from]))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereRaw('COALESCE(a.create_date, DATE(a.created_at)) <= ?', [$to]))
            ->when($filters['job_ids'] ?? [], fn (Builder $query, array $ids) => $query->whereIn('a.job_id', $ids))
            ->when($filters['department_ids'] ?? [], fn (Builder $query, array $ids) => $query->whereIn('a.department_id', $ids));
    }

    protected function outcomeColumns(Builder $query): void
    {
        $query->selectRaw('COUNT(*) as applications')
            ->selectRaw($this->countStatus('in_progress').' as in_progress')
            ->selectRaw($this->countStatus('hired').' as hired')
            ->selectRaw($this->countStatus('refused').' as refused')
            ->selectRaw($this->countStatus('archived').' as archived')
            ->selectRaw('AVG('.self::DAYS_TO_HIRE_SQL.') as avg_days_to_hire')
            ->selectRaw('AVG(a.screening_score) as avg_cv_score')
            ->selectRaw("SUM(CASE WHEN a.screening_status = 'flagged' THEN 1 ELSE 0 END) as flagged");
    }

    /** @return array{applications: int, in_progress: int, hired: int, refused: int, archived: int, hire_rate: ?float, avg_days_to_hire: ?float, avg_cv_score: ?float, flagged: int} */
    protected function outcomeRow(object $row): array
    {
        $applications = (int) $row->applications;

        return [
            'applications'     => $applications,
            'in_progress'      => (int) $row->in_progress,
            'hired'            => (int) $row->hired,
            'refused'          => (int) $row->refused,
            'archived'         => (int) $row->archived,
            'hire_rate'        => $applications > 0 ? round((int) $row->hired / $applications * 100, 1) : null,
            'avg_days_to_hire' => $row->avg_days_to_hire !== null ? round((float) $row->avg_days_to_hire, 1) : null,
            'avg_cv_score'     => $row->avg_cv_score !== null ? round((float) $row->avg_cv_score, 1) : null,
            'flagged'          => (int) $row->flagged,
        ];
    }

    protected function countStatus(string $status): string
    {
        return 'SUM(CASE WHEN ('.self::STATUS_SQL.") = '{$status}' THEN 1 ELSE 0 END)";
    }

    /** @param  Collection<int, int>  $sorted */
    protected function median(Collection $sorted): ?float
    {
        $count = $sorted->count();

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return (float) ($count % 2 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2);
    }
}
