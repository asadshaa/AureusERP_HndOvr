<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\HomeDashboardContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Recruitment\Enums\InterviewStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Pages\RecruitmentReports;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\InterviewResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Pages\WebsiteIntake;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Recruitment\Filament\Schemas\CvAnalysisComponents;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Interview;

/**
 * Hiring at a glance for recruiters and HR (anyone who may list
 * applicants): new applications this week with their CV score, interviews
 * today and this week (the Interviews list's own query, so an interviewer
 * only counts their own), and published jobs, all for the active company.
 */
class RecruitmentPipelineWidget extends Widget
{
    protected string $view = 'filament.widgets.home.recruitment';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(HomeDashboardContext::class)->isRecruiter();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $context = app(HomeDashboardContext::class);
        $companyId = $context->companyId();

        if ($companyId === null) {
            return ['newCount' => 0, 'newApplicants' => [], 'interviewsToday' => [], 'counts' => ['today' => 0, 'week' => 0], 'openJobs' => 0, 'urls' => []];
        }

        $timezone = Interview::displayTimezone();
        $now = CarbonImmutable::now($timezone);
        $weekStartUtc = $now->startOfWeek()->utc();
        $weekEndUtc = $now->endOfWeek()->utc();
        $dayStartUtc = $now->startOfDay()->utc();
        $dayEndUtc = $now->endOfDay()->utc();

        $newQuery = Applicant::query()
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $weekStartUtc);

        $newCount = (clone $newQuery)->count();

        $applicantUrl = $context->resourceUrl(ApplicantResource::class);

        $newApplicants = $newQuery
            ->with(['candidate:id,name', 'job:id,name'])
            ->orderByRaw('screening_score IS NULL')
            ->orderByDesc('screening_score')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'candidate_id', 'job_id', 'screening_score', 'screening_status', 'created_at'])
            ->map(fn (Applicant $applicant): array => [
                'name'      => (string) ($applicant->candidate?->name ?? 'Applicant #'.$applicant->id),
                'job'       => (string) ($applicant->job?->name ?? 'No job'),
                'score'     => $applicant->screening_score !== null ? (int) round((float) $applicant->screening_score) : null,
                'color'     => CvAnalysisComponents::scoreColor($applicant->screening_score),
                'flagged'   => $applicant->screening_status === 'flagged',
                'when'      => $applicant->created_at?->diffForHumans(short: true),
                'url'       => $applicantUrl ? ApplicantResource::getUrl('view', ['record' => $applicant->id]) : null,
            ])
            ->all();

        $interviewUrl = $context->resourceUrl(InterviewResource::class);
        $interviewBase = $interviewUrl ? InterviewResource::getEloquentQuery()->where('status', InterviewStatus::SCHEDULED->value) : null;

        $counts = ['today' => 0, 'week' => 0];
        $interviewsToday = [];

        if ($interviewBase) {
            $row = (clone $interviewBase)
                ->whereBetween('starts_at', [$weekStartUtc, $weekEndUtc])
                ->toBase()
                ->selectRaw('COUNT(*) as week_count')
                ->selectRaw('SUM(CASE WHEN starts_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as today_count', [$dayStartUtc, $dayEndUtc])
                ->first();

            $counts = ['today' => (int) ($row->today_count ?? 0), 'week' => (int) ($row->week_count ?? 0)];

            $interviewsToday = (clone $interviewBase)
                ->whereBetween('starts_at', [$dayStartUtc, $dayEndUtc])
                ->with(['applicant:id,candidate_id', 'applicant.candidate:id,name', 'job:id,name'])
                ->orderBy('starts_at')
                ->limit(5)
                ->get(['recruitments_interviews.id', 'applicant_id', 'job_id', 'starts_at', 'mode', 'title'])
                ->map(fn (Interview $interview): array => [
                    'time' => $interview->starts_at->copy()->setTimezone($timezone)->format('H:i'),
                    'name' => (string) ($interview->applicant?->candidate?->name ?? $interview->title ?? 'Interview'),
                    'job'  => (string) ($interview->job?->name ?? ''),
                    'mode' => $interview->mode?->getLabel(),
                ])
                ->all();
        }

        $openJobs = EmployeeJobPosition::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('posting_status', 'published')
            ->count();

        return [
            'newCount'        => $newCount,
            'newApplicants'   => $newApplicants,
            'counts'          => $counts,
            'interviewsToday' => $interviewsToday,
            'openJobs'        => $openJobs,
            'urls'            => [
                'applicants' => $applicantUrl,
                'interviews' => $interviewUrl,
                'jobs'       => $context->resourceUrl(JobPositionResource::class),
                'intake'     => $context->pageUrl(WebsiteIntake::class),
                'reports'    => $context->pageUrl(RecruitmentReports::class),
            ],
        ];
    }
}
