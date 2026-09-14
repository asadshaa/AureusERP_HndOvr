<?php

namespace Webkul\Employee\Filament\Resources\EmployeeResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Webkul\Employee\Filament\Resources\EmployeeResource;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getRedirectUrl(): string
    {
        // Redirecting to 'view' unconditionally used to send the creator
        // straight into a 404: EmployeeResource::getEloquentQuery() scopes
        // by HrHierarchyService::visibleEmployeeIds() (yourself + your
        // reporting tree), and a newly created employee isn't
        // automatically placed under the creator in that hierarchy --
        // so a role without ViewAllRecords (e.g. HR Officer) couldn't see
        // the very record it just made. Found live during manual testing.
        // The list page is always visible to whoever has canViewAny(),
        // so it's the safe redirect target regardless of hierarchy
        // placement.
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title(__('employees::filament/resources/employee/pages/create-employee.notification.title'))
            ->body(__('employees::filament/resources/employee/pages/create-employee.notification.body'));
    }
}
