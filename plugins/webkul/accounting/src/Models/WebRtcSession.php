<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Webkul\Accounting\Enums\WebRtcSessionStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class WebRtcSession extends Model
{
    use HasUuids;

    protected $table = 'accounting_webrtc_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'              => WebRtcSessionStatus::class,
            'sender_candidates'   => 'array',
            'receiver_candidates' => 'array',
            'metadata'            => 'array',
            'completed_at'        => 'datetime',
            'expires_at'          => 'datetime',
        ];
    }

    public function getOfferSdpAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_filter(array_map('trim', explode("\n", $normalized)), fn ($l) => strlen($l) > 0);

        return implode("\r\n", $lines)."\r\n";
    }

    public function getAnswerSdpAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_filter(array_map('trim', explode("\n", $normalized)), fn ($l) => strlen($l) > 0);

        return implode("\r\n", $lines)."\r\n";
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function transmittable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [WebRtcSessionStatus::Waiting, WebRtcSessionStatus::Connected])
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast() || $this->status === WebRtcSessionStatus::Expired;
    }

    public function isConnectable(): bool
    {
        return ! $this->isExpired() && in_array($this->status, [WebRtcSessionStatus::Waiting, WebRtcSessionStatus::Connected]);
    }

    public function appendSenderCandidate(array $candidate): void
    {
        $candidates = $this->sender_candidates ?? [];
        $candidates[] = $candidate;

        $this->update(['sender_candidates' => $candidates]);
    }

    public function appendReceiverCandidate(array $candidate): void
    {
        $candidates = $this->receiver_candidates ?? [];
        $candidates[] = $candidate;

        $this->update(['receiver_candidates' => $candidates]);
    }
}
