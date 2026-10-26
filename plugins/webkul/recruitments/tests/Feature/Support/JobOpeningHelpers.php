<?php

use Database\Seeders\HrRoleSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Database\Seeders\JobOpeningWorkflowSeeder;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Services\JobOpeningPublicationService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Shared helpers for tests that need a job opening to pass through its
 * approval (Recruitment Stage 2.1). Not a test file itself; require_once it.
 */
function jobOpeningUser(Company $company, string ...$permissions): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/**
 * A recruiter (may edit jobs) whose line manager is a separate user, plus the
 * company's job opening approval workflow.
 *
 * @return array{recruiter: User, manager: User}
 */
function jobOpeningApprovalChain(Company $company): array
{
    app(JobOpeningWorkflowSeeder::class)->provisionForCompany($company);

    $manager = jobOpeningUser($company);
    $managerEmployee = Employee::query()->create([
        'company_id' => $company->id, 'user_id' => $manager->id, 'name' => 'Manager '.Str::random(4),
        'is_active'  => true, 'employment_status' => 'active',
    ]);

    $recruiter = jobOpeningUser($company, 'update_recruitment_job::position', 'view_recruitment_job::position', 'view_any_recruitment_job::position');
    Employee::query()->create([
        'company_id' => $company->id, 'user_id' => $recruiter->id, 'name' => 'Recruiter '.Str::random(4),
        'is_active'  => true, 'employment_status' => 'active', 'parent_id' => $managerEmployee->id,
    ]);

    return ['recruiter' => $recruiter, 'manager' => $manager];
}

/** Submits the job and has the recruiter's line manager approve it. */
function approveJobOpening(JobPosition $job, Company $company): ApprovalRequest
{
    $chain = jobOpeningApprovalChain($company);
    $request = app(JobOpeningPublicationService::class)->submitForApproval($job, $chain['recruiter']);

    return app(ApprovalEngine::class)->approve($request, $chain['manager'], 'Headcount approved');
}

/** Submits, approves and publishes the job; returns the recruiter who published it. */
function publishApprovedJobOpening(JobPosition $job, Company $company): User
{
    $chain = jobOpeningApprovalChain($company);
    $request = app(JobOpeningPublicationService::class)->submitForApproval($job, $chain['recruiter']);
    app(ApprovalEngine::class)->approve($request, $chain['manager'], 'Headcount approved');
    app(JobOpeningPublicationService::class)->publish($job->refresh(), $chain['recruiter']);

    return $chain['recruiter'];
}

/** A user in $company holding a seeded HR role (e.g. recruiter, hiring_manager). */
function jobOpeningRoleUser(Company $company, string $roleName): User
{
    app(HrRoleSeeder::class)->run();
    $role = Role::query()->whereRaw('LOWER(name) = ?', [$roleName])->firstOrFail();

    $user = jobOpeningUser($company);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}
