<?php

namespace Webkul\Security\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The companies a form's company field may hold: the active company only,
 * or, for a group owner, any of their companies. Used to limit select options
 * and to reject any other value server-side (a tampered Livewire payload).
 */
class SelectableCompanies
{
    /** @return array<int, int> */
    public static function ids(): array
    {
        if (CurrentCompany::isGroupOwner()) {
            return CurrentCompany::membershipIds();
        }

        $companyId = CurrentCompany::id();

        return $companyId === null ? [] : [$companyId];
    }

    public static function allows(mixed $companyId): bool
    {
        return filled($companyId) && in_array((int) $companyId, static::ids(), true);
    }

    /** Restrict a companies query (e.g. a relationship select) to the selectable companies. */
    public static function scope(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('id'), static::ids());
    }

    /**
     * Validation rule closure for Filament's `->rule(fn (): Closure => SelectableCompanies::rule())`.
     * Blank values pass (pair it with `required()` where a company is mandatory).
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            if (! static::allows($value)) {
                $fail('You cannot use that company.');
            }
        };
    }
}
