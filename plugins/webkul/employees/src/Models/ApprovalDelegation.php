<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A substitute approver for a date range: from_user's approvals can also be
 * decided by to_user between starts_on and ends_on (inclusive), unless revoked.
 */
class ApprovalDelegation extends Model
{
    protected $table = 'employees_approval_delegations';

    protected $fillable = [
        'company_id', 'from_user_id', 'to_user_id', 'starts_on', 'ends_on', 'reason',
        'created_by', 'revoked_at', 'revoked_by',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'revoked_at' => 'datetime'];
    }

    /** In force on the given day (Y-m-d) and not revoked. */
    public function scopeActiveOn(Builder $query, string $date): Builder
    {
        return $query->whereNull('revoked_at')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
