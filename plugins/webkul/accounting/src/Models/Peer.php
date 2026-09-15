<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Accounting\Enums\PeerStatus;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Models\Company;

/**
 * A paired remote AureusERP instance.
 *
 * `outbound_token` and `signing_secret` are the two values we must be able
 * to read back (we present one and sign with the other), so they carry
 * `encrypted` casts. `inbound_token_hash` is only ever compared, so it is
 * stored hashed and never recoverable.
 */
class Peer extends Model
{
    protected $table = 'accounting_peers';

    protected $guarded = [];

    protected $hidden = ['outbound_token', 'signing_secret', 'inbound_token_hash', 'pairing_code_hash'];

    protected function casts(): array
    {
        return [
            'status'             => PeerStatus::class,
            'outbound_token'     => 'encrypted',
            'signing_secret'     => 'encrypted',
            'pairing_expires_at' => 'datetime',
            'paired_at'          => 'datetime',
            'last_seen_at'       => 'datetime',
            'revoked_at'         => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function outboundTransmissions(): HasMany
    {
        return $this->hasMany(OutboundTransmission::class);
    }

    public function inboundTransmissions(): HasMany
    {
        return $this->hasMany(InboundTransmission::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PeerStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === PeerStatus::Active && $this->revoked_at === null;
    }

    /**
     * True only while an unused pairing code is still inside its TTL.
     */
    public function hasLivePairingCode(): bool
    {
        return $this->pairing_code_hash !== null
            && $this->pairing_expires_at !== null
            && $this->pairing_expires_at->isFuture();
    }
}
