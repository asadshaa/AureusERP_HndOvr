<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A website application that could not be imported (plan §6 A4), kept with
 * what the website sent so nothing is lost silently. Open until it is
 * imported (by the next pull or "Retry") or dismissed by HR.
 */
class IntakeFailure extends Model
{
    public const RESOLVED_IMPORTED = 'imported';

    public const RESOLVED_DISMISSED = 'dismissed';

    protected $table = 'recruitments_intake_failures';

    protected $fillable = [
        'company_id',
        'connection_id',
        'source',
        'external_id',
        'payload',
        'error',
        'attempts',
        'last_attempt_at',
        'resolved_at',
        'resolution',
        'resolved_by',
        'applicant_id',
    ];

    protected $casts = [
        'payload'         => 'array',
        'attempts'        => 'integer',
        'last_attempt_at' => 'datetime',
        'resolved_at'     => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WebsiteConnection::class, 'connection_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($query->qualifyColumn('company_id'), $companyId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('resolved_at'));
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    /** The website's id of the application, when it sent a usable one. */
    public function applicationId(): ?int
    {
        $id = $this->payload['id'] ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    public function candidateLabel(): string
    {
        $name = trim((string) ($this->payload['name'] ?? ''));
        $email = trim((string) ($this->payload['email'] ?? ''));

        return trim($name.($email !== '' ? " <{$email}>" : '')) ?: '—';
    }
}
