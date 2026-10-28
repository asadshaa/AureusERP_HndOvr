<?php

/**
 * Free job boards (Zoho-style "Free job boards"): the feed boards (Indeed,
 * Jooble, talent.com, recruit.net, Expertini, TipTopJob, generic aggregator
 * feed) follow the company website listing with the board's feed URL, the
 * website payload says which feeds the job belongs in ("boards"),
 * applications arriving with ?src={board} are attributed to that board's ad,
 * and the Job boards page keeps each company's registrations. Every HTTP call
 * is faked.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Enums\ChannelTier;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Pages\JobBoards;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\JobBoardRegistration;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingService;
use Webkul\Recruitment\Services\JobPublicProfile;
use Webkul\Recruitment\Services\Website\JobBoardService;
use Webkul\Recruitment\Services\Website\WebsiteDerivedChannelPublisher;
use Webkul\Support\Models\Company;

require_once __DIR__.'/Support/WebsiteHelpers.php';

beforeEach(function (): void {
    Sleep::fake();
});

const JOB_BOARD_FEEDS = [
    'indeed_feed'     => 'indeed',
    'jooble_feed'     => 'jooble',
    'talent_feed'     => 'talent',
    'recruitnet_feed' => 'recruitnet',
    'expertini_feed'  => 'expertini',
    'tiptopjob_feed'  => 'tiptopjob',
    'aggregator_feed' => 'generic',
];

it('offers every free feed board as an automatic channel that needs the company website, and the manual boards with Copy post', function () {
    $registry = app(JobPostingChannelRegistry::class);

    foreach (JOB_BOARD_FEEDS as $key => $board) {
        $channel = $registry->resolve($key);

        expect($channel->tier)->toBe(ChannelTier::FEED)
            ->and($channel->feedBoard)->toBe($board)
            ->and($channel->requires)->toBe(['careers_site'])
            ->and($channel->freeJobBoard)->toBeTrue()
            ->and($channel->description)->not->toBeEmpty()
            ->and($channel->howTo)->not->toBeEmpty()
            ->and($registry->publisherFor($key))->toBeInstanceOf(WebsiteDerivedChannelPublisher::class)
            ->and($registry->channelForFeedBoard($board))->toBe($key);
    }

    foreach (['drjob' => 'Dr.Job', 'postjobfree' => 'Post Job Free', 'linkedin' => 'LinkedIn', 'twine' => 'Twine'] as $key => $label) {
        expect($registry->resolve($key)->isManual())->toBeTrue()
            ->and($registry->resolve($key)->label)->toBe($label)
            ->and($registry->resolve($key)->freeJobBoard)->toBeTrue()
            ->and($registry->publisherFor($key))->toBeNull();
    }

    expect(array_keys($registry->freeJobBoards()))->toBe([
        'indeed_feed', 'jooble_feed', 'talent_feed', 'recruitnet_feed', 'expertini_feed', 'tiptopjob_feed', 'aggregator_feed',
        'linkedin', 'drjob', 'postjobfree', 'twine',
    ]);
});

it('takes the feed boards live with their feed URLs with the website page, and tells the website which feeds list the job', function () {
    $f = jobPostingFixture();
    websiteConnectionFor($f['company']);
    $site = (new FakeWordPressSite)->install();

    $added = jobPostingAdd($f, ['jooble_feed', 'talent_feed', 'aggregator_feed', 'drjob']);
    expect($added->pluck('channel')->all())->toContain('careers_site');

    jobPostingPublish($f);
    $careers = jobPostingFor($f['job'], 'careers_site');

    expect($careers->status)->toBe(ChannelPostingStatus::LIVE)
        ->and(jobPostingFor($f['job'], 'jooble_feed')->status)->toBe(ChannelPostingStatus::LIVE)
        ->and(jobPostingFor($f['job'], 'jooble_feed')->live_url)->toBe('https://careers.example.com/careers/feed/jooble.xml')
        ->and(jobPostingFor($f['job'], 'talent_feed')->live_url)->toBe('https://careers.example.com/careers/feed/talent.xml')
        ->and(jobPostingFor($f['job'], 'aggregator_feed')->live_url)->toBe('https://careers.example.com/careers/feed/generic.xml')
        ->and(jobPostingFor($f['job'], 'drjob')->status)->toBe(ChannelPostingStatus::TO_POST)
        ->and($site->jobs[$careers->token]['boards'])->toBe(['jooble', 'talent', 'generic']);

    // A board added while the job is live: the website learns the new list, the ad goes live.
    jobPostingAdd($f, ['recruitnet_feed']);

    expect($site->jobs[$careers->token]['boards'])->toBe(['jooble', 'talent', 'recruitnet', 'generic'])
        ->and(jobPostingFor($f['job'], 'recruitnet_feed')->status)->toBe(ChannelPostingStatus::LIVE)
        ->and(jobPostingFor($f['job'], 'recruitnet_feed')->live_url)->toBe('https://careers.example.com/careers/feed/recruitnet.xml');
});

it('sends an empty boards list for a job with no feed board, and drops a board taken off the job', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['careers_site', 'jooble_feed', 'expertini_feed']);
    $profile = app(JobPublicProfile::class);

    expect($profile->payload($f['job']->refresh())['boards'])->toBe(['jooble', 'expertini']);

    // Planned (job not published yet): the recruiter can take it off.
    app(JobPostingService::class)->removeChannel(jobPostingFor($f['job'], 'expertini_feed'), $f['manager']);
    expect($profile->payload($f['job']->refresh())['boards'])->toBe(['jooble']);

    $other = jobPostingFixture();
    jobPostingAdd($other, ['careers_site', 'linkedin']);
    expect($profile->payload($other['job']->refresh())['boards'])->toBe([]);
});

it('keeps feed boards waiting without a live website listing', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['tiptopjob_feed']);
    jobPostingPublish($f);

    expect(jobPostingFor($f['job'], 'tiptopjob_feed')->status)->toBe(ChannelPostingStatus::WAITING)
        ->and(app(JobPostingChannelRegistry::class)->availabilityNote('tiptopjob_feed', $f['job']->refresh()))->toContain('TipTopJob feed');
});

it('attributes a website application with ?src={board} to the job\'s ad on that board', function () {
    Queue::fake();
    Storage::fake(CandidateResource::CV_DISK);

    $f = jobPostingFixture();
    websiteConnectionFor($f['company']);
    $site = (new FakeWordPressSite)->install();
    jobPostingAdd($f, ['jooble_feed', 'aggregator_feed']);
    jobPostingPublish($f);
    $token = jobPostingFor($f['job'], 'careers_site')->token;
    Auth::logout();

    $application = fn (int $id, string $src): array => [
        'id'      => $id, 'job_token' => $token, 'ref' => null, 'src' => $src, 'submitted_at' => '2026-11-02T10:15:00Z',
        'name'    => "Applicant {$id}", 'email' => "board{$id}@example.test", 'phone' => null, 'answers' => [],
        'consent' => ['privacy' => true, 'ai_screening' => false], 'cv' => ['filename' => "cv-{$id}.pdf", 'size' => 200, 'mime' => 'application/pdf'],
    ];
    $site->applications = [$application(701, 'jooble'), $application(702, 'generic'), $application(703, 'talent')];
    $site->cvs = [701 => ['contents' => websitePdf()], 702 => ['contents' => websitePdf()], 703 => ['contents' => websitePdf()]];

    test()->artisan('recruitments:pull-website-applications')->assertSuccessful();

    $byId = Applicant::query()->where('company_id', $f['company']->id)->where('external_application_id', 'like', 'website:%')->get()
        ->keyBy(fn (Applicant $applicant): int => (int) str($applicant->external_application_id)->afterLast(':')->toString());

    expect($byId[701]->job_posting_id)->toBe(jobPostingFor($f['job'], 'jooble_feed')->id)
        ->and($byId[701]->source_details)->toBe('Company website via Jooble (XML feed)')
        ->and($byId[702]->job_posting_id)->toBe(jobPostingFor($f['job'], 'aggregator_feed')->id)
        // No talent.com ad on this job: the website ad, with the src kept.
        ->and($byId[703]->job_posting_id)->toBe(jobPostingFor($f['job'], 'careers_site')->id)
        ->and($byId[703]->source_details)->toBe('Company website (src: talent)');
});

it('shows the job boards page only to integration managers, for the active company, and saves registrations there', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $f = jobPostingFixture();
    $other = Company::factory()->create(['is_active' => true]);
    JobBoardRegistration::query()->create(['company_id' => $other->id, 'channel' => 'jooble_feed', 'is_registered' => true, 'registered_at' => '2026-10-01', 'notes' => 'Other company']);

    test()->actingAs(jobPostingManager($f['company']));
    Livewire::test(JobBoards::class)->assertForbidden();

    $manager = websiteIntegrationManager($f['company']);
    test()->actingAs($manager);

    Livewire::test(JobBoards::class)
        ->assertOk()
        ->assertSee('Jooble (XML feed)')
        ->assertSee('Post Job Free')
        ->assertSee('Set up the')
        ->assertDontSee('Other company');

    websiteConnectionFor($f['company']);
    (new FakeWordPressSite)->install();
    jobPostingAdd($f, ['jooble_feed']);
    jobPostingPublish($f);

    Livewire::test(JobBoards::class)
        ->assertSee('https://careers.example.com/careers/feed/jooble.xml')
        ->callAction('editRegistration', data: ['is_registered' => true, 'registered_at' => null, 'notes' => 'Feed sent to Jooble support'], arguments: ['board' => 'jooble_feed'])
        ->assertNotified('Job board saved');

    $registration = JobBoardRegistration::query()->forCompany($f['company']->id)->where('channel', 'jooble_feed')->sole();
    expect($registration->is_registered)->toBeTrue()
        ->and($registration->registered_at->toDateString())->toBe(now()->toDateString())
        ->and($registration->notes)->toBe('Feed sent to Jooble support')
        ->and($registration->updated_by)->toBe($manager->id)
        ->and(JobBoardRegistration::query()->forCompany($other->id)->sole()->notes)->toBe('Other company');

    $jooble = collect(app(JobBoardService::class)->overview($f['company']->id))->firstWhere('key', 'jooble_feed');
    expect($jooble)->toMatchArray([
        'automatic'               => true,
        'feed_url'                => 'https://careers.example.com/careers/feed/jooble.xml',
        'website_active'          => true,
        'is_registered'           => true,
        'jobs_live'               => 1,
        'applications_this_month' => 0,
    ]);
});

it('refuses registrations from users without the permission, for another company or an unknown board', function () {
    $f = jobPostingFixture();
    $other = Company::factory()->create(['is_active' => true]);
    $service = app(JobBoardService::class);
    $manager = websiteIntegrationManager($f['company']);

    expect(fn () => $service->saveRegistration($f['manager'], $f['company']->id, 'jooble_feed', ['is_registered' => true]))->toThrow(RuntimeException::class, 'not allowed')
        ->and(fn () => $service->saveRegistration($manager, $other->id, 'jooble_feed', ['is_registered' => true]))->toThrow(RuntimeException::class, 'not allowed')
        ->and(fn () => $service->saveRegistration($manager, $f['company']->id, 'facebook', ['is_registered' => true]))->toThrow(RuntimeException::class, 'Unknown job board');

    $saved = $service->saveRegistration($manager, $f['company']->id, 'drjob', ['is_registered' => false, 'registered_at' => '2026-01-01', 'notes' => '  ']);
    expect($saved->is_registered)->toBeFalse()
        ->and($saved->registered_at)->toBeNull()
        ->and($saved->notes)->toBeNull()
        ->and(JobBoardRegistration::query()->forCompany($other->id)->exists())->toBeFalse();
});

it('counts the applications each board brought in this month, per company', function () {
    Queue::fake();
    Storage::fake(CandidateResource::CV_DISK);

    $f = jobPostingFixture();
    websiteConnectionFor($f['company']);
    $site = (new FakeWordPressSite)->install();
    jobPostingAdd($f, ['talent_feed']);
    jobPostingPublish($f);
    $token = jobPostingFor($f['job'], 'careers_site')->token;
    Auth::logout();

    $site->applications = [[
        'id'      => 801, 'job_token' => $token, 'ref' => null, 'src' => 'talent', 'submitted_at' => now()->toIso8601String(),
        'name'    => 'Talent Applicant', 'email' => 'talent801@example.test', 'phone' => null, 'answers' => [],
        'consent' => ['privacy' => true, 'ai_screening' => false], 'cv' => ['filename' => 'cv.pdf', 'size' => 200, 'mime' => 'application/pdf'],
    ]];
    $site->cvs = [801 => ['contents' => websitePdf()]];
    test()->artisan('recruitments:pull-website-applications')->assertSuccessful();

    $talent = collect(app(JobBoardService::class)->overview($f['company']->id))->firstWhere('key', 'talent_feed');
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherTalent = collect(app(JobBoardService::class)->overview($otherCompany->id))->firstWhere('key', 'talent_feed');

    expect($talent['applications_this_month'])->toBe(1)
        ->and($talent['jobs_live'])->toBe(1)
        ->and($otherTalent['applications_this_month'])->toBe(0)
        ->and($otherTalent['jobs_live'])->toBe(0)
        ->and($otherTalent['feed_url'])->toBeNull();
});
