<?php

namespace Webkul\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reminder (kind "reminder", numbered from 1) or the single escalation
 * (kind "escalation", number 0) sent for a request at one step in one
 * submission round. Written by ApprovalReminderService; the unique key on
 * request, step, round, kind and number makes each one claimable once.
 */
class ApprovalReminder extends Model
{
    public const KIND_REMINDER = 'reminder';

    public const KIND_ESCALATION = 'escalation';

    protected $table = 'support_approval_reminders';

    protected $fillable = [
        'request_id',
        'step_id',
        'submission_round',
        'kind',
        'reminder_number',
        'recipient_ids',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_round' => 'integer',
            'reminder_number'  => 'integer',
            'recipient_ids'    => 'array',
            'sent_at'          => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'step_id');
    }
}
