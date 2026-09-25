<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class PeriodLock extends Model
{
    protected $table = 'accounts_period_locks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'locked_through_date' => 'date',
            'locked_at'           => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }
}
