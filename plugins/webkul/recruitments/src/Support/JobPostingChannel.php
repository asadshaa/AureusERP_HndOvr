<?php

namespace Webkul\Recruitment\Support;

use Webkul\Recruitment\Enums\ChannelTier;

/**
 * Describes one place a job can be advertised (registered in
 * JobPostingChannelRegistry). A channel with a placement label can be added
 * several times to a job, once per placement (e.g. one row per WhatsApp group).
 */
final readonly class JobPostingChannel
{
    /**
     * @param  array<int, string>  $liveUrlHosts  allowed ad hosts (exact or subdomain); [] = any host
     * @param  array<int, string>  $conflictsWith  channel keys that would list the job twice
     * @param  array<int, string>  $requires  channel keys added automatically with this one
     * @param  ?string  $feedBoard  careers-site feed key (/careers/feed/{board}.xml and ?src={board}) for feed boards
     * @param  bool  $freeJobBoard  listed on Recruitment → Configurations → Job boards
     * @param  ?string  $boardUrl  where the business registers the feed or creates its free account
     * @param  array<int, string>  $howTo  short steps shown on the Job boards page
     */
    public function __construct(
        public string $key,
        public string $label,
        public ChannelTier $tier,
        public string $utmSourceName,
        public bool $requiresLiveUrl = false,
        public array $liveUrlHosts = [],
        public bool $requiresHttps = false,
        public ?string $placementLabel = null,
        public bool $requiresPlacement = false,
        public bool $shortPost = false,
        public array $conflictsWith = [],
        public array $requires = [],
        public ?string $unavailableReason = null,
        public ?string $removalHint = null,
        public int $sort = 100,
        public ?string $description = null,
        public ?string $icon = null,
        public ?string $feedBoard = null,
        public bool $freeJobBoard = false,
        public ?string $boardUrl = null,
        public array $howTo = [],
    ) {}

    public function isManual(): bool
    {
        return $this->tier === ChannelTier::MANUAL;
    }

    /** Reached through the company website's XML feed for this board. */
    public function isFeedBoard(): bool
    {
        return $this->feedBoard !== null;
    }

    public function allowsPlacement(): bool
    {
        return $this->placementLabel !== null;
    }
}
