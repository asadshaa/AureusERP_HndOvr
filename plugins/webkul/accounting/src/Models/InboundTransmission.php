<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\InboundTransmissionStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Evidence of something a peer sent us. Holds no ledger consequence on its
 * own -- the draft bill only exists once a reviewer accepts.
 */
class InboundTransmission extends Model
{
    protected $table = 'accounting_inbound_transmissions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'      => InboundTransmissionStatus::class,
            'payload'     => 'array',
            'reviewed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdMove(): BelongsTo
    {
        return $this->belongsTo(Move::class, 'created_move_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', InboundTransmissionStatus::Received);
    }

    public function isReviewed(): bool
    {
        return $this->status !== InboundTransmissionStatus::Received;
    }
}
