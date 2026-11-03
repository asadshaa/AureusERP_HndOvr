<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Security\Models\User;

/**
 * One line of a job offer's history (created, submitted, approved,
 * returned, sent, accepted...). Never holds the salary.
 */
class JobOfferEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'recruitments_job_offer_events';

    protected $fillable = [
        'job_offer_id',
        'event',
        'from_status',
        'to_status',
        'actor_id',
        'note',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(JobOffer::class, 'job_offer_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function label(): string
    {
        return match ($this->event) {
            'created'          => __('Offer drafted'),
            'updated'          => __('Terms changed'),
            'submitted'        => __('Submitted for approval'),
            'resubmitted'      => __('Resubmitted for approval'),
            'step_approved'    => __('Approval step passed'),
            'approved'         => __('Approved'),
            'rejected'         => __('Rejected'),
            'returned'         => __('Returned for changes'),
            'withdrawn'        => __('Withdrawn'),
            'letter_generated' => __('Offer letter generated'),
            'sent'             => __('Sent to the candidate'),
            'accepted'         => __('Accepted by the candidate').$this->recordedChannelSuffix(),
            'declined'         => __('Declined by the candidate').$this->recordedChannelSuffix(),
            'expired'          => __('Expired'),
            'employee_created' => __('Employee created'),
            default            => str($this->event)->headline()->toString(),
        };
    }

    /** " (by phone)" when HR recorded the answer for the candidate. */
    protected function recordedChannelSuffix(): string
    {
        $channel = $this->meta['channel'] ?? null;

        return $this->actor_id !== null && $channel && $channel !== JobOfferService::RESPONSE_CHANNEL_LINK
            ? ' ('.mb_strtolower(JobOfferService::responseChannelLabel($channel)).')'
            : '';
    }

    public function toStatusLabel(): ?string
    {
        return $this->to_status ? JobOfferStatus::tryFrom($this->to_status)?->getLabel() : null;
    }
}
