<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Enums\ChannelTier;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingLinks;
use Webkul\Recruitment\Support\JobPostingChannel;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UTMSource;

/**
 * One job ad on one channel (and optional placement, e.g. one WhatsApp group).
 * Lifecycle columns are written only through transitionTo() by
 * JobPostingLifecycle / JobPostingService; company, job, channel, placement
 * and the tracked-link token never change once the row exists.
 */
class JobPosting extends Model
{
    protected $table = 'recruitments_job_postings';

    protected $fillable = [
        'channel',
        'placement',
        'utm_source_id',
        'creator_id',
    ];

    protected $casts = [
        'status'               => ChannelPostingStatus::class,
        'posted_at'            => 'datetime',
        'removal_requested_at' => 'datetime',
        'removed_at'           => 'datetime',
        'dropped_at'           => 'datetime',
        'last_reminded_at'     => 'datetime',
        'last_error_at'        => 'datetime',
        'reminder_count'       => 'integer',
    ];

    protected $attributes = [
        'status'        => 'planned',
        'placement'     => '',
        'placement_key' => '',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_id')->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function utmSource(): BelongsTo
    {
        return $this->belongsTo(UTMSource::class, 'utm_source_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(JobPostingEvent::class, 'job_posting_id');
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class, 'job_posting_id');
    }

    /** The registered channel, or a manual placeholder when its key was removed from the code. */
    public function definition(): JobPostingChannel
    {
        $registry = app(JobPostingChannelRegistry::class);

        return $registry->has((string) $this->channel)
            ? $registry->resolve((string) $this->channel)
            : new JobPostingChannel(
                key: (string) $this->channel,
                label: $registry->labelFor((string) $this->channel),
                tier: ChannelTier::MANUAL,
                utmSourceName: (string) $this->channel,
            );
    }

    public function isManual(): bool
    {
        return $this->definition()->isManual();
    }

    public function displayName(): string
    {
        $label = $this->definition()->label;

        return $this->placement !== '' && $this->placement !== null ? "{$label}: {$this->placement}" : $label;
    }

    /** Label for a CLOSED row, derived from how it was closed. */
    public function closedReasonLabel(): ?string
    {
        if ($this->status !== ChannelPostingStatus::CLOSED) {
            return null;
        }

        return match (true) {
            $this->dropped_at !== null                             => 'Removed from job',
            $this->removed_by !== null                             => 'Taken down',
            $this->removed_at !== null                             => 'Withdrawn',
            $this->posted_at === null                              => 'Not posted',
            default                                                => null,
        };
    }

    /** Trimmed, single-spaced, without angle brackets (it is shown in notifications and ad text). */
    public static function normalizePlacement(?string $value): string
    {
        $value = str_replace(['<', '>'], '', (string) $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return Str::limit($value, 120, '');
    }

    /** The placement's identity for uniqueness: normalised and lower-cased (stored with a binary collation). */
    public static function placementKey(?string $value): string
    {
        return mb_strtolower(static::normalizePlacement($value));
    }

    /**
     * A job's ads for "Came from (ad)" selects: the ads still on the job, plus
     * the one an application already points at even if it was removed since.
     *
     * @return array<int, string>
     */
    public static function optionsForJob(?int $jobId, ?int $companyId, ?int $includeId = null): array
    {
        if (! $jobId || ! $companyId) {
            return [];
        }

        return static::query()
            ->where('job_id', $jobId)
            ->where('company_id', $companyId)
            ->where(fn ($query) => $query->whereNull('dropped_at')->when($includeId, fn ($query) => $query->orWhere('id', $includeId)))
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (JobPosting $posting): array => [$posting->id => "{$posting->displayName()} ({$posting->status->getLabel()})"])
            ->all();
    }

    /** True when this ad was ever out on its channel, whatever later cycles reset. */
    public function everWentOut(): bool
    {
        return $this->status->isOutThere()
            || $this->posted_at !== null
            || $this->removed_at !== null
            || $this->applicants()->exists()
            || $this->events()->whereIn('to_status', [ChannelPostingStatus::LIVE->value, ChannelPostingStatus::REMOVAL_PENDING->value])->exists();
    }

    /**
     * The one way lifecycle columns change. Returns false (and writes nothing)
     * when the posting is already in that state with those values, so replaying
     * a step is harmless; otherwise saves and appends one history event.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $details
     */
    public function transitionTo(
        ChannelPostingStatus $to,
        array $attributes,
        string $event,
        string $origin,
        ?User $actor,
        array $details = [],
    ): bool {
        $from = $this->status;
        $this->forceFill(['status' => $to] + $attributes);

        if ($from === $to && ! $this->isDirty()) {
            return false;
        }

        $this->save();
        $this->recordEvent($event, $origin, $actor, $details, $from);

        return true;
    }

    /** @param array<string, mixed> $details */
    public function recordEvent(string $event, string $origin, ?User $actor, array $details = [], ?ChannelPostingStatus $from = null): JobPostingEvent
    {
        return $this->events()->create([
            'company_id'  => $this->company_id,
            'actor_id'    => $actor?->id,
            'event'       => $event,
            'origin'      => $origin,
            'from_status' => $from?->value,
            'to_status'   => $this->status?->value,
            'details'     => $details === [] ? null : $details,
        ]);
    }

    /**
     * Server-side guard for every save: immutable identity columns, company
     * matches the job, registered channel, allowed status moves and per-tier
     * rules, and the facts each status requires.
     */
    public function assertStateIsValid(): void
    {
        $registry = app(JobPostingChannelRegistry::class);
        $known = $registry->has((string) $this->channel);

        if ($this->exists) {
            foreach (['token', 'company_id', 'job_id', 'channel', 'placement', 'placement_key'] as $column) {
                if ($this->isDirty($column)) {
                    throw new RuntimeException("A job posting's {$column} cannot be changed.");
                }
            }
        } elseif (! $known) {
            throw new InvalidArgumentException("Unknown job posting channel [{$this->channel}].");
        }

        $jobCompany = JobPosition::withTrashed()->whereKey($this->job_id)->value('company_id');
        if ($jobCompany === null || (int) $jobCompany !== (int) $this->company_id) {
            throw new RuntimeException('A job posting must belong to the same company as its job.');
        }

        $to = $this->status;
        $from = $this->exists ? ChannelPostingStatus::tryFrom((string) $this->getRawOriginal('status')) : null;

        if ($from !== null && $from !== $to && ! $from->canTransitionTo($to)) {
            throw new RuntimeException("A job posting cannot move from {$from->getLabel()} to {$to->getLabel()}.");
        }

        if (! $known) {
            // A channel removed from the code can still be taken down, nothing else.
            if ($from !== $to && ! in_array($to, [ChannelPostingStatus::CLOSED, ChannelPostingStatus::REMOVAL_PENDING], true)) {
                throw new RuntimeException('This channel is no longer available; its ad can only be closed.');
            }

            return;
        }

        $channel = $registry->resolve((string) $this->channel);

        if ($channel->isManual() && $to === ChannelPostingStatus::WAITING) {
            throw new RuntimeException('An ad posted by the recruiter never waits for a publisher.');
        }

        if (! $channel->isManual() && in_array($to, [ChannelPostingStatus::TO_POST, ChannelPostingStatus::REMOVAL_PENDING], true)) {
            throw new RuntimeException('Aureus lists this channel itself; it is never posted or taken down by hand.');
        }

        if ($to === ChannelPostingStatus::LIVE && $channel->isManual()) {
            if ($this->posted_at === null) {
                throw new RuntimeException('A live ad needs the date it was posted.');
            }

            if ($channel->requiresLiveUrl && blank($this->live_url)) {
                throw new RuntimeException("A live {$channel->label} ad needs its link.");
            }
        }

        if ($to === ChannelPostingStatus::FAILED && blank($this->last_error)) {
            throw new RuntimeException('A job posting with a problem needs a description of the problem.');
        }

        if ($to === ChannelPostingStatus::CLOSED
            && in_array($from, [ChannelPostingStatus::LIVE, ChannelPostingStatus::REMOVAL_PENDING], true)
            && $this->removed_at === null) {
            throw new RuntimeException('Closing an ad that was out there needs the time it was taken down.');
        }
    }

    protected static function boot(): void
    {
        parent::boot();

        // "saving" fires before "creating", so new-row defaults are applied here,
        // before the guard checks them.
        static::saving(function (JobPosting $posting): void {
            if (! $posting->exists) {
                $posting->applyCreationDefaults();
            }

            $posting->assertStateIsValid();
        });
    }

    /** Token, creator, normalised placement and the company copied from the job. */
    protected function applyCreationDefaults(): void
    {
        $this->token ??= app(JobPostingLinks::class)->newToken();
        $this->creator_id ??= Auth::id();
        $this->placement = static::normalizePlacement($this->placement);
        $this->placement_key = static::placementKey($this->placement);

        $jobCompany = JobPosition::withTrashed()->whereKey($this->job_id)->value('company_id');
        if (! $jobCompany) {
            throw new RuntimeException("Set the job opening's company before adding channels.");
        }

        $this->company_id = (int) $jobCompany;

        if ($this->status !== ChannelPostingStatus::PLANNED) {
            throw new RuntimeException('A new job posting starts as planned.');
        }
    }
}
