<?php

namespace Webkul\Security\Models;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission as BasePermission;
use Webkul\Security\PermissionRegistrar;

class Permission extends BasePermission
{
    /**
     * Get the current cached permissions.
     */
    protected static function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        $registrar = app(PermissionRegistrar::class);

        // Re-binding the class on every permission check (config write + container bind) is costly; do it only when it changes.
        if ($registrar->getPermissionClass() !== static::class) {
            $registrar->setPermissionClass(static::class);
        }

        return $registrar->getPermissions($params, $onlyOne);
    }
}
