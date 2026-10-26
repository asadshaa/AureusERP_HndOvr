<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Webkul\Security\Models\User;

/**
 * Append-only history of a job ad: who did what, from which status to which,
 * and why (actor is null for system steps). Rows are never updated or deleted
 * through the model.
 */
class JobPostingEvent extends Model
{
    public const UPDATED_AT = null;

    public const ORIGIN_USER = 'user';

    public const ORIGIN_JOB_LIFECYCLE = 'job_lifecycle';

    public const ORIGIN_SCHEDULER = 'scheduler';

    public const ORIGIN_PUBLISHER = 'publisher';

    public const ORIGIN_MIGRATION = 'migration';

    protected $table = 'recruitments_job_posting_events';

    protected $fillable = [
        'company_id',
        'job_posting_id',
        'actor_id',
        'event',
        'origin',
        'from_status',
        'to_status',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function posting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::updating(fn () => throw new LogicException('Job posting history is append-only.'));
        static::deleting(fn () => throw new LogicException('Job posting history is append-only.'));
    }
}
