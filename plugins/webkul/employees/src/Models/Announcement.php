<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A company announcement posted by HR (AnnouncementService). The body is
 * sanitised HTML; it is sanitised again whenever it is shown.
 *
 * @property bool $is_pinned
 * @property bool $requires_acknowledgement
 * @property Carbon|null $publish_at
 * @property Carbon|null $expires_at
 */
class Announcement extends Model
{
    use SoftDeletes;

    public const AudienceAll = 'all';

    public const AudienceDepartments = 'departments';

    protected $table = 'employees_announcements';

    protected $fillable = [
        'company_id',
        'title',
        'body',
        'audience',
        'is_pinned',
        'requires_acknowledgement',
        'publish_at',
        'expires_at',
        'creator_id',
    ];

    protected $casts = [
        'is_pinned'                => 'boolean',
        'requires_acknowledgement' => 'boolean',
        'publish_at'               => 'datetime',
        'expires_at'               => 'datetime',
    ];

    /** @return array<string, string> */
    public static function audiences(): array
    {
        return [
            self::AudienceAll         => 'Everyone in the company',
            self::AudienceDepartments => 'Selected departments',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'employees_announcement_departments', 'announcement_id', 'department_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class, 'announcement_id');
    }

    /** The body as safe HTML. */
    public function safeBody(): string
    {
        return Str::sanitizeHtml((string) $this->body);
    }

    /** A plain-text preview of the body. */
    public function excerpt(int $limit = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $this->safeBody())))) ?? '');

        return Str::limit($text, $limit);
    }

    public function isLive(?Carbon $now = null): bool
    {
        $now ??= now();

        return ($this->publish_at === null || $this->publish_at->lte($now))
            && ($this->expires_at === null || $this->expires_at->gt($now));
    }

    /** "Scheduled", "Live" or "Expired" for HR. */
    public function stateLabel(?Carbon $now = null): string
    {
        $now ??= now();

        return match (true) {
            $this->publish_at !== null && $this->publish_at->gt($now)   => 'Scheduled',
            $this->expires_at !== null && $this->expires_at->lte($now)  => 'Expired',
            default                                                     => 'Live',
        };
    }

    protected static function booted(): void
    {
        static::creating(function (self $announcement): void {
            $announcement->creator_id ??= Auth::id();
        });
    }
}
