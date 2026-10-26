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
    ) {}

    public function isManual(): bool
    {
        return $this->tier === ChannelTier::MANUAL;
    }

    public function allowsPlacement(): bool
    {
        return $this->placementLabel !== null;
    }
}
