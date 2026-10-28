<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources\EmployeeCodeSequenceResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\EmployeeCodeSequenceResource;
use Webkul\Employee\Services\EmployeeCodeGenerator;
use Webkul\Security\Services\CurrentCompany;

class ListEmployeeCodeSequences extends ListRecords
{
    protected static string $resource = EmployeeCodeSequenceResource::class;

    public function mount(): void
    {
        parent::mount();

        // The active company's settings row, created with the default prefix on first visit.
        if (($companyId = CurrentCompany::id()) !== null) {
            app(EmployeeCodeGenerator::class)->sequenceFor($companyId);
        }
    }
}
