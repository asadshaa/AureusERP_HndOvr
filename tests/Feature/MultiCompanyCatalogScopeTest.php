<?php

/**
 * Multi-company isolation of the product catalog and partners
 * (docs/MULTI_COMPANY_ACCESS_PLAN.md §2.4 #22, #23, #25): products are scoped
 * to the active company plus shared (no-company) products, and resources that
 * define their own getEloquentQuery() still apply the owner/group permission
 * scope from HasResourcePermissionQuery.
 */

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Inventory\Filament\Clusters\Products\Resources\ProductResource as InventoryProductResource;
use Webkul\Partner\Filament\Resources\PartnerResource;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Filament\Resources\ProductResource;
use Webkul\Product\Models\Product;
use Webkul\Sale\Filament\Clusters\Orders\Resources\OrderResource as SaleOrderResource;
use Webkul\Security\Bouncer;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar as WebkulPermissionRegistrar;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/** A user whose active company is $company, with the given resource permission and permissions. */
function catalogMember(Company $company, PermissionType $resourcePermission = PermissionType::GLOBAL, string ...$permissions): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => $resourcePermission]);

    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(WebkulPermissionRegistrar::class)->forgetCachedPermissions();
    CurrentCompany::flush();

    return $user->refresh();
}

/** Sign in as $user, forgetting the previous user's cached authorized user ids. */
function catalogLogin(User $user): void
{
    (new ReflectionProperty(Bouncer::class, 'authorizedUserIdsCache'))->setValue(null, null);
    Auth::login($user);
}

beforeEach(function (): void {
    $this->shipLine = Company::factory()->create(['name' => 'ShipLine '.Str::random(4), 'is_active' => true]);
    $this->openPort = Company::factory()->create(['name' => 'OpenPort '.Str::random(4), 'is_active' => true]);
});

afterEach(function (): void {
    (new ReflectionProperty(Bouncer::class, 'authorizedUserIdsCache'))->setValue(null, null);
});

it('lists the active company\'s and shared products, not another company\'s', function () {
    $ours = Product::factory()->create(['company_id' => $this->shipLine->id]);
    $shared = Product::factory()->create(['company_id' => null]);
    $theirs = Product::factory()->create(['company_id' => $this->openPort->id]);

    catalogLogin(catalogMember($this->shipLine));

    foreach ([ProductResource::class, InventoryProductResource::class] as $resource) {
        expect($resource::getEloquentQuery()->pluck('id')->all())
            ->toContain($ours->id, $shared->id)
            ->not->toContain($theirs->id);
    }
});

it('lets only a group owner change a shared product', function () {
    $ours = Product::factory()->create(['company_id' => $this->shipLine->id]);
    $shared = Product::factory()->create(['company_id' => null]);
    $theirs = Product::factory()->create(['company_id' => $this->openPort->id]);

    catalogLogin(catalogMember($this->shipLine));

    $companyAllowsChange = new ReflectionMethod(ProductResource::class, 'companyAllowsChange');
    expect(ProductResource::canEdit($shared))->toBeFalse()
        ->and(ProductResource::canDelete($shared))->toBeFalse()
        ->and($companyAllowsChange->invoke(null, $ours))->toBeTrue()
        ->and($companyAllowsChange->invoke(null, $shared))->toBeFalse()
        ->and($companyAllowsChange->invoke(null, $theirs))->toBeFalse();

    catalogLogin(catalogMember($this->shipLine, PermissionType::GLOBAL, CurrentCompany::MANAGE_MEMBERSHIPS));
    expect($companyAllowsChange->invoke(null, $shared))->toBeTrue();
});

it('keeps partners isolated by company (the permission scope is unchanged for now)', function () {
    $member = catalogMember($this->shipLine, PermissionType::INDIVIDUAL);
    $colleague = catalogMember($this->shipLine);

    $colleagues = Partner::factory()->create(['company_id' => $this->shipLine->id, 'creator_id' => $colleague->id, 'user_id' => $colleague->id]);
    $otherCompany = Partner::factory()->create(['company_id' => $this->openPort->id, 'creator_id' => $member->id, 'user_id' => $member->id]);

    catalogLogin($member);

    expect(PartnerResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($colleagues->id)
        ->not->toContain($otherCompany->id);
});

it('keeps company isolation on sale orders alongside the permission scope', function () {
    catalogLogin(catalogMember($this->shipLine, PermissionType::INDIVIDUAL));

    $sql = SaleOrderResource::getEloquentQuery()->toRawSql();

    expect($sql)->toContain('`company_id` = '.$this->shipLine->id)
        ->toContain('`state` = \'sale\'')
        ->toContain('`creator_id` in');
});
