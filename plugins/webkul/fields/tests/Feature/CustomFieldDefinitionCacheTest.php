<?php

/**
 * Regression tests for the shared per-request custom field definition cache used by the
 * Webkul\Field\Traits\HasCustomFields model trait.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Webkul\Field\Models\Field;
use Webkul\Field\Traits\HasCustomFields;

/**
 * A customizable model that is only instantiated by this file, so its custom fields are loaded
 * for the first time inside the test below.
 */
class CustomFieldCacheTestModel extends Model
{
    use HasCustomFields;

    protected $table = 'custom_fields';
}

function customFieldCacheTestField(string $customizableType, string $type = 'text', array $attributes = []): Field
{
    return Field::query()->create([
        'code'              => 'cf_cache_test_'.uniqid(),
        'name'              => 'Cache test field',
        'type'              => $type,
        'customizable_type' => $customizableType,
        ...$attributes,
    ]);
}

it('loads the definitions of every customizable type with a single query', function (): void {
    Field::flushDefinitionCache();

    DB::enableQueryLog();
    DB::flushQueryLog();

    Field::definitionsFor('Webkul\Employee\Models\Employee');
    Field::definitionsFor('Webkul\Support\Models\Company');
    Field::definitionsFor('Webkul\Employee\Models\Department');

    $customFieldQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'custom_fields'));

    DB::disableQueryLog();

    expect($customFieldQueries)->toHaveCount(1);
});

it('sees a field created mid-request and drops it again when it is deleted', function (): void {
    $type = 'Tests\CustomFieldCache\Customizable'.uniqid();

    // Warm the cache before the field exists.
    expect(Field::definitionsFor($type))->toHaveCount(0);

    $field = customFieldCacheTestField($type, 'checkbox');

    expect(Field::definitionsFor($type)->pluck('code')->all())->toBe([$field->code]);

    $field->delete();

    expect(Field::definitionsFor($type))->toHaveCount(0);

    $field->restore();

    expect(Field::definitionsFor($type)->pluck('code')->all())->toBe([$field->code]);
});

it('makes a field created mid-request fillable and cast for a model that loads its fields afterwards', function (): void {
    // The shared cache is already warm when the fields are created...
    Field::definitionsFor('warm-the-shared-cache');

    $toggle = customFieldCacheTestField(CustomFieldCacheTestModel::class, 'toggle');
    $multiSelect = customFieldCacheTestField(CustomFieldCacheTestModel::class, 'select', ['is_multiselect' => true]);

    // ...and the model class loads its custom fields for the first time afterwards.
    $model = new CustomFieldCacheTestModel;
    $model->fill([$toggle->code => 1, $multiSelect->code => ['a', 'b']]);

    expect($model->getFillable())->toContain($toggle->code, $multiSelect->code)
        ->and($model->getCasts()[$toggle->code])->toBe('boolean')
        ->and($model->getCasts()[$multiSelect->code])->toBe('array')
        ->and($model->getAttribute($toggle->code))->toBeTrue();
});
