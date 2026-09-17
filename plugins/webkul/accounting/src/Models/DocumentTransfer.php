<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Security\Models\User;
use Webkul\Security\Models\UserDevice;
use Webkul\Support\Models\Company;

class DocumentTransfer extends Model
{
    protected $table = 'accounting_document_transfers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'       => DocumentTransferStatus::class,
            'expires_at'   => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function recipientDevice(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'recipient_device_id');
    }

    public function isPending(): bool
    {
        return $this->status === DocumentTransferStatus::Pending;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DocumentTransferStatus::Pending);
    }

    public function scopeForRecipient(Builder $query, int $userId): Builder
    {
        return $query->where('recipient_id', $userId);
    }
}
