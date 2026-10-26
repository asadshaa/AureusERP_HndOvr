<?php

namespace Webkul\Purchase\Filament\Admin\Clusters\Configurations\Resources;

use BackedEnum;
use Webkul\Product\Filament\Resources\AttributeResource;
use Webkul\Purchase\Filament\Admin\Clusters\Configurations;
use Webkul\Purchase\Filament\Admin\Clusters\Configurations\Resources\ProductAttributeResource\Pages\CreateProductAttribute;
use Webkul\Purchase\Filament\Admin\Clusters\Configurations\Resources\ProductAttributeResource\Pages\EditProductAttribute;
use Webkul\Purchase\Filament\Admin\Clusters\Configurations\Resources\ProductAttributeResource\Pages\ListProductAttributes;
use Webkul\Purchase\Filament\Admin\Clusters\Configurations\Resources\ProductAttributeResource\Pages\ViewProductAttribute;
use Webkul\Purchase\Models\Attribute;
use Webkul\Purchase\Settings\ProductSettings;
use Webkul\Support\Filament\Concerns\GatedByFeatureSetting;

class ProductAttributeResource extends AttributeResource
{
    use GatedByFeatureSetting;

    protected static ?string $model = Attribute::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = 9;

    protected static ?string $cluster = Configurations::class;

    protected static ?string $recordTitleAttribute = 'name';

    /** Feature toggle, checked per request by GatedByFeatureSetting::canAccess(). */
    protected static function isFeatureEnabled(): bool
    {
        return settings(ProductSettings::class)->enable_variants;
    }

    public static function getNavigationGroup(): string
    {
        return __('purchases::filament/admin/clusters/configurations/resources/product-attribute.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('purchases::filament/admin/clusters/configurations/resources/product-attribute.navigation.title');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProductAttributes::route('/'),
            'create' => CreateProductAttribute::route('/create'),
            'view'   => ViewProductAttribute::route('/{record}'),
            'edit'   => EditProductAttribute::route('/{record}/edit'),
        ];
    }
}
