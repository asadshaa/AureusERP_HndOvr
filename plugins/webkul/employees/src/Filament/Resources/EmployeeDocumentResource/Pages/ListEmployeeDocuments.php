<?php

namespace Webkul\Employee\Filament\Resources\EmployeeDocumentResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Resources\EmployeeDocumentResource;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

class ListEmployeeDocuments extends ListRecords
{
    protected static string $resource = EmployeeDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('missingRequired')
                ->label(fn (): string => 'Missing required documents ('.$this->missingRequiredCount().')')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->url(fn (): string => EmployeeResource::getUrl('index', [
                    'filters' => ['missing_required_documents' => ['isActive' => true]],
                ])),
        ];
    }

    /** Active employees of the active company in the user's HR scope missing at least one required document. */
    private function missingRequiredCount(): int
    {
        $user = Auth::user();
        $companyId = CurrentCompany::id();

        if (! $user instanceof User || $companyId === null) {
            return 0;
        }

        return Employee::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('id', app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId)->all() ?: [0])
            ->missingRequiredDocuments()
            ->count();
    }
}
