<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\EmploymentType;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Contracts\JobPostingPublisher;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Services\JobOpeningPublicationService;
use Webkul\Recruitment\Services\JobPostingService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

require_once __DIR__.'/JobOpeningHelpers.php';

/**
 * Shared helpers for the job ads (Recruitment Stage 2.3) tests. Not a test
 * file itself; require_once it.
 *
 * @return array{company: Company, job: JobPosition, manager: User, chain: array{recruiter: User, manager: User}}
 */
function jobPostingFixture(): array
{
    // Fresh acting user: a previous test's rolled-back user must not be stamped as creator_id.
    Auth::login(User::factory()->create(['is_active' => true]));

    $company = Company::factory()->create(['is_active' => true, 'name' => 'Truck It In Test '.Str::random(5)]);
    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);
    $address = Partner::query()->create(['name' => 'Karachi Office', 'sub_type' => 'company', 'company_id' => $company->id, 'city' => 'Karachi']);
    $employmentType = EmploymentType::query()->create(['name' => 'Full-time']);
    $manager = jobPostingManager($company);

    $job = JobPosition::query()->create([
        'company_id'         => $company->id,
        'department_id'      => $department->id,
        'address_id'         => $address->id,
        'employment_type_id' => $employmentType->id,
        'recruiter_id'       => $manager->id,
        'name'               => 'Fleet Dispatcher',
        'description'        => '<p>Plan daily truck routes &amp; driver shifts.</p>',
        'requirements'       => '<ul><li>2 years dispatch experience</li><li>Urdu and English</li></ul>',
        'is_active'          => true,
    ]);

    $chain = jobOpeningApprovalChain($company);

    return compact('company', 'job', 'manager', 'chain');
}

/** A recruiter who may manage job ads (and edit jobs) in $company. */
function jobPostingManager(Company $company): User
{
    return jobOpeningUser($company, HrPermissions::ManageJobPostings, 'update_recruitment_job::position', 'view_recruitment_job::position', 'view_any_recruitment_job::position');
}

/** Submits, approves and publishes the job through the real services, using the fixture's approval chain. */
function jobPostingPublish(array $f): JobPosition
{
    $request = app(JobOpeningPublicationService::class)->submitForApproval($f['job']->refresh(), $f['chain']['recruiter']);
    app(ApprovalEngine::class)->approve($request, $f['chain']['manager'], 'Approved');

    return app(JobOpeningPublicationService::class)->publish($f['job']->refresh(), $f['chain']['recruiter']);
}

/** Approves the job without publishing it. */
function jobPostingApprove(array $f): ApprovalRequest
{
    $request = app(JobOpeningPublicationService::class)->submitForApproval($f['job']->refresh(), $f['chain']['recruiter']);

    return app(ApprovalEngine::class)->approve($request, $f['chain']['manager'], 'Approved');
}

/** @param array<int, string|array{channel: string, placement?: string}> $channels */
function jobPostingAdd(array $f, array $channels): Collection
{
    return app(JobPostingService::class)->addChannels(
        $f['job']->refresh(),
        array_map(fn ($channel): array => is_string($channel) ? ['channel' => $channel] : $channel, $channels),
        $f['manager'],
    );
}

function jobPostingFor(JobPosition $job, string $channel, string $placement = ''): JobPosting
{
    return JobPosting::query()->where('job_id', $job->id)->where('channel', $channel)->where('placement', $placement)->firstOrFail();
}

/** Stage 3/5 stand-in: a publisher for the careers page that succeeds or throws on demand. */
class FakeJobPostingPublisher implements JobPostingPublisher
{
    public bool $fail = false;

    /** @var array<int, int> */
    public array $synced = [];

    public function channelKey(): string
    {
        return 'careers_site';
    }

    public function isAvailable(JobPosition $job): bool
    {
        return true;
    }

    public function sync(JobPosting $posting): ?string
    {
        $this->synced[] = $posting->id;

        if ($this->fail) {
            throw new RuntimeException('Careers page is down');
        }

        return "https://careers.example.com/jobs/{$posting->job_id}";
    }
}
