<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An appraisal reminder that went out (hr:appraisal-reminders). The unique
 * (review_id, kind) key is the claim that keeps reminders idempotent.
 */
class PerformanceReviewReminder extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'employees_performance_review_reminders';

    protected $fillable = ['company_id', 'review_id', 'kind', 'sent_on'];

    protected function casts(): array
    {
        return [
            'sent_on' => 'date',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'review_id');
    }
}
