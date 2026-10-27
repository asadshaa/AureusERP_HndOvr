<?php

namespace Webkul\Security\Filament\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Filament resource whose list lets a multi-company user look across all of
 * their companies (docs/MULTI_COMPANY_ACCESS_PLAN.md), narrowed with a
 * "Company" filter that starts on the active company — so the default view
 * is unchanged and single-company users see no difference at all.
 *
 * Records of another member company are view-only: editing, deleting,
 * restoring and replicating are denied server-side (the form pickers only
 * offer the active company's data), with a "Switch to <company>" message.
 */
trait ScopedToMemberCompanies
{
    /** @var array<int, string> */
    private static array $companyNames = [];

    public static function getEloquentQuery(): Builder
    {
        return CurrentCompany::scopeMemberships(parent::getEloquentQuery());
    }

    /**
     * "Company" table filter: the user's own companies only, starting on the
     * active one; hidden for users who belong to a single company. Clearing
     * it shows every member company (the base query keeps the rows inside
     * the user's companies either way).
     */
    public static function companyFilter(string $column = 'company_id'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label(__('Company'))
            ->multiple()
            ->options(fn (): array => Company::query()
                ->whereKey(CurrentCompany::membershipIds())
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
            ->default(fn (): array => array_filter([CurrentCompany::id()]))
            ->visible(fn (): bool => count(CurrentCompany::membershipIds()) > 1)
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $selected = array_map('intval', array_filter((array) ($data['values'] ?? [])));

                if ($selected === []) {
                    return $query;
                }

                return $query->whereIn(
                    $query->qualifyColumn($column),
                    array_values(array_intersect($selected, CurrentCompany::membershipIds())) ?: [0],
                );
            });
    }

    /** Records with no company, or of the active company, follow the normal rules. */
    public static function isActiveCompanyRecord(?Model $record): bool
    {
        $companyId = $record?->getAttribute('company_id');

        return $record !== null && ($companyId === null || (int) $companyId === CurrentCompany::id());
    }

    public static function switchCompanyMessage(Model $record): string
    {
        $companyId = (int) $record->getAttribute('company_id');
        $name = static::$companyNames[$companyId] ??= (string) Company::withTrashed()->whereKey($companyId)->value('name');

        return __('Switch to :company to edit this record.', ['company' => $name]);
    }

    /** For EditAction/DeleteAction: show a disabled button with the "Switch to" tooltip on other companies' rows. */
    public static function switchCompanyTooltip(): Closure
    {
        return fn (?Model $record = null): bool => $record !== null && ! static::isActiveCompanyRecord($record);
    }

    /**
     * Header actions of a record page: all of them for the active company's
     * records, only the read-only ones named in $readOnly otherwise.
     *
     * @param  array<int, Action|ActionGroup>  $actions
     * @param  array<int, string>  $readOnly
     * @return array<int, Action|ActionGroup>
     */
    public static function actionsForRecordCompany(array $actions, Model $record, array $readOnly = []): array
    {
        if (static::isActiveCompanyRecord($record)) {
            return $actions;
        }

        return array_values(array_filter(
            $actions,
            fn (Action|ActionGroup $action): bool => $action instanceof Action && in_array($action->getName(), $readOnly, true),
        ));
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::otherCompanyResponse($record) ?? parent::getEditAuthorizationResponse($record);
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return static::otherCompanyResponse($record) ?? parent::getDeleteAuthorizationResponse($record);
    }

    public static function getForceDeleteAuthorizationResponse(Model $record): Response
    {
        return static::otherCompanyResponse($record) ?? parent::getForceDeleteAuthorizationResponse($record);
    }

    public static function getRestoreAuthorizationResponse(Model $record): Response
    {
        return static::otherCompanyResponse($record) ?? parent::getRestoreAuthorizationResponse($record);
    }

    public static function getReplicateAuthorizationResponse(Model $record): Response
    {
        return static::otherCompanyResponse($record) ?? parent::getReplicateAuthorizationResponse($record);
    }

    protected static function otherCompanyResponse(Model $record): ?Response
    {
        return static::isActiveCompanyRecord($record) ? null : Response::deny(static::switchCompanyMessage($record));
    }
}
