<?php

/**
 * Recruitment reports (roadmap Stage 6.1): source effectiveness, jobs,
 * pipeline and monthly trend for one company and period, with the same
 * status rules as the applicant list, totals that reconcile, company
 * isolation, the page and its CSV downloads; plus the 6.2 widget fixes.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Webkul\Recruitment\Filament\Clusters\Applications\Pages\RecruitmentReports;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Models\UTMSource;
use Webkul\Recruitment\Services\Reports\RecruitmentReportService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/Support/JobOpeningHelpers.php';
require_once __DIR__.'/Support/JobPostingHelpers.php';

/** An application created $daysAgo days ago, optionally hired $hiredAfter days later, refused, archived or deleted. */
function reportApplicant(JobPosition $job, int $daysAgo, array $options = []): Applicant
{
    $candidate = Candidate::query()->create(['company_id' => $job->company_id, 'name' => 'Candidate '.Str::random(5), 'email_from' => Str::random(8).'@example.test']);
    $created = today()->subDays($daysAgo);

    $applicant = Applicant::query()->create(array_filter([
        'company_id'      => $job->company_id,
        'candidate_id'    => $candidate->id,
        'job_id'          => $job->id,
        'create_date'     => $created->toDateString(),
        'job_posting_id'  => $options['posting'] ?? null,
        'source_id'       => $options['source'] ?? null,
        'stage_id'        => $options['stage'] ?? null,
    ], fn ($value): bool => $value !== null));

    // Set after creating: a new application is scored by the CV analyser (no CV here → no score).
    $changes = isset($options['score']) ? ['screening_score' => $options['score']] : [];

    if (isset($options['hiredAfter'])) {
        $changes['date_closed'] = $created->copy()->addDays($options['hiredAfter'])->toDateString();
    }

    if ($options['refused'] ?? false) {
        $changes['refuse_reason_id'] = RefuseReason::query()->value('id');
    }

    if ($options['archived'] ?? false) {
        $changes['is_active'] = false;
    }

    if ($changes) {
        $applicant->forceFill($changes)->saveQuietly();
    }

    if ($options['deleted'] ?? false) {
        $applicant->delete();
    }

    return $applicant;
}

/** @return array{f: array, linkedin: int, rozee: int, source: UTMSource, other: JobPosition} */
function reportDataset(): array
{
    $f = jobPostingFixture();
    jobPostingPublish($f);
    $postings = jobPostingAdd($f, ['linkedin', 'rozee']);
    $linkedin = jobPostingFor($f['job'], 'linkedin')->id;
    $rozee = jobPostingFor($f['job'], 'rozee')->id;
    $source = UTMSource::query()->create(['name' => 'Referral '.Str::random(4)]);

    reportApplicant($f['job'], 40, ['posting' => $linkedin, 'hiredAfter' => 10, 'score' => 80]);
    reportApplicant($f['job'], 30, ['posting' => $linkedin, 'hiredAfter' => 20, 'score' => 60]);
    reportApplicant($f['job'], 20, ['posting' => $linkedin, 'refused' => true]);
    reportApplicant($f['job'], 10, ['posting' => $rozee]);
    reportApplicant($f['job'], 5, ['posting' => $rozee, 'archived' => true]);
    reportApplicant($f['job'], 3, ['source' => $source->id, 'deleted' => true]);
    reportApplicant($f['job'], 2);
    reportApplicant($f['job'], 400, ['posting' => $linkedin, 'hiredAfter' => 5]);

    $otherCompany = Company::factory()->create(['is_active' => true]);
    $other = JobPosition::query()->create(['company_id' => $otherCompany->id, 'name' => 'Other job', 'is_active' => true]);
    reportApplicant($other, 10, ['hiredAfter' => 1]);

    return ['f' => $f, 'linkedin' => $linkedin, 'rozee' => $rozee, 'source' => $source, 'other' => $other];
}

function reportFilters(int $companyId): array
{
    return ['company_id' => $companyId, 'from' => today()->subDays(90)->toDateString(), 'to' => today()->toDateString()];
}

it('summarises a company\'s applications in the period with the applicant list\'s status rules', function () {
    ['f' => $f] = reportDataset();

    $summary = app(RecruitmentReportService::class)->summary(reportFilters($f['company']->id));

    expect($summary)->toMatchArray([
        'applications'        => 7,
        'in_progress'         => 2,
        'hired'               => 2,
        'refused'             => 1,
        'archived'            => 2,
        'hire_rate'           => 28.6,
        'avg_days_to_hire'    => 15.0,
        'median_days_to_hire' => 15.0,
        'avg_cv_score'        => 70.0,
        'open_jobs'           => 1,
    ]);
});

it('shows which sources bring applications and hires, and adds up to the summary', function () {
    ['f' => $f, 'source' => $source] = reportDataset();
    $service = app(RecruitmentReportService::class);
    $filters = reportFilters($f['company']->id);

    $rows = $service->bySource($filters)->keyBy('key');

    expect($rows['channel:linkedin'])->toMatchArray(['label' => 'LinkedIn', 'applications' => 3, 'hired' => 2, 'refused' => 1, 'hire_rate' => 66.7, 'avg_days_to_hire' => 15.0])
        ->and($rows['channel:rozee'])->toMatchArray(['applications' => 2, 'in_progress' => 1, 'archived' => 1, 'hired' => 0])
        ->and($rows["source:{$source->id}"])->toMatchArray(['label' => $source->name, 'applications' => 1, 'archived' => 1])
        ->and($rows['none'])->toMatchArray(['label' => 'Direct / not recorded', 'applications' => 1, 'in_progress' => 1])
        ->and($rows->sum('applications'))->toBe($service->summary($filters)['applications'])
        ->and($rows->sum('hired'))->toBe($service->summary($filters)['hired']);
});

it('reports per job, the current pipeline and the monthly trend', function () {
    ['f' => $f] = reportDataset();
    $service = app(RecruitmentReportService::class);
    $filters = reportFilters($f['company']->id);

    $jobs = $service->byJob($filters);
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0])->toMatchArray(['job_id' => $f['job']->id, 'label' => 'Fleet Dispatcher', 'applications' => 7, 'hired' => 2, 'posting_status' => 'published', 'days_open' => 0]);

    expect($service->funnel($filters)->sum('applications'))->toBe(2);

    $monthly = $service->monthly($filters);
    expect($monthly->sum('applications'))->toBe(7)
        ->and($monthly->sum('hires'))->toBe(2);
});

it('never mixes in another company\'s applications and narrows to the chosen jobs', function () {
    ['f' => $f, 'other' => $other] = reportDataset();
    $service = app(RecruitmentReportService::class);

    expect($service->summary(reportFilters($other->company_id)))->toMatchArray(['applications' => 1, 'hired' => 1])
        ->and($service->summary(reportFilters($f['company']->id) + ['job_ids' => [$other->id]])['applications'])->toBe(0);
});

it('shows the reports page to people who can list applicants and lets them download CSV', function () {
    ['f' => $f] = reportDataset();
    $recruiter = jobOpeningRoleUser($f['company'], 'recruiter');
    Auth::login($recruiter);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(RecruitmentReports::class)
        ->assertOk()
        ->assertSee('Where applications come from')
        ->assertSee('LinkedIn')
        ->callAction('exportSources')
        ->assertFileDownloaded();

    $outsider = User::factory()->create(['is_active' => true, 'default_company_id' => $f['company']->id]);
    Auth::login($outsider);

    expect(RecruitmentReports::canAccess())->toBeFalse();
});
