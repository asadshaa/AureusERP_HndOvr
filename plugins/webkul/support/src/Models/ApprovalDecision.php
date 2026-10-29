<?php

namespace Webkul\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;

class ApprovalDecision extends Model
{
    protected $table = 'support_approval_decisions';

    protected $fillable = [
        'request_id',
        'step_id',
        'submission_round',
        'actor_id',
        'decision',
        'reason',
        'previous_values',
        'new_values',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_round' => 'integer',
            'previous_values'  => 'array',
            'new_values'       => 'array',
            'decided_at'       => 'datetime',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
