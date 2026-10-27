<?php

namespace Webkul\Recruitment\Services;

use InvalidArgumentException;
use Webkul\Recruitment\Contracts\JobPostingPublisher;
use Webkul\Recruitment\Enums\ChannelTier;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Support\JobPostingChannel;

/**
 * Catalogue of the places a job can be advertised (Zoho-style multi-posting),
 * mirroring ApplicantSourceRegistry. Automatic and feed channels only go live
 * once a JobPostingPublisher is registered for them (Stage 3 / 5).
 */
class JobPostingChannelRegistry
{
    /** @var array<string, JobPostingChannel> */
    private array $channels = [];

    /** @var array<string, JobPostingPublisher> */
    private array $publishers = [];

    public function __construct()
    {
        foreach (static::defaults() as $channel) {
            $this->register($channel);
        }
    }

    public function register(JobPostingChannel $channel): void
    {
        $this->channels[$channel->key] = $channel;
    }

    public function resolve(string $key): JobPostingChannel
    {
        return $this->channels[$key]
            ?? throw new InvalidArgumentException("Unknown job posting channel [{$key}].");
    }

    public function has(string $key): bool
    {
        return isset($this->channels[$key]);
    }

    public function labelFor(string $key): string
    {
        return $this->channels[$key]->label ?? "Unknown channel ({$key})";
    }

    /** @return array<string, JobPostingChannel> */
    public function all(): array
    {
        $channels = $this->channels;
        uasort($channels, fn (JobPostingChannel $a, JobPostingChannel $b): int => [$a->sort, $a->label] <=> [$b->sort, $b->label]);

        return $channels;
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return array_map(fn (JobPostingChannel $channel): string => $channel->label, $this->all());
    }

    public function registerPublisher(JobPostingPublisher $publisher): void
    {
        $this->publishers[$publisher->channelKey()] = $publisher;
    }

    public function publisherFor(string $key): ?JobPostingPublisher
    {
        return $this->publishers[$key] ?? null;
    }

    /** Manual channels are always usable; the others need an available publisher. */
    public function canGoLive(string $key, JobPosition $job): bool
    {
        if ($this->has($key) && $this->resolve($key)->isManual()) {
            return true;
        }

        return $this->publisherFor($key)?->isAvailable($job) ?? false;
    }

    public function availabilityNote(string $key, JobPosition $job): ?string
    {
        if ($this->canGoLive($key, $job)) {
            return null;
        }

        return $this->has($key)
            ? ($this->resolve($key)->unavailableReason ?? 'This channel is not available yet.')
            : 'This channel is no longer available.';
    }

    /**
     * Legacy employees_job_positions.posting_channels labels => channel key
     * (null = not an advertising channel, e.g. a referral).
     *
     * @return array<string, string|null>
     */
    public static function legacyKeyMap(): array
    {
        return [
            'company_website' => 'careers_site',
            'linkedin'        => 'linkedin',
            'indeed'          => 'indeed',
            'other'           => 'other',
            'referral'        => null,
            'recruiter'       => null,
            'manual_import'   => null,
            'api'             => null,
        ];
    }

    /** @return array<int, JobPostingChannel> */
    protected static function defaults(): array
    {
        return [
            new JobPostingChannel(
                key: 'careers_site',
                label: 'Company website',
                tier: ChannelTier::AUTOMATIC,
                utmSourceName: 'Careers page',
                unavailableReason: 'Goes live on the company careers page once the website is connected (Recruitment → Configurations → Careers website).',
                sort: 10,
            ),
            new JobPostingChannel(
                key: 'google_jobs',
                label: 'Google for Jobs',
                tier: ChannelTier::AUTOMATIC,
                utmSourceName: 'Google for Jobs',
                requires: ['careers_site'],
                unavailableReason: 'Goes live with the job\'s page on the company website (Google reads the job data there).',
                sort: 20,
            ),
            new JobPostingChannel(
                key: 'indeed_feed',
                label: 'Indeed (XML feed)',
                tier: ChannelTier::FEED,
                utmSourceName: 'Indeed',
                requires: ['careers_site'],
                conflictsWith: ['indeed'],
                unavailableReason: 'Goes live with the job\'s page on the company website, through the website\'s Indeed feed (register the feed with Indeed once).',
                sort: 30,
            ),
            new JobPostingChannel(
                key: 'aggregator_feed',
                label: 'Job aggregators (XML feed)',
                tier: ChannelTier::FEED,
                utmSourceName: 'Job aggregator',
                unavailableReason: 'Needs the Aureus job feed (Stage 5.1).',
                sort: 40,
            ),
            new JobPostingChannel(
                key: 'linkedin',
                label: 'LinkedIn',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'LinkedIn',
                requiresLiveUrl: true,
                liveUrlHosts: ['linkedin.com', 'lnkd.in'],
                placementLabel: 'Post type (e.g. job post, company page)',
                sort: 50,
            ),
            new JobPostingChannel(
                key: 'indeed',
                label: 'Indeed (posted manually)',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'Indeed',
                requiresLiveUrl: true,
                liveUrlHosts: ['indeed.com'],
                conflictsWith: ['indeed_feed'],
                sort: 60,
            ),
            new JobPostingChannel(
                key: 'rozee',
                label: 'Rozee.pk',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'Rozee.pk',
                requiresLiveUrl: true,
                liveUrlHosts: ['rozee.pk'],
                sort: 70,
            ),
            new JobPostingChannel(
                key: 'bayt',
                label: 'Bayt',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'Bayt',
                requiresLiveUrl: true,
                liveUrlHosts: ['bayt.com'],
                sort: 80,
            ),
            new JobPostingChannel(
                key: 'facebook',
                label: 'Facebook',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'Facebook',
                requiresLiveUrl: true,
                liveUrlHosts: ['facebook.com', 'fb.com'],
                placementLabel: 'Page or group',
                shortPost: true,
                sort: 90,
            ),
            new JobPostingChannel(
                key: 'whatsapp',
                label: 'WhatsApp group',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'WhatsApp',
                liveUrlHosts: ['chat.whatsapp.com', 'whatsapp.com'],
                placementLabel: 'Group name',
                requiresPlacement: true,
                shortPost: true,
                removalHint: 'Post a "position filled" message in the group.',
                sort: 100,
            ),
            new JobPostingChannel(
                key: 'other',
                label: 'Other website',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'Other job board',
                requiresLiveUrl: true,
                requiresHttps: true,
                placementLabel: 'Website name',
                requiresPlacement: true,
                sort: 110,
            ),
        ];
    }
}
