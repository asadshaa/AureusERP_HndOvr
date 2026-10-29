<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Webkul\Security\Models\User;

/**
 * When one user read, and if asked acknowledged, an announcement.
 *
 * @property Carbon|null $read_at
 * @property Carbon|null $acknowledged_at
 */
class AnnouncementRead extends Model
{
    protected $table = 'employees_announcement_reads';

    protected $fillable = [
        'announcement_id',
        'user_id',
        'employee_id',
        'read_at',
        'acknowledged_at',
    ];

    protected $casts = [
        'read_at'         => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class, 'announcement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }
}
