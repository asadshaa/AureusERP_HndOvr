<?php

namespace Webkul\Account\Http\Controllers\API\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Http\Requests\AccountRequest;
use Webkul\Account\Http\Resources\V1\AccountResource;
use Webkul\Account\Models\Account;
use Webkul\Security\Services\CurrentCompany;

#[Group('Account API Management')]
#[Subgroup('Chart of Accounts', 'Manage chart of accounts')]
#[Authenticated]
class AccountController extends Controller
{
    #[Endpoint('List accounts', 'Retrieve a paginated list of accounts with filtering and sorting')]
    #[QueryParam('include', 'string', 'Comma-separated list of relationships to include. </br></br><b>Available options:</b> currency, creator, taxes, tags, journals, moveLines, companies', required: false, example: 'currency')]
    #[QueryParam('filter[id]', 'string', 'Comma-separated list of IDs to filter by', required: false, example: 'No-example')]
    #[QueryParam('filter[name]', 'string', 'Filter by account name (partial match)', required: false, example: 'No-example')]
    #[QueryParam('filter[code]', 'string', 'Filter by account code (partial match)', required: false, example: 'No-example')]
    #[QueryParam('filter[account_type]', 'string', 'Filter by account type', enum: AccountType::class, required: false, example: 'No-example')]
    #[QueryParam('filter[currency_id]', 'int', 'Filter by currency ID', required: false, example: 'No-example')]
    #[QueryParam('filter[deprecated]', 'boolean', 'Filter by deprecated status', required: false, example: 'No-example')]
    #[QueryParam('filter[reconcile]', 'boolean', 'Filter by reconcile flag', required: false, example: 'No-example')]
    #[QueryParam('sort', 'string', 'Sort field', example: 'code')]
    #[QueryParam('page', 'int', 'Page number', example: 1)]
    #[ResponseFromApiResource(AccountResource::class, Account::class, collection: true, paginate: 10)]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function index()
    {
        Gate::authorize('viewAny', Account::class);

        $accounts = QueryBuilder::for($this->companyAccounts())
            ->allowedFilters(
                AllowedFilter::exact('id'),
                AllowedFilter::partial('name'),
                AllowedFilter::partial('code'),
                AllowedFilter::exact('account_type'),
                AllowedFilter::exact('currency_id'),
                AllowedFilter::exact('deprecated'),
                AllowedFilter::exact('reconcile'),
            )
            ->allowedSorts('id', 'code', 'name', 'account_type', 'created_at')
            ->allowedIncludes(...$this->allowedIncludes())
            ->paginate();

        return AccountResource::collection($accounts);
    }

    #[Endpoint('Create account', 'Create a new account')]
    #[ResponseFromApiResource(AccountResource::class, Account::class, status: 201, additional: ['message' => 'Account created successfully.'])]
    #[Response(status: 422, description: 'Validation error', content: '{"message": "The given data was invalid.", "errors": {"name": ["The name field is required."]}}')]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function store(AccountRequest $request)
    {
        Gate::authorize('create', Account::class);

        $companyId = CurrentCompany::id();

        abort_if($companyId === null, 422, 'Select an active company before creating an account.');

        $account = DB::transaction(function () use ($request, $companyId): Account {
            $account = Account::create($request->validated());
            $account->companies()->sync([$companyId]);

            return $account;
        });

        return (new AccountResource($account))
            ->additional(['message' => 'Account created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    #[Endpoint('Show account', 'Retrieve a specific account by its ID')]
    #[UrlParam('id', 'integer', 'The account ID', required: true, example: 1)]
    #[QueryParam('include', 'string', 'Comma-separated list of relationships to include. </br></br><b>Available options:</b> currency, creator, taxes, tags, journals, moveLines, companies', required: false, example: 'currency,taxes')]
    #[ResponseFromApiResource(AccountResource::class, Account::class)]
    #[Response(status: 404, description: 'Account not found', content: '{"message": "Resource not found."}')]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function show(string $id)
    {
        $account = QueryBuilder::for($this->companyAccounts()->whereKey($id))
            ->allowedIncludes(...$this->allowedIncludes())
            ->firstOrFail();

        Gate::authorize('view', $account);

        return new AccountResource($account);
    }

    #[Endpoint('Update account', 'Update an existing account')]
    #[UrlParam('id', 'integer', 'The account ID', required: true, example: 1)]
    #[ResponseFromApiResource(AccountResource::class, Account::class, additional: ['message' => 'Account updated successfully.'])]
    #[Response(status: 404, description: 'Account not found', content: '{"message": "Resource not found."}')]
    #[Response(status: 422, description: 'Validation error', content: '{"message": "The given data was invalid.", "errors": {"name": ["The name field is required."]}}')]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function update(AccountRequest $request, string $id)
    {
        $account = $this->companyAccounts()->findOrFail($id);

        Gate::authorize('update', $account);

        $account->update($request->validated());

        return (new AccountResource($account))
            ->additional(['message' => 'Account updated successfully.']);
    }

    #[Endpoint('Delete account', 'Delete an account')]
    #[UrlParam('id', 'integer', 'The account ID', required: true, example: 1)]
    #[Response(status: 200, description: 'Account deleted', content: '{"message": "Account deleted successfully."}')]
    #[Response(status: 404, description: 'Account not found', content: '{"message": "Resource not found."}')]
    #[Response(status: 422, description: 'Account in use', content: '{"message": "The account cannot be deleted because it has journal items.", "errors": {"account": ["it has journal items"]}}')]
    #[Response(status: 401, description: 'Unauthenticated', content: '{"message": "Unauthenticated."}')]
    public function destroy(string $id)
    {
        $account = $this->companyAccounts()->findOrFail($id);

        Gate::authorize('delete', $account);

        $blockers = $account->deletionBlockers();

        if ($blockers !== []) {
            return response()->json([
                'message' => 'The account cannot be deleted because '.implode(', ', $blockers).'.',
                'errors'  => ['account' => $blockers],
            ], 422);
        }

        $account->delete();

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }

    /**
     * Includes; ledger lines, journals and taxes are limited to the token
     * user's companies, so a shared account never exposes another
     * company's records.
     *
     * @return array<int, string|AllowedInclude>
     */
    protected function allowedIncludes(): array
    {
        $companyIds = CurrentCompany::membershipIds();
        $ownRecords = fn (string $relation): AllowedInclude => AllowedInclude::callback(
            $relation,
            fn ($query) => $query->whereIn($query->qualifyColumn('company_id'), $companyIds === [] ? [0] : $companyIds),
        );

        return [
            'currency',
            'creator',
            $ownRecords('taxes'),
            'tags',
            $ownRecords('journals'),
            $ownRecords('moveLines'),
            'companies',
        ];
    }

    /**
     * Accounts linked to one of the token user's companies.
     *
     * @return Builder<Account>
     */
    protected function companyAccounts(): Builder
    {
        return Account::query()->linkedToCompanies(CurrentCompany::membershipIds());
    }
}
