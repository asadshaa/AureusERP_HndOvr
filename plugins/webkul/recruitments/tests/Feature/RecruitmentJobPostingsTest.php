<?php

/**
 * Recruitment Stage 2.3 -- job ads on many channels (Zoho-style multi-posting),
 * docs/RECRUITMENT_PLUGIN_STATUS_AND_INTEGRATION_ROADMAP.md §15.3:
 * one posting per job x channel x placement, kept in step with the job's
 * lifecycle, assisted manual posting with take-down confirmation, tracked
 * apply links, attribution of CVs to the ad they came from, and the
 * company/permission/audit guards around all of it.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ListApplicants;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages\ListJobPositions;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages\ViewJobPosition;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\RelationManagers\JobPostingsRelationManager;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobPostingEvent;
use Webkul\Recruitment\Services\ApplicantIntakeService;
use Webkul\Recruitment\Services\CandidateApplicationService;
use Webkul\Recruitment\Services\JobOpeningPublicationService;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingLifecycle;
use Webkul\Recruitment\Services\JobPostingLinks;
use Webkul\Recruitment\Services\JobPostingService;
use Webkul\Recruitment\Services\JobPostingTextBuilder;
use Webkul\Recruitment\Services\LegacyPostingChannelBackfill;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UTMSource;

require_once __DIR__.'/Support/JobPostingHelpers.php';

beforeEach(function () {
    app()->forgetInstance(JobPostingChannelRegistry::class);
});

function postingNotifications(User $user, string $titleFragment): int
{
    return $user->notifications()->get()->filter(fn ($notification): bool => str_contains((string) ($notification->data['title'] ?? ''), $titleFragment))->count();
}

// ---------------------------------------------------------------------
// Data model and guards
// ---------------------------------------------------------------------

it('adds a channel as a planned ad with an unguessable token, the job\'s company and its UTM source', function () {
    $f = jobPostingFixture();
    $linkedin = UTMSource::query()->firstOrCreate(['name' => 'LinkedIn']);

    jobPostingAdd($f, ['linkedin']);
    $posting = jobPostingFor($f['job'], 'linkedin');

    expect($posting->status)->toBe(ChannelPostingStatus::PLANNED)
        ->and($posting->token)->toMatch('/^[0-9a-f]{32}$/')
        ->and($posting->company_id)->toBe($f['company']->id)
        ->and($posting->utm_source_id)->toBe(UTMSource::query()->where('name', 'LinkedIn')->orderBy('id')->value('id'))
        ->and($posting->creator_id)->toBe($f['manager']->id)
        ->and($posting->events()->sole()->only(['event', 'actor_id', 'origin']))->toBe(['event' => 'added', 'actor_id' => $f['manager']->id, 'origin' => 'user']);

    // A second job reuses the same new UTM source instead of creating another.
    jobPostingAdd($f, ['rozee']);
    $other = JobPosition::query()->create(['company_id' => $f['company']->id, 'name' => 'Driver', 'is_active' => true]);
    app(JobPostingService::class)->addChannels($other, [['channel' => 'rozee']], $f['manager']);

    expect(UTMSource::query()->where('name', 'Rozee.pk')->count())->toBe(1)
        ->and($linkedin->exists)->toBeTrue();
});

it('refuses ads for a job without a company and never lets an ad belong to another company', function () {
    $f = jobPostingFixture();
    $companyless = JobPosition::query()->create(['name' => 'Floating job', 'is_active' => true]);

    expect(fn () => app(JobPostingService::class)->addChannels($companyless, [['channel' => 'linkedin']], $f['manager']))
        ->toThrow(RuntimeException::class);

    jobPostingAdd($f, ['linkedin']);
    $posting = jobPostingFor($f['job'], 'linkedin');
    $other = Company::factory()->create();

    expect(fn () => $posting->forceFill(['company_id' => $other->id])->save())->toThrow(RuntimeException::class, 'cannot be changed');
});

it('keeps one ad per channel and placement, enforces placements, conflicts and companion channels', function () {
    $f = jobPostingFixture();

    jobPostingAdd($f, ['linkedin', 'linkedin']);
    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => 'Karachi drivers']]);
    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => '  karachi   drivers ']]);
    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => 'Lahore drivers']]);

    expect(JobPosting::query()->where('job_id', $f['job']->id)->where('channel', 'linkedin')->count())->toBe(1)
        ->and(JobPosting::query()->where('job_id', $f['job']->id)->where('channel', 'whatsapp')->count())->toBe(2);

    expect(fn () => jobPostingAdd($f, ['whatsapp']))->toThrow(ValidationException::class);
    expect(fn () => jobPostingAdd($f, [['channel' => 'rozee', 'placement' => 'Somewhere']]))->toThrow(ValidationException::class);
    expect(fn () => jobPostingAdd($f, ['indeed', 'indeed_feed']))->toThrow(RuntimeException::class, 'would list the job twice');
    expect(fn () => jobPostingAdd($f, ['no_such_board']))->toThrow(RuntimeException::class, 'Unknown job posting channel');

    jobPostingAdd($f, ['google_jobs']);
    expect(JobPosting::query()->where('job_id', $f['job']->id)->pluck('channel')->all())->toContain('google_jobs', 'careers_site');
});

it('refuses illegal status moves and tier rules on the model itself', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'careers_site']);
    $linkedin = jobPostingFor($f['job'], 'linkedin');
    $careers = jobPostingFor($f['job'], 'careers_site');

    expect(fn () => $linkedin->forceFill(['status' => ChannelPostingStatus::LIVE, 'posted_at' => now(), 'live_url' => 'https://linkedin.com/jobs/1'])->save())
        ->toThrow(RuntimeException::class, 'cannot move from Planned to Live');
    expect(fn () => $linkedin->refresh()->forceFill(['status' => ChannelPostingStatus::WAITING])->save())
        ->toThrow(RuntimeException::class, 'never waits');
    expect(fn () => $careers->forceFill(['status' => ChannelPostingStatus::TO_POST])->save())
        ->toThrow(RuntimeException::class, 'never posted or taken down by hand');
    expect(fn () => $linkedin->refresh()->forceFill(['token' => str_repeat('a', 32)])->save())
        ->toThrow(RuntimeException::class, 'cannot be changed');
    expect(fn () => $linkedin->refresh()->forceFill(['channel' => 'rozee'])->save())
        ->toThrow(RuntimeException::class, 'cannot be changed');
});

// ---------------------------------------------------------------------
// Lifecycle: the ads follow the job
// ---------------------------------------------------------------------

it('turns planned ads into "to post" and "waiting" when the job is published, and tells the recruiter once', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee', 'careers_site', 'indeed_feed']);

    jobPostingPublish($f);

    expect(jobPostingFor($f['job'], 'linkedin')->status)->toBe(ChannelPostingStatus::TO_POST)
        ->and(jobPostingFor($f['job'], 'rozee')->status)->toBe(ChannelPostingStatus::TO_POST)
        ->and(jobPostingFor($f['job'], 'careers_site')->status)->toBe(ChannelPostingStatus::WAITING)
        ->and(jobPostingFor($f['job'], 'indeed_feed')->status)->toBe(ChannelPostingStatus::WAITING)
        ->and(jobPostingFor($f['job'], 'linkedin')->events()->latest('id')->first()->only(['event', 'origin', 'actor_id']))
        ->toBe(['event' => 'activated', 'origin' => 'job_lifecycle', 'actor_id' => $f['chain']['recruiter']->id])
        ->and(postingNotifications($f['manager'], 'Post 2 job ad(s)'))->toBe(1);
});

it('leaves manual ads alone on pause and resume, and replaying publish changes nothing', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    jobPostingPublish($f);
    $posting = app(JobPostingService::class)->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://www.linkedin.com/jobs/view/123');
    $eventsBefore = $posting->events()->count();

    $service = app(JobOpeningPublicationService::class);
    $service->pause($f['job']->refresh(), $f['chain']['recruiter']);
    $service->publish($f['job']->refresh(), $f['chain']['recruiter']);

    expect($posting->refresh()->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($posting->events()->count())->toBe($eventsBefore);

    DB::transaction(function () use ($f): void {
        $locked = JobPosition::query()->whereKey($f['job']->id)->lockForUpdate()->first();
        expect(app(JobPostingLifecycle::class)->onJobPublished($locked, null)->isEmpty())->toBeTrue();
    });
});

it('rolls the job back too when its ads cannot be updated', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee']);
    // An ad that no longer matches its job's company cannot be saved, so activating it fails.
    DB::table('recruitments_job_postings')->where('job_id', $f['job']->id)->where('channel', 'rozee')->update(['company_id' => Company::factory()->create()->id]);
    jobPostingApprove($f);

    expect(fn () => app(JobOpeningPublicationService::class)->publish($f['job']->refresh(), $f['chain']['recruiter']))
        ->toThrow(RuntimeException::class);

    expect($f['job']->refresh()->postingStatus())->toBe(JobPostingStatus::APPROVED)
        ->and(jobPostingFor($f['job'], 'linkedin')->status)->toBe(ChannelPostingStatus::PLANNED);
});

it('asks for live manual ads to be taken down when the job closes, once, and closes the rest', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee', 'bayt', 'careers_site']);
    jobPostingPublish($f);
    $service = app(JobPostingService::class);
    $service->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/1');
    $service->markLive(jobPostingFor($f['job'], 'rozee'), $f['manager'], 'https://www.rozee.pk/job/2');

    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);

    $linkedin = jobPostingFor($f['job'], 'linkedin');
    $rozee = jobPostingFor($f['job'], 'rozee');
    expect($linkedin->status)->toBe(ChannelPostingStatus::REMOVAL_PENDING)
        ->and($rozee->status)->toBe(ChannelPostingStatus::REMOVAL_PENDING)
        ->and($linkedin->removal_requested_at->equalTo($rozee->removal_requested_at))->toBeTrue()
        ->and(jobPostingFor($f['job'], 'bayt')->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and(jobPostingFor($f['job'], 'bayt')->closedReasonLabel())->toBe('Not posted')
        ->and(jobPostingFor($f['job'], 'careers_site')->status)->toBe(ChannelPostingStatus::CLOSED);

    $notice = $f['manager']->notifications()->get()->first(fn ($n): bool => str_contains((string) ($n->data['title'] ?? ''), 'Take down 2 job ad(s)'));
    expect($notice)->not->toBeNull()
        ->and($notice->data['body'])->toContain('Not recorded as posted: Bayt');

    expect(fn () => app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']))
        ->toThrow(RuntimeException::class, 'cannot be closed');
    expect(postingNotifications($f['manager'], 'Take down'))->toBe(1);
});

it('closes every ad without any notice when an approved job closes before publishing', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'careers_site']);
    jobPostingApprove($f);

    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);

    expect(JobPosting::query()->where('job_id', $f['job']->id)->pluck('status')->unique()->all())->toBe([ChannelPostingStatus::CLOSED])
        ->and(postingNotifications($f['manager'], 'Take down'))->toBe(0);
});

it('plans closed ads again on reopen with the same token, keeps removed channels and pending take-downs', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee', 'bayt']);
    jobPostingPublish($f);
    $service = app(JobPostingService::class);
    $service->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/1');
    $service->markLive(jobPostingFor($f['job'], 'rozee'), $f['manager'], 'https://rozee.pk/job/2');
    $service->removeChannel(jobPostingFor($f['job'], 'bayt'), $f['manager']);
    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);
    $service->markRemoved(jobPostingFor($f['job'], 'linkedin'), $f['manager']);
    $token = jobPostingFor($f['job'], 'linkedin')->token;

    app(JobOpeningPublicationService::class)->reopen($f['job']->refresh(), $f['chain']['recruiter']);

    $linkedin = jobPostingFor($f['job'], 'linkedin');
    expect($linkedin->status)->toBe(ChannelPostingStatus::PLANNED)
        ->and($linkedin->token)->toBe($token)
        ->and($linkedin->live_url)->toBeNull()
        ->and($linkedin->posted_at)->toBeNull()
        ->and($linkedin->events()->latest('id')->first()->details['previous_live_url'])->toBe('https://linkedin.com/jobs/view/1')
        ->and(jobPostingFor($f['job'], 'bayt')->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and(jobPostingFor($f['job'], 'rozee')->status)->toBe(ChannelPostingStatus::REMOVAL_PENDING);

    // Re-approve and publish: planned ads come back as "to post"; the still-up ad can be kept live,
    // and the recruiter is told about it.
    jobPostingPublish($f);
    expect(jobPostingFor($f['job'], 'linkedin')->status)->toBe(ChannelPostingStatus::TO_POST);
    $notice = $f['manager']->notifications()->get()->first(fn ($n): bool => str_contains((string) ($n->data['body'] ?? ''), 'Still marked for take-down'));
    expect($notice?->data['body'])->toContain('Rozee.pk');

    $rozee = $service->markLive(jobPostingFor($f['job'], 'rozee'), $f['manager'], 'https://rozee.pk/job/2');
    expect($rozee->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($rozee->removal_requested_at)->toBeNull();
});

it('starts a channel added to a published job as "to post", and one added to a paused job on resume', function () {
    $f = jobPostingFixture();
    jobPostingPublish($f);

    jobPostingAdd($f, ['linkedin']);
    expect(jobPostingFor($f['job'], 'linkedin')->status)->toBe(ChannelPostingStatus::TO_POST);

    app(JobOpeningPublicationService::class)->pause($f['job']->refresh(), $f['chain']['recruiter']);
    jobPostingAdd($f, ['rozee']);
    expect(jobPostingFor($f['job'], 'rozee')->status)->toBe(ChannelPostingStatus::PLANNED);

    app(JobOpeningPublicationService::class)->publish($f['job']->refresh(), $f['chain']['recruiter']);
    expect(jobPostingFor($f['job'], 'rozee')->status)->toBe(ChannelPostingStatus::TO_POST);
});

// ---------------------------------------------------------------------
// Recruiter actions and validation
// ---------------------------------------------------------------------

it('validates the ad link when marking a manual ad as posted', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', ['channel' => 'whatsapp', 'placement' => 'Drivers group']]);
    jobPostingPublish($f);
    $service = app(JobPostingService::class);
    $linkedin = jobPostingFor($f['job'], 'linkedin');

    foreach ([null, 'javascript:alert(1)', 'https://facebook.com/jobs/1', app(JobPostingLinks::class)->applyUrl($linkedin)] as $badUrl) {
        expect(fn () => $service->markLive($linkedin->refresh(), $f['manager'], $badUrl))->toThrow(ValidationException::class);
    }
    expect(fn () => $service->markLive($linkedin->refresh(), $f['manager'], 'https://linkedin.com/jobs/1', now()->addDay()))
        ->toThrow(ValidationException::class);

    // WhatsApp has no ad URL to give.
    $whatsapp = $service->markLive(jobPostingFor($f['job'], 'whatsapp', 'Drivers group'), $f['manager'], null);
    expect($whatsapp->status)->toBe(ChannelPostingStatus::LIVE);

    $live = $service->markLive($linkedin->refresh(), $f['manager'], 'https://www.linkedin.com/jobs/view/42');
    $postedAt = $live->posted_at;
    expect($live->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($live->posted_by)->toBe($f['manager']->id)
        ->and($live->apply_url)->toBeNull();

    $updated = $service->markLive($live, $f['manager'], 'https://www.linkedin.com/jobs/view/43');
    expect($updated->live_url)->toBe('https://www.linkedin.com/jobs/view/43')
        ->and($updated->posted_at->equalTo($postedAt))->toBeTrue()
        ->and($updated->events()->latest('id')->first()->event)->toBe('link_updated');
});

it('only marks a manual ad as posted while the job is published and the ad is due', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'careers_site']);
    $service = app(JobPostingService::class);

    // Draft job: the ad is still planned.
    expect(fn () => $service->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/1'))
        ->toThrow(RuntimeException::class, 'only be marked as posted');

    jobPostingPublish($f);
    expect(fn () => $service->markLive(jobPostingFor($f['job'], 'careers_site'), $f['manager'], 'https://example.com/x'))
        ->toThrow(RuntimeException::class, 'not posted by hand');
});

it('uses the tracked apply link once the careers page is live over HTTPS, and never a localhost link', function () {
    $links = app(JobPostingLinks::class);

    config(['app.url' => 'http://127.0.0.1:8000', 'recruitment.careers.base_url' => null]);
    expect($links->linksAreLive())->toBeFalse();

    Route::get('/careers/apply/{token}', fn () => 'ok')->name(JobPostingLinks::APPLY_ROUTE);
    app('router')->getRoutes()->refreshNameLookups();

    foreach (['http://127.0.0.1:8000', 'https://erp.test', 'https://10.0.0.5', 'https://localhost'] as $url) {
        config(['recruitment.careers.base_url' => $url]);
        expect($links->linksAreLive())->toBeFalse();
    }

    config(['recruitment.careers.base_url' => 'https://careers.example.com']);
    expect($links->linksAreLive())->toBeTrue();

    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    jobPostingPublish($f);
    $posting = app(JobPostingService::class)->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/7');

    expect($posting->apply_url)->toBe("https://careers.example.com/careers/apply/{$posting->token}")
        ->and(app(JobPostingTextBuilder::class)->build($posting)->fullText)->toContain("Apply here: https://careers.example.com/careers/apply/{$posting->token}");
});

it('confirms take-downs once, early or after the job closes', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee']);
    jobPostingPublish($f);
    $service = app(JobPostingService::class);
    $service->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/1');
    $service->markLive(jobPostingFor($f['job'], 'rozee'), $f['manager'], 'https://rozee.pk/job/1');

    // Early take-down while the job is still published.
    $rozee = $service->markRemoved(jobPostingFor($f['job'], 'rozee'), $f['manager']);
    expect($rozee->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and($rozee->removed_by)->toBe($f['manager']->id)
        ->and($rozee->closedReasonLabel())->toBe('Taken down');

    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);
    $linkedin = $service->markRemoved(jobPostingFor($f['job'], 'linkedin'), $f['manager']);
    $events = $linkedin->events()->count();

    expect($linkedin->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and($linkedin->removed_at)->not->toBeNull();
    expect(fn () => $service->markRemoved($linkedin, $f['manager']))->toThrow(RuntimeException::class);
    expect($linkedin->events()->count())->toBe($events);
});

it('records a posting problem and clears it on retry; automatic channels without a publisher cannot retry', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['rozee']);
    jobPostingPublish($f);
    $service = app(JobPostingService::class);

    $failed = $service->reportProblem(jobPostingFor($f['job'], 'rozee'), $f['manager'], 'Rozee rejected the ad: missing salary range');
    expect($failed->status)->toBe(ChannelPostingStatus::FAILED)
        ->and($failed->last_error)->toBe('Rozee rejected the ad: missing salary range');

    $retried = $service->retry($failed, $f['manager']);
    expect($retried->status)->toBe(ChannelPostingStatus::TO_POST)
        ->and($retried->last_error)->toBeNull();

    // An automatic channel's problem can only be retried once its publisher exists.
    jobPostingAdd($f, ['careers_site']);
    $careers = jobPostingFor($f['job'], 'careers_site');
    DB::table('recruitments_job_postings')->where('id', $careers->id)->update(['status' => 'failed', 'last_error' => 'x']);
    expect(fn () => $service->retry($careers->refresh(), $f['manager']))->toThrow(RuntimeException::class, 'careers page');
});

it('removes a channel that never went out, refuses one that is live, and revives it when re-added', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['rozee', 'linkedin']);
    jobPostingPublish($f);
    $service = app(JobPostingService::class);
    $token = jobPostingFor($f['job'], 'rozee')->token;

    $dropped = $service->removeChannel(jobPostingFor($f['job'], 'rozee'), $f['manager']);
    expect($dropped->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and($dropped->dropped_at)->not->toBeNull()
        ->and($dropped->closedReasonLabel())->toBe('Removed from job');

    $service->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/1');
    expect(fn () => $service->removeChannel(jobPostingFor($f['job'], 'linkedin'), $f['manager']))
        ->toThrow(RuntimeException::class, 'Take the ad down');

    jobPostingAdd($f, ['rozee']);
    $revived = jobPostingFor($f['job'], 'rozee');
    expect($revived->id)->toBe($dropped->id)
        ->and($revived->token)->toBe($token)
        ->and($revived->dropped_at)->toBeNull()
        ->and($revived->status)->toBe(ChannelPostingStatus::TO_POST);
});

it('posts a taken-down ad again with the same link, and flags an unrecorded ad of a closed job as still up', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'bayt']);
    jobPostingPublish($f);
    $service = app(JobPostingService::class);
    $service->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/1');
    $removed = $service->markRemoved(jobPostingFor($f['job'], 'linkedin'), $f['manager']);

    $reposted = $service->repost($removed, $f['manager']);
    expect($reposted->status)->toBe(ChannelPostingStatus::TO_POST)
        ->and($reposted->token)->toBe($removed->token)
        ->and($reposted->posted_at)->toBeNull()
        ->and($reposted->live_url)->toBeNull();

    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);
    $stillUp = $service->reportStillUp(jobPostingFor($f['job'], 'bayt'), $f['manager'], 'https://www.bayt.com/en/job/9');

    expect($stillUp->status)->toBe(ChannelPostingStatus::REMOVAL_PENDING)
        ->and($stillUp->live_url)->toBe('https://www.bayt.com/en/job/9')
        ->and($stillUp->removal_requested_at)->not->toBeNull();
});

// ---------------------------------------------------------------------
// Publisher seam (Stage 3/5): a fake careers-page publisher
// ---------------------------------------------------------------------

it('lists automatic channels through their publisher after commit, records failures, and unlists on pause and close', function () {
    $publisher = new FakeJobPostingPublisher;
    app(JobPostingChannelRegistry::class)->registerPublisher($publisher);

    $f = jobPostingFixture();
    jobPostingAdd($f, ['careers_site']);
    jobPostingPublish($f);

    $careers = jobPostingFor($f['job'], 'careers_site');
    expect($careers->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($careers->live_url)->toBe("https://careers.example.com/jobs/{$f['job']->id}")
        ->and($publisher->synced)->toBe([$careers->id]);

    expect(fn () => app(JobPostingService::class)->markLive($careers, $f['manager'], 'https://example.com'))
        ->toThrow(RuntimeException::class, 'not posted by hand');

    app(JobOpeningPublicationService::class)->pause($f['job']->refresh(), $f['chain']['recruiter']);
    expect($careers->refresh()->status)->toBe(ChannelPostingStatus::WAITING);

    $publisher->fail = true;
    app(JobOpeningPublicationService::class)->publish($f['job']->refresh(), $f['chain']['recruiter']);
    expect($careers->refresh()->status)->toBe(ChannelPostingStatus::FAILED)
        ->and($careers->last_error)->toBe('Careers page is down')
        ->and($f['job']->refresh()->postingStatus())->toBe(JobPostingStatus::PUBLISHED);

    $publisher->fail = false;
    expect(app(JobPostingService::class)->retry($careers, $f['manager'])->status)->toBe(ChannelPostingStatus::LIVE);

    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);
    $careers->refresh();
    expect($careers->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and($careers->removed_at)->not->toBeNull()
        ->and($careers->removed_by)->toBeNull()
        ->and($careers->closedReasonLabel())->toBe('Withdrawn');
});

// ---------------------------------------------------------------------
// Authorisation and company isolation
// ---------------------------------------------------------------------

it('needs hr_manage_job_postings to manage ads, but the job lifecycle still moves them', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    $editorOnly = $f['chain']['recruiter'];  // can edit and publish jobs, but not manage ads
    $service = app(JobPostingService::class);

    expect(fn () => $service->addChannels($f['job']->refresh(), [['channel' => 'rozee']], $editorOnly))->toThrow(RuntimeException::class, 'not allowed');

    jobPostingPublish($f);
    $posting = jobPostingFor($f['job'], 'linkedin');
    expect($posting->status)->toBe(ChannelPostingStatus::TO_POST);

    foreach ([
        fn () => $service->markLive($posting, $editorOnly, 'https://linkedin.com/jobs/view/1'),
        fn () => $service->reportProblem($posting, $editorOnly, 'x'),
        fn () => $service->removeChannel($posting, $editorOnly),
        fn () => $service->repost($posting, $editorOnly),
    ] as $call) {
        expect($call)->toThrow(RuntimeException::class, 'not allowed');
    }

    expect($posting->refresh()->status)->toBe(ChannelPostingStatus::TO_POST);
});

it('refuses a manager from another company even with the permission', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    $outsider = jobPostingManager(Company::factory()->create(['is_active' => true]));

    expect(fn () => app(JobPostingService::class)->addChannels($f['job']->refresh(), [['channel' => 'rozee']], $outsider))
        ->toThrow(RuntimeException::class, 'not allowed');
    expect(fn () => app(JobPostingService::class)->removeChannel(jobPostingFor($f['job'], 'linkedin'), $outsider))
        ->toThrow(RuntimeException::class, 'not allowed');
});

it('gives the seeded recruiter and hiring manager roles the ads permission, but not the HR auditor', function () {
    $company = Company::factory()->create(['is_active' => true]);

    expect(jobOpeningRoleUser($company, 'recruiter')->can(HrPermissions::ManageJobPostings))->toBeTrue()
        ->and(jobOpeningRoleUser($company, 'hiring_manager')->can(HrPermissions::ManageJobPostings))->toBeTrue()
        ->and(jobOpeningRoleUser($company, 'hr_auditor')->can(HrPermissions::ManageJobPostings))->toBeFalse();
});

it('lets a manager mark an ad as posted from the Job ads tab, and keeps the tab read-only for viewers', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    jobPostingPublish($f);
    $posting = jobPostingFor($f['job'], 'linkedin');

    test()->actingAs($f['manager']);
    $tab = fn () => Livewire::test(JobPostingsRelationManager::class, ['ownerRecord' => $f['job']->refresh(), 'pageClass' => ViewJobPosition::class]);

    $tab()->callTableAction('markLive', $posting, data: ['live_url' => ''])
        ->assertHasTableActionErrors(['live_url']);
    $tab()->callTableAction('markLive', $posting, data: ['live_url' => 'https://www.linkedin.com/jobs/view/99'])
        ->assertHasNoTableActionErrors();

    expect($posting->refresh()->status)->toBe(ChannelPostingStatus::LIVE)
        ->and($posting->live_url)->toBe('https://www.linkedin.com/jobs/view/99');

    $viewer = jobOpeningUser($f['company'], 'view_recruitment_job::position', 'view_any_recruitment_job::position');
    test()->actingAs($viewer);
    Livewire::test(JobPostingsRelationManager::class, ['ownerRecord' => $f['job']->refresh(), 'pageClass' => ViewJobPosition::class])
        // Hidden actions cannot be called at all (Filament refuses them); the service refusal is covered above.
        ->assertTableActionHidden('markRemoved', $posting)
        ->assertTableActionHidden('markLive', $posting);

    expect($posting->refresh()->status)->toBe(ChannelPostingStatus::LIVE)
        ->and(JobPostingsRelationManager::canViewForRecord($f['job'], ViewJobPosition::class))->toBeTrue();

    test()->actingAs(jobOpeningUser($f['company']));
    expect(JobPostingsRelationManager::canViewForRecord($f['job'], ViewJobPosition::class))->toBeFalse();
});

it('resolves a tracked link to its own ad only, even after the ad is taken down', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee']);
    $links = app(JobPostingLinks::class);
    $linkedin = jobPostingFor($f['job'], 'linkedin');

    expect($links->resolveToken($linkedin->token)?->id)->toBe($linkedin->id);

    foreach ([str_repeat('0', 32), substr($linkedin->token, 0, 31), strtoupper($linkedin->token), '../x'] as $bad) {
        expect($links->resolveToken($bad))->toBeNull();
    }

    app(JobPostingService::class)->removeChannel($linkedin, $f['manager']);
    expect($links->resolveToken($linkedin->token)?->id)->toBe($linkedin->id);
});

// ---------------------------------------------------------------------
// Attribution: which ad brought the CV
// ---------------------------------------------------------------------

it('attributes an imported application to its ad, keeps the first ad, and refuses another job\'s ad', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee']);
    $linkedin = jobPostingFor($f['job'], 'linkedin');
    $intake = app(ApplicantIntakeService::class);
    $importer = jobOpeningUser($f['company']);

    $payload = [
        'external_application_id' => 'LI-1', 'candidate_name' => 'Ali Raza', 'candidate_email' => 'ali.raza@example.test',
        'job_id'                  => $f['job']->id, 'source_details' => 'LinkedIn export', 'job_posting_id' => $linkedin->id,
    ];
    $applicant = $intake->import('manual', $payload, $importer);

    expect($applicant->job_posting_id)->toBe($linkedin->id)
        ->and($applicant->source_id)->toBe($linkedin->refresh()->utm_source_id)
        ->and($applicant->applicant_properties['source_provenance']['source_origin'])->toBe('posting');

    // Re-import of the same application keeps the first ad.
    $again = $intake->import('manual', [...$payload, 'job_posting_id' => jobPostingFor($f['job'], 'rozee')->id], $importer);
    expect($again->id)->toBe($applicant->id)->and($again->job_posting_id)->toBe($linkedin->id);

    $otherJob = JobPosition::query()->create(['company_id' => $f['company']->id, 'name' => 'Other', 'is_active' => true]);
    expect(fn () => $intake->import('manual', [...$payload, 'external_application_id' => 'LI-2', 'job_id' => $otherJob->id], $importer))
        ->toThrow(RuntimeException::class, 'does not belong to this job');
});

it('records "Came from (ad)" when a recruiter adds a CV by hand, and the model refuses a mismatched ad', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $f = jobPostingFixture();
    jobPostingAdd($f, ['rozee']);
    $rozee = jobPostingFor($f['job'], 'rozee');
    $partner = Partner::query()->create(['name' => 'Sana Khan', 'sub_type' => 'individual', 'company_id' => $f['company']->id]);
    $candidate = Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Sana Khan', 'email_from' => 'sana@example.test', 'partner_id' => $partner->id]);

    test()->actingAs(jobOpeningRoleUser($f['company'], 'recruiter'));
    Livewire::test(ListApplicants::class)
        ->callAction('create', data: ['candidate_id' => $candidate->id, 'job_id' => $f['job']->id, 'job_posting_id' => $rozee->id])
        ->assertHasNoActionErrors();

    $applicant = Applicant::query()->where('candidate_id', $candidate->id)->sole();
    expect($applicant->job_posting_id)->toBe($rozee->id)
        ->and($applicant->source_details)->toBe('Rozee.pk')
        ->and($applicant->jobPosting->displayName())->toBe('Rozee.pk');

    $otherJob = JobPosition::query()->create(['company_id' => $f['company']->id, 'name' => 'Other', 'is_active' => true]);
    expect(fn () => $applicant->update(['job_id' => $otherJob->id]))
        ->toThrow(InvalidArgumentException::class, 'different job or company');
    expect(fn () => app(CandidateApplicationService::class)->applyToJob($candidate, $otherJob, Auth::user(), $rozee))
        ->toThrow(RuntimeException::class, 'does not belong to this job');
});

// ---------------------------------------------------------------------
// Deletion and company guards (both job models)
// ---------------------------------------------------------------------

it('refuses to delete a job whose ads may be up, through either job model, and closes the rest on delete', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee']);
    jobPostingPublish($f);
    app(JobPostingService::class)->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/1');

    expect($f['job']->refresh()->delete())->toBeFalse()
        ->and(EmployeeJobPosition::query()->find($f['job']->id)->delete())->toBeFalse()
        ->and(JobPosition::query()->find($f['job']->id))->not->toBeNull();

    app(JobPostingService::class)->markRemoved(jobPostingFor($f['job'], 'linkedin'), $f['manager']);
    expect(EmployeeJobPosition::query()->find($f['job']->id)->delete())->toBeTrue();

    $rozee = jobPostingFor($f['job'], 'rozee');
    expect($rozee->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and($rozee->events()->latest('id')->first()->details['reason'])->toBe('job_deleted');
});

it('force deletes a job only when none of its ads ever went out', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    $postingId = jobPostingFor($f['job'], 'linkedin')->id;

    expect($f['job']->refresh()->forceDelete())->toBeTrue()
        ->and(JobPosting::query()->find($postingId))->toBeNull()
        ->and(JobPostingEvent::query()->where('job_posting_id', $postingId)->count())->toBe(0);

    $g = jobPostingFixture();
    jobPostingAdd($g, ['linkedin']);
    jobPostingPublish($g);
    $service = app(JobPostingService::class);
    $service->markLive(jobPostingFor($g['job'], 'linkedin'), $g['manager'], 'https://linkedin.com/jobs/view/1');
    $service->markRemoved(jobPostingFor($g['job'], 'linkedin'), $g['manager']);

    expect($g['job']->refresh()->forceDelete())->toBeFalse()
        ->and(JobPosition::withTrashed()->find($g['job']->id))->not->toBeNull();
});

it('does not let a job with ads move to another company, through either job model', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    $other = Company::factory()->create(['is_active' => true]);

    // A validation error (shown on the company field of either job form), not a server error.
    expect(fn () => $f['job']->refresh()->update(['company_id' => $other->id]))->toThrow(ValidationException::class, 'cannot move to another company');
    expect(fn () => EmployeeJobPosition::query()->find($f['job']->id)->update(['company_id' => $other->id]))->toThrow(ValidationException::class, 'cannot move to another company');

    // Unrelated edits (the form sends company_id back unchanged) still save.
    $f['job']->refresh()->update(['company_id' => $f['company']->id, 'name' => 'Senior Fleet Dispatcher']);
    expect($f['job']->refresh()->name)->toBe('Senior Fleet Dispatcher');
});

// ---------------------------------------------------------------------
// Reminders and the safety sweep
// ---------------------------------------------------------------------

it('reminds about ads still up after the job closed, on the configured cadence, then tells HR once', function () {
    config(['recruitment.postings.removal_reminder_after_days' => 2, 'recruitment.postings.removal_reminder_every_days' => 2, 'recruitment.postings.removal_reminder_max' => 2]);

    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    jobPostingPublish($f);
    app(JobPostingService::class)->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/1');
    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);

    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();
    expect(postingNotifications($f['manager'], 'Reminder'))->toBe(0);

    test()->travel(3)->days();
    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();
    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();
    expect(postingNotifications($f['manager'], 'Reminder 1'))->toBe(1)
        ->and(jobPostingFor($f['job'], 'linkedin')->reminder_count)->toBe(1);

    test()->travel(1)->days();
    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();
    expect(jobPostingFor($f['job'], 'linkedin')->reminder_count)->toBe(1);

    test()->travel(2)->days();
    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();
    $posting = jobPostingFor($f['job'], 'linkedin');
    expect($posting->reminder_count)->toBe(2)
        ->and($posting->events()->where('event', 'escalated')->count())->toBe(1);

    // Maximum reached: no more reminders, no second escalation.
    test()->travel(5)->days();
    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();
    expect(jobPostingFor($f['job'], 'linkedin')->reminder_count)->toBe(2)
        ->and($posting->events()->where('event', 'escalated')->count())->toBe(1);
});

it('sweeps ads of a job closed outside the normal flow, once', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee']);
    jobPostingPublish($f);
    app(JobPostingService::class)->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/1');
    DB::table('employees_job_positions')->where('id', $f['job']->id)->update(['posting_status' => 'closed']);

    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();

    expect(jobPostingFor($f['job'], 'linkedin')->status)->toBe(ChannelPostingStatus::REMOVAL_PENDING)
        ->and(jobPostingFor($f['job'], 'rozee')->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and(jobPostingFor($f['job'], 'linkedin')->events()->latest('id')->first()->origin)->toBe('scheduler');

    $events = JobPostingEvent::query()->count();
    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();
    expect(JobPostingEvent::query()->count())->toBe($events);
});

// ---------------------------------------------------------------------
// Ad text, list page, legacy backfill
// ---------------------------------------------------------------------

it('prepares plain-text ad copy from the job, short for messaging channels, without a localhost link', function () {
    config(['app.url' => 'http://127.0.0.1:8000', 'recruitment.careers.apply_email' => null]);

    $f = jobPostingFixture();
    $f['job']->update(['description' => '<p>Plan daily truck routes &amp; driver shifts.</p><script>alert(1)</script>']);
    jobPostingAdd($f, ['rozee', ['channel' => 'whatsapp', 'placement' => 'Karachi drivers']]);
    jobPostingPublish($f);

    $full = app(JobPostingService::class)->postText(jobPostingFor($f['job'], 'rozee'), $f['manager']);
    expect($full->fullText)->toContain('Fleet Dispatcher', 'Truck It In Test', 'Karachi', 'Full-time', 'Plan daily truck routes & driver shifts.', '• 2 years dispatch experience', 'Apply through this post.')
        ->not->toContain('<script>')
        ->not->toContain('127.0.0.1');

    config(['recruitment.careers.apply_email' => 'careers@example.test']);
    $short = app(JobPostingTextBuilder::class)->build(jobPostingFor($f['job'], 'whatsapp', 'Karachi drivers'));
    expect($short->fullText)->toStartWith("We're hiring: Fleet Dispatcher")
        ->toContain('• Urdu and English', 'email your CV to careers@example.test')
        ->not->toContain('Plan daily truck routes');

    expect(app(JobPostingService::class)->closeImpactSummary($f['job']))->toBeNull();
    app(JobPostingService::class)->markLive(jobPostingFor($f['job'], 'rozee'), $f['manager'], 'https://rozee.pk/job/1');
    expect(app(JobPostingService::class)->closeImpactSummary($f['job']))->toContain('1 manual ad(s) are live (Rozee.pk)');
});

it('converts legacy channel labels into planned ads once, without inventing posting facts', function () {
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['is_active' => true]);
    $draft = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Legacy draft', 'is_active' => true]);
    $published = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Legacy live', 'is_active' => true]);
    $closed = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Legacy closed', 'is_active' => true]);
    DB::table('employees_job_positions')->where('id', $draft->id)->update(['posting_channels' => json_encode(['company_website', 'linkedin', 'referral', 'other'])]);
    DB::table('employees_job_positions')->where('id', $published->id)->update(['posting_status' => 'published', 'posting_channels' => json_encode(['linkedin', 'company_website'])]);
    DB::table('employees_job_positions')->where('id', $closed->id)->update(['posting_status' => 'closed', 'posting_channels' => json_encode(['linkedin'])]);

    $first = app(LegacyPostingChannelBackfill::class)->run();
    $second = app(LegacyPostingChannelBackfill::class)->run();

    expect(JobPosting::query()->where('job_id', $draft->id)->orderBy('channel')->pluck('status', 'channel')->map->value->all())
        ->toBe(['careers_site' => 'planned', 'linkedin' => 'planned', 'other' => 'planned'])
        ->and(jobPostingFor($draft, 'other', 'Legacy')->events()->sole()->details['legacy_value'])->toBe('other')
        ->and(jobPostingFor($published, 'linkedin')->status)->toBe(ChannelPostingStatus::TO_POST)
        ->and(jobPostingFor($published, 'linkedin')->posted_at)->toBeNull()
        ->and(jobPostingFor($published, 'careers_site')->status)->toBe(ChannelPostingStatus::WAITING)
        ->and(JobPosting::query()->where('job_id', $closed->id)->count())->toBe(0)
        ->and($second['created'])->toBe(0)
        ->and($first['skipped_unmapped'])->toBeGreaterThanOrEqual(1)
        ->and(json_decode((string) DB::table('employees_job_positions')->where('id', $draft->id)->value('posting_channels'), true))->toBe(['company_website', 'linkedin', 'referral', 'other']);
});

it('shows the ads and the ads needing action on the job list, and filters jobs whose ads need action', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee']);
    jobPostingPublish($f);
    $quiet = JobPosition::query()->create(['company_id' => $f['company']->id, 'name' => 'No ads yet', 'is_active' => true]);

    $viewer = jobPostingManager($f['company']);
    test()->actingAs($viewer);

    Livewire::test(ListJobPositions::class)
        ->assertCanSeeTableRecords([$f['job'], $quiet])
        ->assertTableColumnStateSet('postings_needing_action_count', 2, $f['job'])
        ->filterTable('ads_need_action')
        ->assertCanSeeTableRecords([$f['job']])
        ->assertCanNotSeeTableRecords([$quiet]);
});

// ---------------------------------------------------------------------
// Review regressions (independent review of Stage 2.3)
// ---------------------------------------------------------------------

it('does not send take-down reminders for a republished job, and a second close restarts them', function () {
    config(['recruitment.postings.removal_reminder_after_days' => 1, 'recruitment.postings.removal_reminder_every_days' => 1, 'recruitment.postings.removal_reminder_max' => 3]);

    $f = jobPostingFixture();
    jobPostingAdd($f, ['rozee']);
    jobPostingPublish($f);
    app(JobPostingService::class)->markLive(jobPostingFor($f['job'], 'rozee'), $f['manager'], 'https://rozee.pk/job/1');
    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);
    test()->travel(2)->days();
    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();
    expect(jobPostingFor($f['job'], 'rozee')->reminder_count)->toBe(1);

    app(JobOpeningPublicationService::class)->reopen($f['job']->refresh(), $f['chain']['recruiter']);
    jobPostingPublish($f);
    test()->travel(3)->days();
    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();
    expect(jobPostingFor($f['job'], 'rozee')->reminder_count)->toBe(1);

    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);
    $rozee = jobPostingFor($f['job'], 'rozee');
    expect($rozee->reminder_count)->toBe(0)
        ->and($rozee->removal_requested_at->isToday())->toBeTrue();
});

it('keeps the daily reminder cadence even when the last reminder was written later in the day', function () {
    config(['recruitment.postings.removal_reminder_after_days' => 2, 'recruitment.postings.removal_reminder_every_days' => 2, 'recruitment.postings.removal_reminder_max' => 5]);

    $f = jobPostingFixture();
    jobPostingAdd($f, ['rozee']);
    jobPostingPublish($f);
    app(JobPostingService::class)->markLive(jobPostingFor($f['job'], 'rozee'), $f['manager'], 'https://rozee.pk/job/1');
    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);

    // Requested and last reminded two days ago, a few seconds *after* this run's time of day.
    $twoDaysAgoLater = now()->subDays(2)->addSeconds(5);
    DB::table('recruitments_job_postings')->where('job_id', $f['job']->id)->update([
        'removal_requested_at' => $twoDaysAgoLater->copy()->subDays(2), 'last_reminded_at' => $twoDaysAgoLater, 'reminder_count' => 1,
    ]);

    test()->artisan('recruitments:remind-posting-removals')->assertSuccessful();

    expect(jobPostingFor($f['job'], 'rozee')->reminder_count)->toBe(2);
});

it('refuses to force delete a job whose ads went out in an earlier cycle, even after reopen cleared the dates', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    jobPostingPublish($f);
    $service = app(JobPostingService::class);
    $service->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/1');
    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);
    $service->markRemoved(jobPostingFor($f['job'], 'linkedin'), $f['manager']);
    app(JobOpeningPublicationService::class)->reopen($f['job']->refresh(), $f['chain']['recruiter']);
    $posting = jobPostingFor($f['job'], 'linkedin');
    expect($posting->posted_at)->toBeNull();

    expect($f['job']->refresh()->delete())->toBeTrue();
    expect(JobPosition::withTrashed()->find($f['job']->id)->forceDelete())->toBeFalse()
        ->and(JobPosting::query()->find($posting->id))->not->toBeNull()
        ->and($posting->events()->where('event', 'marked_live')->exists())->toBeTrue();
});

it('escapes user text in notifications and keeps angle brackets out of placements', function () {
    $f = jobPostingFixture();
    $f['job']->update(['name' => 'Driver <b>urgent</b>']);
    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => '<a href="https://evil.tld">Group</a>']]);
    $posting = JobPosting::query()->where('job_id', $f['job']->id)->where('channel', 'whatsapp')->sole();
    expect($posting->placement)->not->toContain('<')->not->toContain('>');

    jobPostingPublish($f);
    $notice = $f['manager']->notifications()->get()->first(fn ($n): bool => str_contains((string) ($n->data['title'] ?? ''), 'Post 1 job ad(s)'));

    expect($notice->data['title'])->toContain('&lt;b&gt;urgent&lt;/b&gt;')->not->toContain('<b>')
        ->and($notice->data['body'])->not->toContain('<a ');
});

it('previews the source of an ad without writing, and keeps a removed ad selectable for its applicants', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['rozee']);
    $rozee = jobPostingFor($f['job'], 'rozee');
    DB::table('recruitments_job_postings')->where('id', $rozee->id)->update(['utm_source_id' => null]);
    $updatedAt = DB::table('recruitments_job_postings')->where('id', $rozee->id)->value('updated_at');

    expect(app(JobPostingLinks::class)->peekSourceId($rozee->refresh()))->toBe(UTMSource::query()->where('name', 'Rozee.pk')->orderBy('id')->value('id'))
        ->and($rozee->refresh()->utm_source_id)->toBeNull()
        ->and(DB::table('recruitments_job_postings')->where('id', $rozee->id)->value('updated_at'))->toBe($updatedAt);

    app(JobPostingService::class)->removeChannel($rozee, $f['manager']);
    expect(JobPosting::optionsForJob($f['job']->id, $f['company']->id))->not->toHaveKey($rozee->id)
        ->and(JobPosting::optionsForJob($f['job']->id, $f['company']->id, $rozee->id))->toHaveKey($rozee->id);
});

it('keeps source and provenance consistent with the kept ad on re-import, and lets a re-import move to another job', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin', 'rozee']);
    $linkedin = jobPostingFor($f['job'], 'linkedin');
    $intake = app(ApplicantIntakeService::class);
    $importer = jobOpeningUser($f['company']);
    $payload = ['external_application_id' => 'EXT-9', 'candidate_name' => 'Hina Ali', 'candidate_email' => 'hina@example.test', 'job_id' => $f['job']->id, 'source_details' => 'Export', 'job_posting_id' => $linkedin->id];

    $intake->import('manual', $payload, $importer);
    $again = $intake->import('manual', [...$payload, 'job_posting_id' => jobPostingFor($f['job'], 'rozee')->id], $importer);

    expect($again->job_posting_id)->toBe($linkedin->id)
        ->and($again->source_id)->toBe($linkedin->refresh()->utm_source_id)
        ->and($again->applicant_properties['source_provenance']['channel'])->toBe('linkedin');

    $otherJob = JobPosition::query()->create(['company_id' => $f['company']->id, 'name' => 'Other', 'is_active' => true]);
    $moved = $intake->import('manual', [...$payload, 'job_id' => $otherJob->id, 'job_posting_id' => null], $importer);

    expect($moved->job_id)->toBe($otherJob->id)
        ->and($moved->job_posting_id)->toBeNull()
        ->and($moved->applicant_properties['source_provenance']['previous_job_posting_id'])->toBe($linkedin->id);
});

it('treats groups that differ only by an emoji or an accent as different groups', function () {
    $f = jobPostingFixture();

    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => 'Karachi Jobs 🚚']]);
    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => 'Karachi Jobs 💼']]);
    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => 'Lahore']]);
    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => 'Lahóre']]);
    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => 'LAHORE']]);

    expect(JobPosting::query()->where('job_id', $f['job']->id)->where('channel', 'whatsapp')->count())->toBe(4);
});

it('reopens a job whose closed ad uses a channel that no longer exists, dropping that ad', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['bayt', 'rozee']);
    jobPostingApprove($f);
    app(JobOpeningPublicationService::class)->close($f['job']->refresh(), $f['chain']['recruiter']);
    DB::table('recruitments_job_postings')->where('job_id', $f['job']->id)->where('channel', 'bayt')->update(['channel' => 'retired_board']);

    app(JobOpeningPublicationService::class)->reopen($f['job']->refresh(), $f['chain']['recruiter']);

    $retired = JobPosting::query()->where('job_id', $f['job']->id)->where('channel', 'retired_board')->sole();
    expect($f['job']->refresh()->postingStatus())->toBe(JobPostingStatus::DRAFT)
        ->and($retired->status)->toBe(ChannelPostingStatus::CLOSED)
        ->and($retired->dropped_at)->not->toBeNull()
        ->and(jobPostingFor($f['job'], 'rozee')->status)->toBe(ChannelPostingStatus::PLANNED);
});

it('refuses ad actions on a deleted job, and brings its ads back when the job is restored', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['rozee', 'linkedin']);
    jobPostingPublish($f);
    $f['job']->refresh()->delete();
    $service = app(JobPostingService::class);

    expect(jobPostingFor($f['job'], 'rozee')->status)->toBe(ChannelPostingStatus::CLOSED);
    expect(fn () => $service->repost(jobPostingFor($f['job'], 'rozee'), $f['manager']))->toThrow(RuntimeException::class);

    JobPosition::withTrashed()->find($f['job']->id)->restore();

    expect(jobPostingFor($f['job'], 'rozee')->status)->toBe(ChannelPostingStatus::TO_POST)
        ->and(jobPostingFor($f['job'], 'linkedin')->status)->toBe(ChannelPostingStatus::TO_POST)
        ->and(jobPostingFor($f['job'], 'rozee')->events()->latest('id')->first()->event)->toBe('restored');
});

it('keeps the facts of earlier cycles in the history when an ad is posted again', function () {
    $f = jobPostingFixture();
    jobPostingAdd($f, ['linkedin']);
    jobPostingPublish($f);
    $service = app(JobPostingService::class);
    $live = $service->markLive(jobPostingFor($f['job'], 'linkedin'), $f['manager'], 'https://linkedin.com/jobs/view/5');
    expect($live->events()->where('event', 'marked_live')->sole()->details['live_url'])->toBe('https://linkedin.com/jobs/view/5');

    $removed = $service->markRemoved($live, $f['manager']);
    $reposted = $service->repost($removed, $f['manager']);

    $details = $reposted->events()->where('event', 'reposted')->sole()->details;
    expect($details['previous_live_url'])->toBe('https://linkedin.com/jobs/view/5')
        ->and($details['previous_posted_by'])->toBe($f['manager']->id)
        ->and($details)->toHaveKeys(['previous_posted_at', 'previous_removed_at']);
});

it('unlists possibly-listed automatic ads on pause and will not drop one Aureus may still be listing', function () {
    $publisher = new FakeJobPostingPublisher;
    app(JobPostingChannelRegistry::class)->registerPublisher($publisher);

    $f = jobPostingFixture();
    jobPostingAdd($f, ['careers_site']);
    $publisher->fail = true;
    jobPostingPublish($f);
    $careers = jobPostingFor($f['job'], 'careers_site');
    expect($careers->status)->toBe(ChannelPostingStatus::FAILED)
        ->and($careers->events()->where('event', 'publisher_failed')->sole()->details['error'])->toBe('Careers page is down');

    expect(fn () => app(JobPostingService::class)->removeChannel($careers, $f['manager']))
        ->toThrow(RuntimeException::class, 'may still be listing');

    $publisher->fail = false;
    $publisher->synced = [];
    app(JobOpeningPublicationService::class)->pause($f['job']->refresh(), $f['chain']['recruiter']);

    expect($publisher->synced)->toBe([$careers->id])
        ->and($careers->refresh()->status)->toBe(ChannelPostingStatus::WAITING)
        ->and($careers->last_error)->toBeNull();
});

it('warns instead of claiming success when the group or website is already on the job', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $f = jobPostingFixture();
    jobPostingAdd($f, [['channel' => 'whatsapp', 'placement' => 'Drivers']]);
    test()->actingAs($f['manager']);

    Livewire::test(JobPostingsRelationManager::class, ['ownerRecord' => $f['job']->refresh(), 'pageClass' => ViewJobPosition::class])
        ->callTableAction('addPlacement', data: ['channel' => 'whatsapp', 'placement' => 'drivers'])
        ->assertNotified('Could not update the job ad');

    expect(JobPosting::query()->where('job_id', $f['job']->id)->count())->toBe(1);
});
