<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Webkul\Account\Database\Factories\AccountFactory;
use Webkul\Account\Enums\AccountType;
use Webkul\Accounting\Models\AccountDetail;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class Account extends Model
{
    use HasFactory;

    protected $table = 'accounts_accounts';

    protected $fillable = [
        'currency_id',
        'creator_id',
        'parent_id',
        'account_type',
        'name',
        'code',
        'note',
        'deprecated',
        'reconcile',
        'non_trade',
        'is_group',
        'source_classification_path',
        'import_batch_id',
    ];

    protected $casts = [
        'deprecated'   => 'boolean',
        'reconcile'    => 'boolean',
        'non_trade'    => 'boolean',
        'is_group'     => 'boolean',
        'account_type' => AccountType::class,
    ];

    /**
     * Postable (leaf) accounts only — journal lines may not use group nodes.
     */
    public function scopePostable($query)
    {
        return $query->where('is_group', false);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public function getDescendantIds(array $visited = []): array
    {
        $ids = [];
        $visited[] = $this->id;

        foreach ($this->children as $child) {
            if (in_array($child->id, $visited, true)) {
                continue;
            }

            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getDescendantIds($visited));
        }

        return array_values(array_unique($ids));
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'accounts_account_taxes', 'account_id', 'tax_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'accounts_account_account_tags', 'account_id', 'account_tag_id');
    }

    public function journals(): BelongsToMany
    {
        return $this->belongsToMany(Journal::class, 'accounts_account_journals', 'account_id', 'journal_id');
    }

    public function moveLines(): HasMany
    {
        return $this->hasMany(MoveLine::class, 'account_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'accounts_account_companies', 'account_id', 'company_id');
    }

    /**
     * Accounts linked to at least one of the given companies (the chart of
     * accounts is shared between companies through accounts_account_companies).
     *
     * @param  array<int, int>  $companyIds
     */
    public function scopeLinkedToCompanies(Builder $query, array $companyIds): Builder
    {
        return $query->whereHas('companies', fn (Builder $companies): Builder => $companies->whereIn('companies.id', $companyIds === [] ? [0] : $companyIds));
    }

    /**
     * Account codes are unique per company (through the pivot), not globally:
     * the same code may exist once in each company's chart.
     *
     * @param  array<int, int>  $companyIds
     */
    public static function codeExistsInCompanies(string $code, array $companyIds, int|string|null $ignoreAccountId = null): bool
    {
        return static::query()
            ->where('code', trim($code))
            ->linkedToCompanies($companyIds)
            ->when($ignoreAccountId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreAccountId))
            ->exists();
    }

    /**
     * Accounts that may be the parent of an account of the given companies
     * and type: group (non-postable) accounts linked to one of those
     * companies, in the same statement section, never the account itself
     * or one of its descendants.
     *
     * @param  array<int, int>  $companyIds
     */
    public function scopeEligibleParents(Builder $query, array $companyIds, AccountType|string|null $accountType = null, ?self $child = null): Builder
    {
        $type = $accountType instanceof AccountType ? $accountType : AccountType::tryFrom((string) $accountType);

        return $query
            ->where('is_group', true)
            ->linkedToCompanies($companyIds)
            ->when($type !== null, fn (Builder $q): Builder => $q->whereIn('account_type', array_map(
                fn (AccountType $sectionType): string => $sectionType->value,
                AccountType::inSection($type->section()),
            )))
            ->when($child?->exists, fn (Builder $q): Builder => $q->whereNotIn('id', [$child->getKey(), ...$child->getDescendantIds()]));
    }

    /**
     * Whether making $parentId the parent would put the account inside its
     * own subtree (a cycle in the hierarchy).
     */
    public function parentWouldCreateCycle(int|string|null $parentId): bool
    {
        $visited = [];

        while ($parentId !== null && $parentId !== '') {
            if ((int) $parentId === (int) $this->getKey() || in_array((int) $parentId, $visited, true)) {
                return true;
            }

            $visited[] = (int) $parentId;
            $parentId = static::query()->whereKey($parentId)->value('parent_id');
        }

        return false;
    }

    /**
     * The companies linked to this account that the user does not belong to.
     * A shared account (one row linked to several companies) may only be
     * changed or deleted by someone who belongs to every linked company, or
     * by a group owner; otherwise one company could edit another's chart.
     *
     * @return array<int, int>
     */
    public function companiesInaccessibleTo(User $user): array
    {
        if (CurrentCompany::isGroupOwner($user)) {
            return [];
        }

        return array_values(array_diff(
            $this->companies()->pluck('companies.id')->map(fn ($id): int => (int) $id)->all(),
            CurrentCompany::membershipIds($user),
        ));
    }

    /**
     * Link the account to the requested companies, as far as the user may
     * decide: companies the user does not belong to are never added, and
     * existing links to them are always kept (unless a group owner).
     *
     * @param  array<int, int|string>  $companyIds
     */
    public function syncCompaniesFor(User $user, array $companyIds): void
    {
        $requested = array_map('intval', $companyIds);

        if (! CurrentCompany::isGroupOwner($user)) {
            $requested = array_merge(
                array_intersect($requested, CurrentCompany::membershipIds($user)),
                $this->companiesInaccessibleTo($user),
            );
        }

        if ($requested === []) {
            // Never leave an account without a company: a new one takes the
            // user's active company, an existing one keeps its links.
            $activeCompanyId = CurrentCompany::id($user);

            if ($activeCompanyId === null || $this->companies()->exists()) {
                return;
            }

            $requested = [$activeCompanyId];
        }

        $this->companies()->sync(array_values(array_unique($requested)));
    }

    /**
     * Why the account cannot be deleted: ledger lines, or configuration that
     * points at it (journals, FS tags, bank mapping rules and mappings, manual
     * adjustments, report line bindings, sub-accounts). Empty when it can be.
     *
     * @return array<int, string>
     */
    public function deletionBlockers(): array
    {
        $blockers = [];

        if ($this->moveLines()->exists()) {
            $blockers[] = 'it has journal items';
        }

        $references = [
            'accounts_journals'                    => ['default_account_id', 'suspense_account_id', 'profit_account_id', 'loss_account_id', 'journals'],
            'accounting_fs_tags'                   => ['account_id', 'FS tags'],
            'accounting_bank_mapping_rules'        => ['offset_account_id', 'bank_gl_account_id', 'bank mapping rules'],
            'accounting_bank_transaction_mappings' => ['offset_account_id', 'bank_gl_account_id', 'bank transaction mappings'],
            'accounting_manual_adjustments'        => ['debit_account_id', 'credit_account_id', 'manual adjustments'],
            'accounting_report_line_accounts'      => ['account_id', 'report line bindings'],
        ];

        foreach ($references as $table => $columns) {
            $label = array_pop($columns);

            if (! Schema::hasTable($table)) {
                continue;
            }

            $isReferenced = DB::table($table)
                ->where(function ($query) use ($columns): void {
                    foreach ($columns as $column) {
                        $query->orWhere($column, $this->getKey());
                    }
                })
                ->exists();

            if ($isReferenced) {
                $blockers[] = "it is used by {$label}";
            }
        }

        if ($this->children()->exists()) {
            $blockers[] = 'it has sub-accounts';
        }

        return $blockers;
    }

    public function accountingDetail(): HasOne
    {
        return $this->hasOne(AccountDetail::class);
    }

    public static function getMostFrequentAccountsForPartner(
        int $companyId,
        int $partnerId,
        string $moveType,
        bool $filterNeverUsedAccounts = false,
        ?int $limit = null
    ) {
        $minDate = now()->subYears(2)->toDateString();

        // There is no `internal_group` column on accounts_accounts; the group is
        // expressed through account_type, so narrow on the types that make up
        // the income/expense groups instead.
        $group = null;

        if (in_array($moveType, (new Move)->getInboundTypes(true))) {
            $group = array_keys(AccountType::income());
        } elseif (in_array($moveType, (new Move)->getOutboundTypes(true))) {
            $group = array_keys(AccountType::expenses());
        }

        $query = DB::table('accounts_account_move_lines')
            ->select('accounts_account_move_lines.account_id')
            ->join('accounts_accounts', 'accounts_accounts.id', '=', 'accounts_account_move_lines.account_id')
            ->where('accounts_account_move_lines.company_id', $companyId)
            ->where('accounts_account_move_lines.partner_id', $partnerId)
            ->where('accounts_accounts.deprecated', false)
            ->whereDate('accounts_account_move_lines.date', '>=', $minDate);

        if ($group) {
            $query->whereIn('accounts_accounts.account_type', $group);
        }

        if (! $filterNeverUsedAccounts) {
            $accountsBase = DB::table('accounts_accounts')
                ->select('accounts_accounts.id as account_id')
                ->join('accounts_account_companies', function ($join) use ($companyId) {
                    $join->on('accounts_account_companies.account_id', '=', 'accounts_accounts.id')
                        ->where('accounts_account_companies.company_id', $companyId);
                })
                ->leftJoin('accounts_account_move_lines', function ($j) use ($companyId, $partnerId, $minDate) {
                    $j->on('accounts_account_move_lines.account_id', '=', 'accounts_accounts.id')
                        ->where('accounts_account_move_lines.company_id', $companyId)
                        ->where('accounts_account_move_lines.partner_id', $partnerId)
                        ->whereDate('accounts_account_move_lines.date', '>=', $minDate);
                })
                ->where('accounts_accounts.deprecated', false);

            if ($group) {
                $accountsBase->whereIn('accounts_accounts.account_type', $group);
            }

            $query = $query->unionAll($accountsBase);
        }

        $query = DB::table(DB::raw("({$query->toSql()}) as q"))
            ->mergeBindings($query)
            ->select('q.account_id')
            ->join('accounts_accounts', 'accounts_accounts.id', '=', 'q.account_id')
            ->groupBy('q.account_id')
            ->orderByRaw('COUNT(q.account_id) DESC')
            ->orderBy('accounts_accounts.code', 'DESC');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->pluck('account_id')->toArray();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            $account->creator_id ??= Auth::id();
        });

        static::saving(function (self $account): void {
            if ($account->exists && $account->isDirty('parent_id') && $account->parentWouldCreateCycle($account->parent_id)) {
                throw new InvalidArgumentException("Account {$account->code} cannot be placed under its own sub-account (the hierarchy would loop).");
            }
        });
    }

    protected static function newFactory()
    {
        return AccountFactory::new();
    }
}
