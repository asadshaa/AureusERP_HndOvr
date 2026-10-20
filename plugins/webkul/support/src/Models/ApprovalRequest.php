<?php

namespace Webkul\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Webkul\Security\Models\User;

class ApprovalRequest extends Model
{
    protected $table = 'support_approval_requests';

    protected $fillable = [
        'company_id',
        'workflow_id',
        'requester_id',
        'subject_type',
        'subject_id',
        'request_type',
        'amount',
        'context',
        'status',
        'current_step_sequence',
        'submitted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'                => 'decimal:4',
            'context'               => 'array',
            'current_step_sequence' => 'integer',
            'submitted_at'          => 'datetime',
            'completed_at'          => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'request_id')->orderBy('id');
    }

    public function currentStep(): ?ApprovalStep
    {
        if ($this->current_step_sequence === null) {
            return null;
        }

        return $this->workflow?->steps?->first(
            fn (ApprovalStep $step): bool => (int) $step->sequence === (int) $this->current_step_sequence
        );
    }
}
