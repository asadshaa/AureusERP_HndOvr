<?php

/**
 * The products API only reads and changes products (and their variants,
 * attributes and packagings) of the companies the token user belongs to, plus
 * shared products with no company (as on the Products screen). Another
 * company's product answers 404; a shared product can only be changed by a
 * group owner; new records cannot be put in another company.
 */

use Illuminate\Support\Str;
use Webkul\Product\Enums\ProductType;
use Webkul\Product\Models\Category;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UOM;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('products');

    $this->ownCompany = Company::factory()->create(['name' => 'API isolation ownCompany '.Str::random(8), 'is_active' => true]);
    $this->otherCompany = Company::factory()->create(['name' => 'API isolation otherCompany '.Str::random(8), 'is_active' => true]);

    $user = SecurityHelper::authenticateWithPermissions([
        'view_any_product_product', 'view_product_product', 'create_product_product', 'update_product_product', 'delete_product_product',
        'view_any_product_packaging', 'view_product_packaging', 'create_product_packaging', 'update_product_packaging', 'delete_product_packaging',
    ]);

    $user->forceFill(['default_company_id' => $this->ownCompany->id, 'resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    $user->allowedCompanies()->sync([]);
    CurrentCompany::flush();
});

function companyIsolationProduct(?Company $company, array $attributes = []): Product
{
    return Product::factory()->create(['company_id' => $company?->id, ...$attributes]);
}

it('lists the user\'s own and shared products, never another company\'s', function () {
    $own = companyIsolationProduct($this->ownCompany);
    $shared = companyIsolationProduct(null);
    $other = companyIsolationProduct($this->otherCompany);

    $ids = collect($this->getJson(route('admin.api.v1.products.products.index', ['filter[id]' => "{$own->id},{$shared->id},{$other->id}"]))
        ->assertOk()
        ->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$own->id, $shared->id])->sort()->values()->all());

    $this->getJson(route('admin.api.v1.products.products.show', $shared))->assertOk();
    $this->getJson(route('admin.api.v1.products.products.show', $other))->assertNotFound();
    $this->getJson(route('admin.api.v1.products.products.variants.index', $other))->assertNotFound();
    $this->getJson(route('admin.api.v1.products.products.attributes.index', $other))->assertNotFound();
});

it('answers 404 for another company\'s product and 403 for a shared one, and leaves both untouched', function () {
    $other = companyIsolationProduct($this->otherCompany, ['name' => 'Other Co Product']);
    $shared = companyIsolationProduct(null, ['name' => 'Shared Product']);

    $this->patchJson(route('admin.api.v1.products.products.update', $other), ['name' => 'Renamed'])->assertNotFound();
    $this->deleteJson(route('admin.api.v1.products.products.destroy', $other))->assertNotFound();
    $this->patchJson(route('admin.api.v1.products.products.update', $shared), ['name' => 'Renamed'])->assertForbidden();
    $this->deleteJson(route('admin.api.v1.products.products.destroy', $shared))->assertForbidden();

    expect($other->refresh()->name)->toBe('Other Co Product')
        ->and($shared->refresh()->name)->toBe('Shared Product')
        ->and($shared->trashed())->toBeFalse();
});

it('creates products and packagings only in the user\'s companies', function () {
    $uom = UOM::factory()->create();
    $product = [
        'type'        => ProductType::GOODS->value,
        'name'        => 'Isolation Product',
        'price'       => 10,
        'category_id' => Category::factory()->create()->id,
        'uom_id'      => $uom->id,
        'uom_po_id'   => $uom->id,
    ];

    $this->postJson(route('admin.api.v1.products.products.store'), [...$product, 'company_id' => $this->otherCompany->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id']);

    $this->postJson(route('admin.api.v1.products.products.store'), [...$product, 'company_id' => $this->ownCompany->id])->assertCreated();

    $this->postJson(route('admin.api.v1.products.packagings.store'), ['name' => 'Box', 'qty' => 10, 'product_id' => companyIsolationProduct($this->otherCompany)->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product_id']);

    $this->postJson(route('admin.api.v1.products.packagings.store'), ['name' => 'Box', 'qty' => 10, 'product_id' => companyIsolationProduct($this->ownCompany)->id, 'company_id' => $this->otherCompany->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id']);

    $this->postJson(route('admin.api.v1.products.packagings.store'), ['name' => 'Box', 'qty' => 10, 'product_id' => companyIsolationProduct($this->ownCompany)->id, 'company_id' => $this->ownCompany->id])
        ->assertCreated();

    $otherPackaging = Packaging::query()->create(['name' => 'Other box', 'qty' => 5, 'product_id' => companyIsolationProduct($this->otherCompany)->id, 'company_id' => $this->otherCompany->id]);

    $this->getJson(route('admin.api.v1.products.packagings.show', $otherPackaging))->assertNotFound();
    $this->deleteJson(route('admin.api.v1.products.packagings.destroy', $otherPackaging))->assertNotFound();
});
