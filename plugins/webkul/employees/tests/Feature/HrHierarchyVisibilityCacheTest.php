<?php

/**
 * HrHierarchyService remembers visibleEmployeeIds() per request (the Employees
 * list asks it 5+ times per row). The memo must never hide a hierarchy change
 * made earlier in the same request, and must stay per user.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function visibilityUser(Company $company): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user->refresh();
}

function visibilityEmployee(Company $company, ?User $user = null, ?Employee $manager = null): Employee
{
    return Employee::query()->create([
        'company_id' => $company->id, 'user_id' => $user?->id, 'name' => 'Emp '.Str::random(5),
        'is_active'  => true, 'employment_status' => 'active', 'parent_id' => $manager?->id,
    ]);
}

it('answers repeated visibility checks from one lookup per user and company', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $managerUser = visibilityUser($company);
    $manager = visibilityEmployee($company, $managerUser);
    $reports = collect(range(1, 5))->map(fn () => visibilityEmployee($company, null, $manager));
    $service = app(HrHierarchyService::class);

    $service->visibleEmployeeIds($managerUser, $company->id);
    DB::flushQueryLog();
    DB::enableQueryLog();
    foreach ($reports as $report) {
        expect($service->canManage($managerUser, $report))->toBeTrue();
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and(app(HrHierarchyService::class))->toBe($service);
});

it('sees a hierarchy change made earlier in the same request, and keeps users apart', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $managerUser = visibilityUser($company);
    $manager = visibilityEmployee($company, $managerUser);
    $otherUser = visibilityUser($company);
    visibilityEmployee($company, $otherUser);
    $service = app(HrHierarchyService::class);

    $newcomer = visibilityEmployee($company);
    expect($service->canManage($managerUser, $newcomer))->toBeFalse();

    // Moving the newcomer under the manager must be visible immediately.
    $newcomer->update(['parent_id' => $manager->id]);
    expect($service->canManage($managerUser, $newcomer->refresh()))->toBeTrue()
        ->and($service->canManage($otherUser, $newcomer))->toBeFalse();

    // A brand-new report created in the same request is seen too.
    $another = visibilityEmployee($company, null, $manager);
    expect($service->canManage($managerUser, $another))->toBeTrue();
});
