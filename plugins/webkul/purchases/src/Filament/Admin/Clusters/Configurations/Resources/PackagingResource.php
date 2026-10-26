<?php

namespace Webkul\Purchase\Filament\Admin\Clusters\Configurations\Resources;

use BackedEnum;
use Webkul\Product\Filament\Resources\PackagingResource as BasePackagingResource;
use Webkul\Purchase\Filament\Admin\Clusters\Configurations;
use Webkul\Purchase\Filament\Admin\Clusters\Configurations\Resources\PackagingResource\Pages\ManagePackagings;
use Webkul\Purchase\Models\Packaging;
use Webkul\Purchase\Settings\ProductSettings;
use Webkul\Support\Filament\Concerns\GatedByFeatureSetting;

class PackagingResource extends BasePackagingResource
{
    use GatedByFeatureSetting;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = 10;

    protected static ?string $model = Packaging::class;

    protected static ?string $cluster = Configurations::class;

    protected static ?string $recordTitleAttribute = 'name';

    /** Feature toggle, checked per request by GatedByFeatureSetting::canAccess(). */
    protected static function isFeatureEnabled(): bool
    {
        return settings(ProductSettings::class)->enable_packagings;
    }

    public static function getNavigationGroup(): string
    {
        return __('purchases::filament/admin/clusters/configurations/resources/packaging.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('purchases::filament/admin/clusters/configurations/resources/packaging.navigation.title');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePackagings::route('/'),
        ];
    }
}
