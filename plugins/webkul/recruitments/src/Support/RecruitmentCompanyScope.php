<?php

namespace Webkul\Recruitment\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * One place for "only the current company" in Recruitment form selects, so
 * every dropdown uses the same rule as the resources' getEloquentQuery()
 * (the signed-in user's default company). Users count as part of a company
 * when it is their default company or one they are allowed to work in --
 * the same rule JobPositionResource already used for recruiters.
 */
class RecruitmentCompanyScope
{
    public static function companyId(): ?int
    {
        $companyId = Auth::user()?->default_company_id;

        return $companyId ? (int) $companyId : null;
    }

    /** Records that belong to the current company. */
    public static function ownCompany(Builder $query, string $column = 'company_id'): Builder
    {
        return $query->where($query->qualifyColumn($column), self::companyId());
    }

    /** Users who belong to the current company. */
    public static function companyUsers(Builder $query): Builder
    {
        $companyId = self::companyId();

        return $query->where(function (Builder $query) use ($companyId): void {
            $query->where('default_company_id', $companyId)
                ->orWhereHas('allowedCompanies', fn (Builder $companyQuery): Builder => $companyQuery->whereKey($companyId));
        });
    }
}
