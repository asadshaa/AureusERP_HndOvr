<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar as WebkulPermissionRegistrar;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Set which companies a user belongs to (docs/MULTI_COMPANY_ACCESS_PLAN.md):
 *
 *   php artisan erp:company-access hr@shipline.test --companies=ShipLine
 *   php artisan erp:company-access owner@truckitin.test --companies=1,25,26 --default=1 --group-owner
 *
 * Without options it only shows the user's current companies.
 */
class CompanyAccess extends Command
{
    protected $signature = 'erp:company-access
        {email : The user\'s email address}
        {--companies=* : Company ids or exact names the user belongs to (replaces the current list)}
        {--default= : Company id or name to make active (must be one of --companies)}
        {--group-owner : Allow this user to manage other users\' company memberships}
        {--revoke-group-owner : Remove the group-owner permission}
        {--dry-run : Show the change without saving it}';

    protected $description = 'Set the companies a user belongs to, their active company and group-owner access';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $companyOptions = collect($this->option('companies'))->flatMap(fn (string $value) => explode(',', $value))->map(fn (string $value): string => trim($value))->filter();

        if ($companyOptions->isEmpty() && ! $this->option('group-owner') && ! $this->option('revoke-group-owner')) {
            $this->showAccess($user);

            return self::SUCCESS;
        }

        $companies = $companyOptions->isEmpty() ? null : $this->resolveCompanies($companyOptions);

        if ($companies === false) {
            return self::FAILURE;
        }

        $defaultId = $this->resolveDefault($user, $companies);

        if ($defaultId === false) {
            return self::FAILURE;
        }

        $this->line("User: {$user->name} <{$user->email}>");

        if ($companies !== null) {
            $this->line('Companies: '.$companies->pluck('name')->join(', '));
            $this->line('Active company: '.$companies->firstWhere('id', $defaultId)?->name);
        }

        if ($this->option('group-owner')) {
            $this->line('Group owner: yes');
        } elseif ($this->option('revoke-group-owner')) {
            $this->line('Group owner: no');
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        if ($companies !== null) {
            $user->allowedCompanies()->sync($companies->pluck('id')->reject(fn (int $id): bool => $id === $defaultId)->all());
            $user->forceFill(['default_company_id' => $defaultId])->saveQuietly();
        }

        if ($this->option('group-owner') || $this->option('revoke-group-owner')) {
            $this->forgetCachedPermissions();
            $permission = Permission::query()->firstOrCreate(['name' => CurrentCompany::MANAGE_MEMBERSHIPS, 'guard_name' => 'web']);

            $this->option('group-owner')
                ? $user->permissions()->syncWithoutDetaching([$permission->getKey()])
                : $user->permissions()->detach($permission->getKey());

            $this->forgetCachedPermissions();
        }

        CurrentCompany::flush($user);
        $this->info('Saved.');

        return self::SUCCESS;
    }

    /** Both registrars keep their own copy of the permission list. */
    protected function forgetCachedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(WebkulPermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  Collection<int, string>  $values
     * @return Collection<int, Company>|false
     */
    protected function resolveCompanies(Collection $values): Collection|false
    {
        $companies = collect();

        foreach ($values as $value) {
            $company = Company::query()
                ->when(ctype_digit($value), fn ($query) => $query->whereKey((int) $value), fn ($query) => $query->where('name', $value))
                ->first();

            if (! $company) {
                $this->error("No active company \"{$value}\".");

                return false;
            }

            $companies->put($company->id, $company);
        }

        return $companies->values();
    }

    /** @param  Collection<int, Company>|null  $companies */
    protected function resolveDefault(User $user, ?Collection $companies): int|false|null
    {
        if ($companies === null) {
            return null;
        }

        $value = $this->option('default');

        if ($value === null || $value === '') {
            return $companies->contains('id', (int) $user->default_company_id) ? (int) $user->default_company_id : (int) $companies->first()->id;
        }

        $match = $companies->first(fn (Company $company): bool => (string) $company->id === (string) $value || $company->name === $value);

        if (! $match) {
            $this->error('--default must be one of --companies.');

            return false;
        }

        return (int) $match->id;
    }

    protected function showAccess(User $user): void
    {
        $ids = CurrentCompany::membershipIds($user);
        $active = CurrentCompany::id($user);

        $this->table(['Company', 'Active'], Company::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Company $company): array => ["{$company->name} (#{$company->id})", $company->id === $active ? 'yes' : ''])
            ->all());

        $this->line('Group owner: '.(CurrentCompany::isGroupOwner($user) ? 'yes' : 'no'));
    }
}
