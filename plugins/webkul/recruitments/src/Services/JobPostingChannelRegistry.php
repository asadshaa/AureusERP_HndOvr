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

    /**
     * Channels reached through the company website's XML feeds, keyed by channel.
     *
     * @return array<string, JobPostingChannel>
     */
    public function feedBoardChannels(): array
    {
        return array_filter($this->all(), fn (JobPostingChannel $channel): bool => $channel->isFeedBoard());
    }

    /** The channel whose website feed is /careers/feed/{board}.xml (e.g. "generic" => aggregator_feed). */
    public function channelForFeedBoard(string $board): ?string
    {
        foreach ($this->channels as $channel) {
            if ($channel->feedBoard !== null && $channel->feedBoard === $board) {
                return $channel->key;
            }
        }

        return null;
    }

    /**
     * Channels listed on the Job boards page (free feeds and free manual boards).
     *
     * @return array<string, JobPostingChannel>
     */
    public function freeJobBoards(): array
    {
        return array_filter($this->all(), fn (JobPostingChannel $channel): bool => $channel->freeJobBoard);
    }

    /** @return array<string, JobPostingPublisher> */
    public function publishers(): array
    {
        return $this->publishers;
    }

    /**
     * Free aggregators that read the company website's XML feed for them
     * (integrations/careers-site/src/feed.js, FEED_BOARDS). Each follows the
     * website listing like the Indeed feed does.
     *
     * @return array<int, JobPostingChannel>
     */
    protected static function feedBoards(): array
    {
        $boards = [
            ['jooble', 'Jooble', 'https://jooble.org', 'Free listings on Jooble, read from the website\'s Jooble feed.', 'Jooble\'s "For employers" / partners contact form'],
            ['talent', 'talent.com', 'https://www.talent.com/integrations', 'Free organic listings on talent.com, read from the website\'s talent.com feed.', 'talent.com\'s employer integrations page (organic XML feed)'],
            ['recruitnet', 'recruit.net', 'https://www.recruit.net', 'Free listings on recruit.net, read from the website\'s feed.', 'recruit.net\'s "Contact us" / job feed submission'],
            ['expertini', 'Expertini', 'https://www.expertini.com', 'Free listings on Expertini, read from the website\'s feed.', 'Expertini\'s employer / XML feed submission page'],
            ['tiptopjob', 'TipTopJob', 'https://www.tiptopjob.com', 'Free listings on TipTopJob, read from the website\'s feed.', 'TipTopJob\'s recruiter / XML feed page'],
        ];

        return array_map(fn (array $board, int $index): JobPostingChannel => new JobPostingChannel(
            key: "{$board[0]}_feed",
            label: "{$board[1]} (XML feed)",
            tier: ChannelTier::FEED,
            utmSourceName: $board[1],
            requires: ['careers_site'],
            unavailableReason: "Goes live with the job's page on the company website, through the website's {$board[1]} feed (register the feed with {$board[1]} once).",
            sort: 31 + $index,
            description: $board[3],
            icon: 'heroicon-o-rss',
            feedBoard: $board[0],
            freeJobBoard: true,
            boardUrl: $board[2],
            howTo: [
                "Open {$board[4]} and ask them to index the company's job feed.",
                'Give them the feed URL below. The board decides whether to accept it.',
                'Once accepted, the board re-reads the feed itself: nothing more to do per job.',
            ],
        ), $boards, array_keys($boards));
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
                description: 'Free organic Indeed listings, read from the website\'s Indeed feed.',
                icon: 'heroicon-o-rss',
                feedBoard: 'indeed',
                freeJobBoard: true,
                boardUrl: 'https://www.indeed.com/hire',
                howTo: [
                    'Sign in to Indeed for Employers with the company account.',
                    'Ask Indeed (Help → Contact us, "XML feed") to index the feed URL below.',
                    'Indeed reviews the feed; once accepted it re-reads it several times a day.',
                ],
            ),
            ...static::feedBoards(),
            new JobPostingChannel(
                key: 'aggregator_feed',
                label: 'Other job aggregators (generic XML feed)',
                tier: ChannelTier::FEED,
                utmSourceName: 'Job aggregator',
                requires: ['careers_site'],
                unavailableReason: 'Goes live with the job\'s page on the company website, through the website\'s generic job feed (give the feed URL to any aggregator that accepts XML feeds).',
                sort: 40,
                description: 'One Indeed-format feed for any other aggregator that takes XML feeds (e.g. Careerjet, Adzuna, WhatJobs).',
                icon: 'heroicon-o-queue-list',
                feedBoard: 'generic',
                freeJobBoard: true,
                howTo: [
                    'Find the aggregator\'s "submit your job feed" / "XML feed" page or support email.',
                    'Give them the feed URL below (it uses the Indeed XML format).',
                    'Note the aggregator\'s name in the notes here so HR knows who reads the feed.',
                ],
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
                description: 'Free "Basic" job post (one free post at a time) or a company page post; use Copy post.',
                icon: 'heroicon-o-briefcase',
                freeJobBoard: true,
                boardUrl: 'https://www.linkedin.com/talent/post-a-job',
                howTo: [
                    'Open LinkedIn → Post a free job (Basic job post) with the company page.',
                    'Paste the text from Job ads → Copy post; use the tracked apply link as the "external website" apply option.',
                    'Mark the ad as posted with its LinkedIn link.',
                ],
            ),
            new JobPostingChannel(
                key: 'drjob',
                label: 'Dr.Job',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'Dr.Job',
                requiresLiveUrl: true,
                liveUrlHosts: ['drjobpro.com', 'drjobs.ae'],
                sort: 52,
                description: 'Free employer job posts (mainly Gulf countries); use Copy post.',
                icon: 'heroicon-o-briefcase',
                freeJobBoard: true,
                boardUrl: 'https://www.drjobpro.com',
                howTo: [
                    'Create a free employer account on Dr.Job.',
                    'Post the job with the text from Job ads → Copy post (the apply link is tracked).',
                    'Mark the ad as posted with its Dr.Job link.',
                ],
            ),
            new JobPostingChannel(
                key: 'postjobfree',
                label: 'Post Job Free',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'Post Job Free',
                requiresLiveUrl: true,
                liveUrlHosts: ['postjobfree.com'],
                sort: 54,
                description: 'Free job posts on postjobfree.com; use Copy post.',
                icon: 'heroicon-o-briefcase',
                freeJobBoard: true,
                boardUrl: 'https://www.postjobfree.com',
                howTo: [
                    'Create a free employer account on Post Job Free.',
                    'Post the job with the text from Job ads → Copy post.',
                    'Mark the ad as posted with its Post Job Free link.',
                ],
            ),
            new JobPostingChannel(
                key: 'twine',
                label: 'Twine',
                tier: ChannelTier::MANUAL,
                utmSourceName: 'Twine',
                requiresLiveUrl: true,
                liveUrlHosts: ['twine.net'],
                sort: 56,
                description: 'Free job posts for freelance and creative roles on twine.net; use Copy post.',
                icon: 'heroicon-o-briefcase',
                freeJobBoard: true,
                boardUrl: 'https://www.twine.net',
                howTo: [
                    'Create a free client account on Twine.',
                    'Post the job with the text from Job ads → Copy post.',
                    'Mark the ad as posted with its Twine link.',
                ],
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
