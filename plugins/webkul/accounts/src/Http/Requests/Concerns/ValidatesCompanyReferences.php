<?php

namespace Webkul\Account\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Company context for the accounts API form requests: which company a record
 * being created or updated belongs to, so the ids it references (journals,
 * accounts, taxes...) can be limited to that company or to the companies the
 * token user belongs to (Webkul\Security\Services\CompanyScopedRules).
 */
trait ValidatesCompanyReferences
{
    protected function isUpdateRequest(): bool
    {
        return $this->isMethod('PUT') || $this->isMethod('PATCH');
    }

    protected function apiUser(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }

    /** The id in the URL (e.g. {invoice}, {journal} or the workflow actions' {id}). */
    protected function routeRecordId(): mixed
    {
        $parameters = $this->route()?->parameters() ?? [];

        return $parameters === [] ? null : reset($parameters);
    }

    /**
     * The company of the record in the URL, looked up among the user's
     * companies (null when it is not one of them: the controller answers 404).
     *
     * @param  class-string<Model>  $model
     */
    protected function routeRecordCompanyId(string $model, bool $includeShared = false): ?int
    {
        $id = $this->routeRecordId();

        if ($id === null || ! $this->apiUser()) {
            return null;
        }

        $companyId = CurrentCompany::scopeMemberships($model::query(), $includeShared, user: $this->apiUser())
            ->whereKey($id)
            ->value('company_id');

        return $companyId === null ? null : (int) $companyId;
    }

    /** The user's active company, where new records are created. */
    protected function activeCompanyId(): ?int
    {
        return $this->apiUser() ? CurrentCompany::id($this->apiUser()) : null;
    }

    /**
     * The company the record will belong to: the company_id sent with the
     * request, else (update) the record's own company, else the active company.
     *
     * @param  class-string<Model>  $model
     */
    protected function targetCompanyId(string $model, bool $includeShared = false): ?int
    {
        if ($this->filled('company_id') && is_numeric($this->input('company_id'))) {
            return (int) $this->input('company_id');
        }

        return $this->isUpdateRequest()
            ? $this->routeRecordCompanyId($model, $includeShared)
            : $this->activeCompanyId();
    }

    /** Accounts linked to the company (the chart of accounts is shared through accounts_account_companies). */
    protected function companyAccountRule(?int $companyId): Exists
    {
        return $this->accountRuleForCompanies([(int) $companyId]);
    }

    /** Accounts linked to one of the token user's companies. */
    protected function memberAccountRule(): Exists
    {
        return $this->accountRuleForCompanies($this->apiUser() ? CurrentCompany::membershipIds($this->apiUser()) : []);
    }

    /** @param  array<int, int>  $companyIds */
    protected function accountRuleForCompanies(array $companyIds): Exists
    {
        return Rule::exists('accounts_accounts', 'id')->where(fn (Builder $query): Builder => $query->whereIn(
            'id',
            fn (Builder $links): Builder => $links
                ->select('account_id')
                ->from('accounts_account_companies')
                ->whereIn('company_id', $companyIds === [] ? [0] : $companyIds),
        ));
    }
}
