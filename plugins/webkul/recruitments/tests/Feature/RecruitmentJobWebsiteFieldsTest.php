<?php

/**
 * Job fields for the careers website and Google for Jobs
 * (docs/RECRUITMENT_AUTOMATION_PLAN.md, phase A2): public title, slug, work
 * location, apply-before date and salary; the website payload (API contract
 * v1, plan §7), the JobPosting structured data, and the readiness checklist.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Webkul\Employee\Models\EmploymentType;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Enums\JobLocationType;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages\ViewJobPosition;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Services\JobPublicProfile;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/Support/JobOpeningHelpers.php';

/** @return array{company: Company, job: JobPosition} */
function websiteJobFixture(array $attributes = []): array
{
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['is_active' => true, 'name' => 'Truck It In '.Str::random(6)]);
    $address = Partner::query()->create([
        'name' => 'Karachi office', 'sub_type' => 'company', 'company_id' => $company->id,
        'city' => 'Karachi', 'country_id' => Country::query()->where('code', 'PK')->value('id'),
    ]);

    $job = JobPosition::query()->create($attributes + [
        'company_id'   => $company->id,
        'name'         => 'Fleet Dispatcher',
        'is_active'    => true,
        'address_id'   => $address->id,
        'description'  => '<p class="x" onclick="evil()">Plan the <strong>fleet</strong>.</p><script>alert(1)</script>',
        'requirements' => '<ul><li>2 years dispatching</li></ul>',
    ]);

    return compact('company', 'job');
}

it('gives every job a web address that is unique within its company and stays put', function () {
    ['company' => $company, 'job' => $job] = websiteJobFixture(['public_title' => 'Fleet Dispatcher – Karachi']);

    expect($job->slug)->toBe('fleet-dispatcher-karachi');

    $twin = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Other', 'public_title' => 'Fleet Dispatcher – Karachi', 'is_active' => true]);
    expect($twin->slug)->toBe('fleet-dispatcher-karachi-2');

    $job->update(['public_title' => 'Senior Fleet Dispatcher']);
    expect($job->refresh()->slug)->toBe('fleet-dispatcher-karachi');

    $elsewhere = JobPosition::query()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Fleet Dispatcher – Karachi', 'is_active' => true]);
    expect($elsewhere->slug)->toBe('fleet-dispatcher-karachi');
});

it('refuses a salary range whose minimum is above its maximum', function () {
    ['job' => $job] = websiteJobFixture();

    expect(fn () => $job->update(['salary_min' => 200000, 'salary_max' => 100000]))
        ->toThrow(InvalidArgumentException::class);
});

it('builds the website payload of API contract v1 and never sends a hidden salary', function () {
    $fullTime = EmploymentType::query()->firstOrCreate(['name' => 'Full-Time'], ['code' => 'Full-Time']);
    ['job' => $job] = websiteJobFixture([
        'public_title'         => 'Fleet Dispatcher',
        'location_type'        => JobLocationType::ON_SITE,
        'employment_type_id'   => $fullTime->id,
        'valid_through'        => now()->addMonth()->toDateString(),
        'salary_min'           => 120000,
        'salary_max'           => 160000,
        'salary_currency_id'   => Currency::query()->where('code', 'PKR')->value('id'),
        'salary_visible'       => false,
        'min_experience_years' => 2,
    ]);
    JobQuestion::query()->create(['job_id' => $job->id, 'company_id' => $job->company_id, 'type' => 'yes_no', 'label' => 'Can you work on-site in Karachi?', 'is_required' => true, 'sort' => 1]);

    $payload = app(JobPublicProfile::class)->payload($job->refresh());

    expect($payload)->toMatchArray([
        'company'          => $job->company->name,
        'status'           => 'unlisted',
        'title'            => 'Fleet Dispatcher',
        'slug'             => 'fleet-dispatcher',
        'location'         => ['city' => 'Karachi', 'country' => 'PK', 'type' => 'on_site'],
        'employment_type'  => 'FULL_TIME',
        'salary'           => ['min' => null, 'max' => null, 'currency' => null, 'period' => 'MONTH', 'visible' => false],
        'experience_years' => 2,
        'valid_through'    => now()->addMonth()->toDateString(),
    ])
        ->and($payload['description_html'])->toBe('<p>Plan the <strong>fleet</strong>.</p>alert(1)')
        ->and($payload['requirements_html'])->toBe('<ul><li>2 years dispatching</li></ul>')
        ->and($payload['questions'])->toHaveCount(1)
        ->and($payload['questions'][0])->toMatchArray(['type' => 'yes_no', 'label' => 'Can you work on-site in Karachi?', 'required' => true])
        ->and($payload)->not->toHaveKeys(['no_of_recruitment', 'recruiter_id', 'expected_employees']);

    $job->update(['salary_visible' => true]);

    expect(app(JobPublicProfile::class)->payload($job->refresh())['salary'])
        ->toBe(['min' => 120000.0, 'max' => 160000.0, 'currency' => 'PKR', 'period' => 'MONTH', 'visible' => true]);
});

it('produces Google JobPosting data for on-site and remote jobs', function () {
    ['job' => $job] = websiteJobFixture([
        'location_type'  => JobLocationType::ON_SITE,
        'date_to'        => now()->addWeeks(2),
        'salary_min'     => 100000,
        'salary_visible' => true,
    ]);

    $onSite = app(JobPublicProfile::class)->structuredData($job->refresh(), 'https://example.test/careers/fleet-dispatcher');

    expect($onSite)->toMatchArray([
        '@context'     => 'https://schema.org/',
        '@type'        => 'JobPosting',
        'title'        => 'Fleet Dispatcher',
        'validThrough' => now()->addWeeks(2)->toDateString(),
        'url'          => 'https://example.test/careers/fleet-dispatcher',
    ])
        ->and($onSite['jobLocation']['address'])->toMatchArray(['addressLocality' => 'Karachi', 'addressCountry' => 'PK'])
        ->and($onSite['baseSalary']['value'])->toMatchArray(['minValue' => 100000.0, 'unitText' => 'MONTH'])
        ->and($onSite)->not->toHaveKey('jobLocationType');

    $job->update(['location_type' => JobLocationType::REMOTE, 'salary_visible' => false]);
    $remote = app(JobPublicProfile::class)->structuredData($job->refresh());

    expect($remote['jobLocationType'])->toBe('TELECOMMUTE')
        ->and($remote['applicantLocationRequirements'])->toMatchArray(['@type' => 'Country', 'name' => 'PK'])
        ->and($remote)->not->toHaveKeys(['jobLocation', 'baseSalary']);
});

it('lists what a job still needs before the website and Google accept it', function () {
    ['job' => $job] = websiteJobFixture(['description' => '', 'address_id' => null]);
    $profile = app(JobPublicProfile::class);

    expect($profile->missingForWebsite($job))->toHaveCount(3);

    $job->update(['location_type' => JobLocationType::ON_SITE, 'salary_visible' => true]);
    expect($profile->missingForWebsite($job->refresh()))->toContain(
        'A job description.',
        'A work address with a city (on-site and hybrid jobs).',
        'An "apply before" date (Google requires it).',
        'A salary amount (the salary is set to be shown).',
    );

    ['job' => $ready] = websiteJobFixture(['location_type' => JobLocationType::REMOTE, 'valid_through' => now()->addMonth()->toDateString()]);
    expect($profile->missingForWebsite($ready))->toBe([]);
});

it('shows HR a read-only website preview on the job page', function () {
    ['company' => $company, 'job' => $job] = websiteJobFixture(['location_type' => JobLocationType::HYBRID]);
    $recruiter = jobOpeningRoleUser($company, 'recruiter');
    Auth::login($recruiter);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(ViewJobPosition::class, ['record' => $job->getRouteKey()])
        ->assertActionVisible('websitePreview')
        ->mountAction('websitePreview')
        ->assertActionMounted('websitePreview');
});
