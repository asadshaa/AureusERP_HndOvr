<?php

namespace Webkul\Security\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Webkul\Security\Models\User;

/**
 * Validation rules for ids sent to the API: a referenced record must belong to
 * one of the given companies (by default the companies the token user belongs
 * to, CurrentCompany membership), optionally also accepting shared rows that
 * have no company. The write-side counterpart of the read scoping done with
 * CurrentCompany::scopeMemberships() in the API controllers.
 */
class CompanyScopedRules
{
    /**
     * exists:{table},{column} limited to rows of $companyIds (null: the user's
     * companies), plus rows with no company when $allowShared.
     *
     * @param  array<int, int>|null  $companyIds
     */
    public static function exists(
        string $table,
        ?array $companyIds = null,
        bool $allowShared = false,
        string $companyColumn = 'company_id',
        string $column = 'id',
        ?Authenticatable $user = null,
    ): Exists {
        $companyIds = static::resolveCompanyIds($companyIds, $user);

        return Rule::exists($table, $column)->where(fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
            ->whereIn($companyColumn, $companyIds === [] ? [0] : $companyIds)
            ->when($allowShared, fn (Builder $shared): Builder => $shared->orWhereNull($companyColumn))));
    }

    /**
     * exists:{table},{column} for rows that belong to a parent record of one of
     * the companies (e.g. a bank account through its partner, a payment method
     * line through its journal).
     *
     * @param  array<int, int>|null  $companyIds
     */
    public static function existsThrough(
        string $table,
        string $foreignKey,
        string $parentTable,
        ?array $companyIds = null,
        string $parentCompanyColumn = 'company_id',
        string $column = 'id',
        ?Authenticatable $user = null,
    ): Exists {
        $companyIds = static::resolveCompanyIds($companyIds, $user);

        return Rule::exists($table, $column)->where(fn (Builder $query): Builder => $query->whereIn(
            $foreignKey,
            fn (Builder $parents): Builder => $parents
                ->select('id')
                ->from($parentTable)
                ->whereIn($parentCompanyColumn, $companyIds === [] ? [0] : $companyIds),
        ));
    }

    /**
     * A company_id value: one of $companyIds (null: the user's companies).
     *
     * @param  array<int, int>|null  $companyIds
     */
    public static function company(?array $companyIds = null, ?Authenticatable $user = null): In
    {
        $companyIds = static::resolveCompanyIds($companyIds, $user);

        return Rule::in($companyIds === [] ? [0] : $companyIds);
    }

    /**
     * @param  array<int, int>|null  $companyIds
     * @return array<int, int>
     */
    protected static function resolveCompanyIds(?array $companyIds, ?Authenticatable $user): array
    {
        return array_values(array_map('intval', $companyIds ?? CurrentCompany::membershipIds($user instanceof User ? $user : null)));
    }
}
