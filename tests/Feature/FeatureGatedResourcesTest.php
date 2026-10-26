<?php

/**
 * Feature-toggled resources (e.g. inventory locations, project milestones)
 * must stay correct once the Filament component cache is built. The toggle
 * therefore lives in canAccess() (GatedByFeatureSetting), checked on every
 * request, not in isDiscovered(), which only runs when the cache is built.
 */

use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Webkul\Account\Filament\Resources\CashRoundingResource;
use Webkul\Account\Settings\CustomerInvoiceSettings;
use Webkul\Inventory\Filament\Clusters\Configurations\Resources\LocationResource;
use Webkul\Inventory\Settings\WarehouseSettings;
use Webkul\Project\Filament\Clusters\Configurations\Resources\MilestoneResource;
use Webkul\Project\Settings\TaskSettings;
use Webkul\Security\Models\User;
use Webkul\Support\Filament\Concerns\GatedByFeatureSetting;

/** @return array<int, class-string<resource>> */
function featureGatedResources(): array
{
    return collect(Filament::getPanel('admin')->getResources())
        ->filter(fn (string $resource): bool => in_array(GatedByFeatureSetting::class, class_uses_recursive($resource), true))
        ->values()
        ->all();
}

it('discovers every feature-gated resource unconditionally, so a cached registry is complete', function () {
    $gated = featureGatedResources();

    expect(count($gated))->toBeGreaterThanOrEqual(15);

    foreach ($gated as $resource) {
        $declaredBy = (new ReflectionMethod($resource, 'isDiscovered'))->getDeclaringClass()->getName();
        expect(str_starts_with($declaredBy, 'Filament\\'))->toBeTrue("{$resource} still gates discovery in {$declaredBy}");
    }
});

it('hides a feature-gated resource when its setting is off and defers to its policy when on', function (string $resource, string $settingsClass, string $flag) {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    test()->actingAs(User::factory()->create(['is_active' => true]));

    $settings = settings($settingsClass);
    $original = $settings->{$flag};

    try {
        $settings->{$flag} = false;
        expect($resource::canAccess())->toBeFalse();

        $settings->{$flag} = true;
        expect($resource::canAccess())->toBe($resource::canViewAny());
    } finally {
        $settings->{$flag} = $original;
    }
})->with([
    'inventory locations' => [LocationResource::class, WarehouseSettings::class, 'enable_locations'],
    'project milestones'  => [MilestoneResource::class, TaskSettings::class, 'enable_milestones'],
    'cash rounding'       => [CashRoundingResource::class, CustomerInvoiceSettings::class, 'group_cash_rounding'],
]);
