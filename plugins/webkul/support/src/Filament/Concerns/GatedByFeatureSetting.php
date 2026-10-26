<?php

namespace Webkul\Support\Filament\Concerns;

/**
 * A resource shown only while a feature setting is on (e.g. "enable
 * locations"). The setting is checked on every request through canAccess(),
 * which Filament uses for page access, navigation, cluster sub-navigation and
 * global search. It must not live in isDiscovered(): discovery runs once when
 * the component cache is built (from the console), so a cached registry would
 * keep or drop the resource regardless of the current setting.
 */
trait GatedByFeatureSetting
{
    /** Whether the feature setting that enables this resource is on. */
    abstract protected static function isFeatureEnabled(): bool;

    public static function canAccess(): bool
    {
        return static::isFeatureEnabled() && parent::canAccess();
    }
}
