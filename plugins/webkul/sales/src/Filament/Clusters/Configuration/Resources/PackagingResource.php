<?php

namespace Webkul\Sale\Filament\Clusters\Configuration\Resources;

use Webkul\Product\Filament\Resources\PackagingResource as BasePackagingResource;
use Webkul\Product\Settings\ProductSettings;
use Webkul\Sale\Filament\Clusters\Configuration;
use Webkul\Sale\Filament\Clusters\Configuration\Resources\PackagingResource\Pages\ManagePackagings;
use Webkul\Sale\Models\Packaging;
use Webkul\Support\Filament\Concerns\GatedByFeatureSetting;

class PackagingResource extends BasePackagingResource
{
    use GatedByFeatureSetting;

    protected static ?string $model = Packaging::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = 5;

    protected static ?string $cluster = Configuration::class;

    /** Feature toggle, checked per request by GatedByFeatureSetting::canAccess(). */
    protected static function isFeatureEnabled(): bool
    {
        return settings(ProductSettings::class)->enable_packagings;
    }

    public static function getNavigationGroup(): string
    {
        return __('Packagings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Products');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePackagings::route('/'),
        ];
    }
}
