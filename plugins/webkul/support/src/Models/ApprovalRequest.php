<?php

namespace Webkul\Support\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
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
        'submission_round',
        'submitted_at',
        'current_step_started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'                  => 'decimal:4',
            'context'                 => 'array',
            'current_step_sequence'   => 'integer',
            'submission_round'        => 'integer',
            'submitted_at'            => 'datetime',
            'current_step_started_at' => 'datetime',
            'completed_at'            => 'datetime',
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

    public function reminders(): HasMany
    {
        return $this->hasMany(ApprovalReminder::class, 'request_id')->orderBy('id');
    }

    /**
     * Reminders and the escalation sent for the step the request is waiting
     * on now, in the current submission round.
     *
     * @return Collection<int, ApprovalReminder>
     */
    public function currentStepReminders(): Collection
    {
        $step = $this->currentStep();
        if (! $step) {
            return collect();
        }

        return $this->reminders
            ->where('step_id', $step->id)
            ->where('submission_round', (int) ($this->submission_round ?: 1))
            ->values();
    }

    /** Whole hours the current step has been waiting (pending requests only). */
    public function waitingHours(?CarbonInterface $now = null): ?int
    {
        if ($this->status !== 'pending') {
            return null;
        }

        $since = $this->current_step_started_at ?? $this->submitted_at ?? $this->created_at;

        return $since ? max(0, (int) floor($since->diffInMinutes($now ?? now(), true) / 60)) : null;
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
