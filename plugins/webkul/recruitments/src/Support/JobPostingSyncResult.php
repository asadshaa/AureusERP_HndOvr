<?php

namespace Webkul\Recruitment\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Webkul\Recruitment\Models\JobPosting;

/**
 * What one job lifecycle step did to its postings, so the work that must run
 * after commit (publisher sync, notifications) knows exactly which ads changed.
 */
final readonly class JobPostingSyncResult
{
    /**
     * @param  Collection<int, JobPosting>  $toPost  manual ads the recruiter must now post
     * @param  Collection<int, JobPosting>  $removalRequested  manual ads that must now be taken down
     * @param  Collection<int, JobPosting>  $notPosted  manual ads closed before anyone recorded posting them
     * @param  array<int, int>  $publisherPostingIds  automatic/feed postings whose publisher must sync
     * @param  Collection<int, JobPosting>  $stillUp  manual ads still marked for take-down when the job is published again
     */
    public function __construct(
        public Collection $toPost,
        public Collection $removalRequested,
        public Collection $notPosted,
        public array $publisherPostingIds,
        public CarbonImmutable $at,
        public Collection $stillUp = new EloquentCollection,
    ) {}

    public static function empty(?CarbonImmutable $at = null): self
    {
        return new self(new EloquentCollection, new EloquentCollection, new EloquentCollection, [], $at ?? CarbonImmutable::now());
    }

    /** @param array<int, int> $postingIds */
    public static function publisherOnly(array $postingIds): self
    {
        return new self(new EloquentCollection, new EloquentCollection, new EloquentCollection, $postingIds, CarbonImmutable::now());
    }

    public function isEmpty(): bool
    {
        return $this->toPost->isEmpty()
            && $this->removalRequested->isEmpty()
            && $this->notPosted->isEmpty()
            && $this->publisherPostingIds === []
            && $this->stillUp->isEmpty();
    }
}
