<?php

namespace Webkul\Security\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Multi-company membership and the active company (docs/MULTI_COMPANY_ACCESS_PLAN.md).
 *
 * A user belongs to the companies in user_allowed_companies plus their
 * default company (archived companies never count). The active company is
 * users.default_company_id — the value the ~430 existing company filters
 * already read — and can only be switched to one of the user's companies.
 * A "group owner" (permission security_manage_company_memberships) is the
 * super admin over all companies: a member of every active company, and the
 * only one who may manage other users' company memberships.
 */
class CurrentCompany
{
    public const MANAGE_MEMBERSHIPS = 'security_manage_company_memberships';

    /** Container key of the per-request membership cache (user id => company ids). */
    private const CACHE = 'security.current-company.memberships';

    public static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** The active company of the signed-in user (null when signed out or not a member of any company). */
    public static function id(?User $user = null): ?int
    {
        $user ??= static::user();

        if (! $user || ! $user->default_company_id) {
            return null;
        }

        return in_array((int) $user->default_company_id, static::membershipIds($user), true) ? (int) $user->default_company_id : null;
    }

    /**
     * The (non-archived) companies the user belongs to. A group owner (the
     * super admin over all companies) belongs to every active company.
     *
     * @return array<int, int>
     */
    public static function membershipIds(?User $user = null): array
    {
        $user ??= static::user();

        if (! $user) {
            return [];
        }

        return static::cache()[$user->getKey()] ??= Company::query()
            ->unless(static::isGroupOwner($user), fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereKey($user->default_company_id)
                ->orWhereIn('id', $user->allowedCompanies()->select('companies.id'))))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public static function canAccess(int $companyId, ?User $user = null): bool
    {
        return in_array($companyId, static::membershipIds($user), true);
    }

    public static function isGroupOwner(?User $user = null): bool
    {
        $user ??= static::user();

        return (bool) $user?->can(self::MANAGE_MEMBERSHIPS);
    }

    /** Make another of the user's companies the active one. */
    public static function switchTo(int $companyId, ?User $user = null): void
    {
        $user ??= static::user() ?? throw new RuntimeException('Sign in first.');

        if (! static::canAccess($companyId, $user)) {
            throw new RuntimeException('You do not belong to that company.');
        }

        $user->forceFill(['default_company_id' => $companyId])->saveQuietly();
        static::flush($user);
    }

    /**
     * Make sure the active company is one of the user's companies (e.g. after
     * their company was archived). Returns false when they belong to none.
     */
    public static function ensureValid(?User $user = null): bool
    {
        $user ??= static::user();

        if (! $user) {
            return false;
        }

        if (static::id($user) !== null) {
            return true;
        }

        $first = static::membershipIds($user)[0] ?? null;

        if ($first === null) {
            return false;
        }

        $user->forceFill(['default_company_id' => $first])->saveQuietly();
        static::flush($user);

        return true;
    }

    /**
     * Restrict a query to the active company (optionally also rows with no
     * company, i.e. definitions shared by all companies). Signed out or no
     * active company: no rows.
     */
    public static function scope(Builder $query, bool $includeShared = false, string $column = 'company_id'): Builder
    {
        $companyId = static::id();
        $qualified = $query->qualifyColumn($column);

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $inner) => $inner
            ->where($qualified, $companyId)
            ->when($includeShared, fn (Builder $q) => $q->orWhereNull($qualified)));
    }

    /** Someone who is not a group owner can only put a user in one of their own companies. */
    public static function canAssignCompany(int $companyId, ?User $actor = null): bool
    {
        $actor ??= static::user();

        return $actor !== null && (static::isGroupOwner($actor) || static::canAccess($companyId, $actor));
    }

    /** Users who belong to the given company (default or allowed). */
    public static function scopeUsersOf(Builder $query, int $companyId): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where($inner->qualifyColumn('default_company_id'), $companyId)
            ->orWhereHas('allowedCompanies', fn (Builder $companies) => $companies->whereKey($companyId)));
    }

    public static function flush(?User $user = null): void
    {
        if ($user) {
            unset(static::cache()[$user->getKey()]);

            return;
        }

        static::cache()->exchangeArray([]);
    }

    /** Per request (or queued job): scoped instances are reset between them. */
    private static function cache(): \ArrayObject
    {
        if (! app()->bound(self::CACHE)) {
            app()->scoped(self::CACHE, fn (): \ArrayObject => new \ArrayObject);
        }

        return app(self::CACHE);
    }
}
