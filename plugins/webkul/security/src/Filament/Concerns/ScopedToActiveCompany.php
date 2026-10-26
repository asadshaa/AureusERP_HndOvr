<?php

namespace Webkul\Security\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Webkul\Security\Services\CurrentCompany;

/**
 * Filament resource whose records belong to a company: lists and record pages
 * show only the active company's records (plus, when the resource sets
 * $includeSharedRecords, definitions with no company that every company uses).
 * Shared records can only be changed by a group owner.
 */
trait ScopedToActiveCompany
{
    public static function getEloquentQuery(): Builder
    {
        return CurrentCompany::scope(parent::getEloquentQuery(), static::includesSharedRecords());
    }

    public static function canEdit(Model $record): bool
    {
        return static::companyAllowsChange($record) && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::companyAllowsChange($record) && parent::canDelete($record);
    }

    protected static function includesSharedRecords(): bool
    {
        return property_exists(static::class, 'includeSharedRecords') && static::$includeSharedRecords;
    }

    protected static function companyAllowsChange(Model $record): bool
    {
        $companyId = $record->getAttribute('company_id');

        return $companyId === null
            ? CurrentCompany::isGroupOwner()
            : CurrentCompany::canAccess((int) $companyId);
    }
}
