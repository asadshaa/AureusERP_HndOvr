<?php

namespace Webkul\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;

class ApprovalStep extends Model
{
    protected $table = 'support_approval_steps';

    protected $fillable = [
        'workflow_id',
        'approver_user_id',
        'approver_role_id',
        'sequence',
        'name',
        'hierarchy_route',
        'required_approvals',
        'sla_hours',
        'conditions',
    ];

    protected function casts(): array
    {
        return ['conditions' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_role_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'step_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $step): void {
            $routes = collect([$step->approver_user_id, $step->approver_role_id, $step->hierarchy_route])->filter();
            if ($routes->count() !== 1) {
                throw new RuntimeException('Each approval step needs exactly one approver: a specific user, a role, or a manager (such as the line manager).');
            }
        });
    }
}
