<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;

/** Audit row of a loan / advance instalment change (append-only). */
class RequestInstalmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'employees_request_instalment_events';

    protected $fillable = [
        'company_id',
        'request_id',
        'instalment_id',
        'action',
        'user_id',
        'reason',
        'before',
        'after',
    ];

    protected function casts(): array
    {
        return [
            'before'     => 'array',
            'after'      => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(EmployeeRequest::class, 'request_id');
    }

    public function instalment(): BelongsTo
    {
        return $this->belongsTo(RequestInstalment::class, 'instalment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
