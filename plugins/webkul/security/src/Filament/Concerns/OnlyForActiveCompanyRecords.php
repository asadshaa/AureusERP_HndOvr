<?php

namespace Webkul\Security\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Webkul\Security\Services\CurrentCompany;

/**
 * Record sub-page (e.g. ManageRelatedRecords) that changes the record's data:
 * only open for records of the active company. A resource using
 * ScopedToMemberCompanies lists other member companies' records view-only,
 * so their sub-pages stay closed (and drop out of the record sub-navigation).
 */
trait OnlyForActiveCompanyRecords
{
    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        if ($record instanceof Model && $record->getAttribute('company_id') !== null
            && (int) $record->getAttribute('company_id') !== CurrentCompany::id()) {
            return false;
        }

        return parent::canAccess($parameters);
    }
}
