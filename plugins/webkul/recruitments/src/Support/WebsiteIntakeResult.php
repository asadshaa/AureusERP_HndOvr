<?php

namespace Webkul\Recruitment\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\WebsiteConnection;

/** What one pull (or one Retry) of a careers website did. */
final class WebsiteIntakeResult
{
    public const IMPORTED = 'imported';

    public const ALREADY_IMPORTED = 'already_imported';

    public const ALREADY_APPLIED = 'already_applied';

    public const FAILED = 'failed';

    public const WAITING_FOR_HR = 'waiting_for_hr';

    public const DISMISSED = 'dismissed';

    /** @var array<string, int> */
    public array $counts = [
        self::IMPORTED         => 0,
        self::ALREADY_IMPORTED => 0,
        self::ALREADY_APPLIED  => 0,
        self::FAILED           => 0,
        self::WAITING_FOR_HR   => 0,
        self::DISMISSED        => 0,
    ];

    /** Acknowledgements the website did not accept (sent again on the next pull). */
    public int $ackFailures = 0;

    /** @var EloquentCollection<int, Applicant> */
    public EloquentCollection $created;

    /** The website could not be read at all (connection, credentials...). */
    public ?string $error = null;

    /** Another pull of the same website was running. */
    public bool $busy = false;

    public function __construct(public readonly WebsiteConnection $connection)
    {
        $this->created = new EloquentCollection;
    }

    public function add(string $outcome): string
    {
        $this->counts[$outcome] = ($this->counts[$outcome] ?? 0) + 1;

        return $outcome;
    }

    public function summary(): string
    {
        $site = $this->connection->host();

        if ($this->busy) {
            return "{$site}: another check of this website is running; skipped.";
        }

        if ($this->error !== null) {
            return "{$site}: could not read the applications. {$this->error}";
        }

        $parts = [
            "{$this->counts[self::IMPORTED]} imported",
            "{$this->counts[self::ALREADY_IMPORTED]} already imported",
            "{$this->counts[self::ALREADY_APPLIED]} already applied to the job",
            "{$this->counts[self::FAILED]} failed",
        ];

        if ($this->counts[self::WAITING_FOR_HR] > 0) {
            $parts[] = "{$this->counts[self::WAITING_FOR_HR]} waiting on the Website intake page";
        }

        if ($this->ackFailures > 0) {
            $parts[] = "{$this->ackFailures} not yet acknowledged (sent again next time)";
        }

        return "{$site}: ".implode(', ', $parts).'.';
    }
}
