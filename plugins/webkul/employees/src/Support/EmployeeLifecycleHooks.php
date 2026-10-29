<?php

namespace Webkul\Employee\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Services\CelebrationService;
use Webkul\Employee\Services\Checklists\EmployeeChecklistService;
use Webkul\Support\Models\Company;

/**
 * Model hooks of the onboarding / exit checklists and the dashboard
 * celebrations, registered once from EmployeeServiceProvider. A failure
 * here is reported and never stops the save that triggered it.
 */
final class EmployeeLifecycleHooks
{
    /** Employee fields the birthdays / anniversaries card depends on. */
    private const CelebrationFields = ['name', 'birthday', 'show_birthday', 'joining_date', 'leaving_date', 'is_active', 'department_id', 'user_id', 'company_id'];

    public static function register(): void
    {
        // A new company starts with the default onboarding and exit templates
        // (after its default document types, which the templates link to).
        Company::created(fn (Company $company) => self::safely(function () use ($company): void {
            if (Schema::hasTable('employees_checklist_template_tasks')) {
                DefaultChecklistTemplates::provisionForCompany((int) $company->getKey());
            }
        }));

        // ... and the default appraisal form (HR Parity 3.2).
        Company::created(fn (Company $company) => self::safely(function () use ($company): void {
            if (Schema::hasTable('employees_appraisal_templates')) {
                DefaultAppraisalTemplate::provisionForCompany((int) $company->getKey());
            }
        }));

        Employee::created(fn (Employee $employee) => self::safely(fn () => app(EmployeeChecklistService::class)->autoStartOnboarding($employee)));

        Employee::saved(function (Employee $employee): void {
            self::safely(fn () => app(EmployeeChecklistService::class)->syncWithEmployee($employee));

            if ($employee->company_id && ($employee->wasRecentlyCreated || $employee->wasChanged(self::CelebrationFields))) {
                CelebrationService::forget((int) $employee->company_id);
            }
        });

        EmployeeDocument::created(fn (EmployeeDocument $document) => self::safely(fn () => app(EmployeeChecklistService::class)->completeForDocument($document)));

        // An effective-dated work schedule also completes "Add to attendance schedule".
        if (class_exists(WorkSchedule::class)) {
            WorkSchedule::created(fn (WorkSchedule $schedule) => self::safely(fn () => $schedule->employee_id
                ? app(EmployeeChecklistService::class)->completeForWorkSchedule((int) $schedule->employee_id)
                : null));
        }

        EmployeeRequest::saved(function (EmployeeRequest $request): void {
            if ($request->wasChanged('payload') || $request->wasRecentlyCreated) {
                self::safely(fn () => app(EmployeeChecklistService::class)->completeForLetterRequest($request));
            }
        });
    }

    private static function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
