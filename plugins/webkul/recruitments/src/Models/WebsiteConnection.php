<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A company's careers website (WordPress with the "Aureus Careers" plugin,
 * automation plan §3 option B). One per company. The only secret Aureus keeps
 * is the integration user's Application Password: encrypted at rest and
 * hidden from serialisation. Every lookup is by company.
 */
class WebsiteConnection extends Model
{
    protected $table = 'recruitments_website_connections';

    protected static ?bool $tableExists = null;

    protected $fillable = [
        'company_id',
        'base_url',
        'username',
        'app_password',
        'is_active',
        'creator_id',
    ];

    protected $hidden = [
        'app_password',
    ];

    protected $casts = [
        'app_password'    => 'encrypted',
        'is_active'       => 'boolean',
        'last_checked_at' => 'datetime',
        'last_pulled_at'  => 'datetime',
    ];

    protected $attributes = [
        'is_active' => false,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(IntakeFailure::class, 'connection_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($query->qualifyColumn('company_id'), $companyId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /** The company's switched-on connection, if any. */
    public static function activeFor(?int $companyId): ?self
    {
        if (! $companyId || ! static::tableExists()) {
            return null;
        }

        return static::query()->forCompany($companyId)->active()->first();
    }

    /** Publishers are registered at boot: until the migration has run, no company is connected. */
    public static function tableExists(): bool
    {
        // Only a "yes" is remembered, so a long-running worker notices the migration.
        if (static::$tableExists !== true) {
            static::$tableExists = Schema::hasTable('recruitments_website_connections') ? true : null;
        }

        return static::$tableExists === true;
    }

    /** Base of the plugin's REST routes, e.g. https://example.com/wp-json/aureus/v1. */
    public function apiBaseUrl(): string
    {
        return rtrim((string) $this->base_url, '/').'/'.ltrim((string) config('recruitment.website.api_path', '/wp-json/aureus/v1'), '/');
    }

    public function host(): string
    {
        return strtolower((string) parse_url((string) $this->base_url, PHP_URL_HOST));
    }

    /**
     * The applicant's external_application_id for a website application: the
     * site's host keeps two WordPress sites (e.g. after a move) from colliding.
     */
    public function externalIdFor(int|string $applicationId): string
    {
        return 'website:'.$this->host().':'.$applicationId;
    }

    public static function normalizeBaseUrl(?string $url): string
    {
        return rtrim(trim((string) $url), '/');
    }

    /**
     * HTTPS only, a plain site address (no credentials, query or fragment).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(bool $passwordRequired): array
    {
        return [
            'base_url'     => ['required', 'string', 'max:255', 'url:https', function (string $attribute, mixed $value, \Closure $fail): void {
                $parts = parse_url((string) $value);

                if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null)) {
                    $fail('Enter the website address starting with https://.');

                    return;
                }

                if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    $fail('Enter only the website address, e.g. https://www.example.com (no login, ? or #).');
                }
            }],
            'username'     => ['required', 'string', 'max:255'],
            'app_password' => [$passwordRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'is_active'    => ['boolean'],
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (WebsiteConnection $connection): void {
            $connection->base_url = static::normalizeBaseUrl($connection->base_url);

            // Server-side guard for every write path, not only the settings page.
            Validator::make(
                ['base_url' => $connection->base_url, 'username' => $connection->username, 'app_password' => $connection->app_password, 'is_active' => $connection->is_active],
                static::rules(passwordRequired: true),
            )->validate();
        });

        static::creating(function (WebsiteConnection $connection): void {
            $connection->creator_id ??= Auth::id();
        });
    }
}
