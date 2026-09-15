<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Webkul\Accounting\Enums\OutboundTransmissionStatus;
use Webkul\Accounting\Enums\TransmissionChannel;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class OutboundTransmission extends Model
{
    protected $table = 'accounting_outbound_transmissions';

    protected $guarded = [];

    protected $hidden = ['claim_token_hash'];

    protected function casts(): array
    {
        return [
            'channel'      => TransmissionChannel::class,
            'status'       => OutboundTransmissionStatus::class,
            'expires_at'   => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(Peer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** The Move (invoice) or Document being sent. */
    public function transmittable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OutboundTransmissionStatus::Queued);
    }

    public function isClaimable(): bool
    {
        return $this->channel === TransmissionChannel::External
            && in_array($this->status, [OutboundTransmissionStatus::Queued, OutboundTransmissionStatus::Delivered], true)
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
