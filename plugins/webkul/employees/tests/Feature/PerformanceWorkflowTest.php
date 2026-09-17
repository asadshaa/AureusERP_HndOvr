<?php

/**
 * Section 5 ("IMPLEMENTATION SECTION 5 -- PERFORMANCE") -- exercises the
 * existing HR -> Launch Cycle -> Employee Self Review -> Line Manager
 * Review -> Completed workflow (PerformanceService + PerformanceCycle/
 * PerformanceReview), plus the two real gaps found and fixed while
 * inspecting it: no Policy existed for either model, so the generic
 * EditAction let any employee self-approve their own review (confirmed
 * empirically with a throwaway Livewire test before this file was
 * written), and the cycle's custom "launch" action had no permission gate
 * of its own.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource\Pages\ManagePerformanceCycles;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource\Pages\ManagePerformanceReviews;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function perfUser(Company $company, array $permissionNames, string $roleName): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($permissionNames as $name) {
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->assignRole($role);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

function perfFixture(): array
{
    $company = Company::factory()->create(['is_active' => true]);

    $hrUser = perfUser($company, [HrPermissions::ManagePerformance], 'perf_hr_'.uniqid());

    $managerUser = perfUser($company, [], 'perf_manager_'.uniqid());
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Line Manager', 'is_active' => true]);

    $employeeUser = perfUser($company, [], 'perf_employee_'.uniqid());
    $employee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id, 'name' => 'Employee', 'is_active' => true]);

    $cycle = PerformanceCycle::query()->create([
        'company_id' => $company->id, 'name' => 'Q1 Review', 'starts_on' => now(), 'ends_on' => now()->addMonths(3), 'status' => 'draft',
    ]);

    return compact('company', 'hrUser', 'managerUser', 'manager', 'employeeUser', 'employee', 'cycle');
}

// ---------------------------------------------------------------------
// Full happy-path workflow: HR launches -> employee self-reviews ->
// manager completes -> completed, with date range/goals/competencies/
// comments/improvement plan/promotion recommendation all retained.
// ---------------------------------------------------------------------
it('runs the full cycle: HR launches, employee self-reviews, manager completes, all fields retained', function () {
    $f = perfFixture();
    $service = app(PerformanceService::class);

    expect($f['cycle']->starts_on)->not->toBeNull()->and($f['cycle']->ends_on)->not->toBeNull();

    $reviews = $service->launch($f['cycle'], $f['hrUser']);
    expect($f['cycle']->fresh()->status)->toBe('active');

    $review = PerformanceReview::query()->where('cycle_id', $f['cycle']->id)->where('employee_id', $f['employee']->id)->firstOrFail();
    expect($review->status)->toBe('self_review')
        ->and($review->reviewer_id)->toBe($f['manager']->id);

    $review->goals()->create([
        'company_id' => $f['company']->id, 'title' => 'Ship feature X', 'weight' => 50, 'target_value' => 1, 'status' => 'in_progress',
    ]);
    expect($review->goals)->toHaveCount(1);

    $review = $service->submitSelfReview($review, $f['employee'], 4.0, 'I hit most of my goals this quarter.');
    expect($review->status)->toBe('manager_review')
        ->and((float) $review->self_rating)->toBe(4.0)
        ->and($review->self_comments)->toBe('I hit most of my goals this quarter.')
        ->and($review->submitted_at)->not->toBeNull();

    $review = $service->completeManagerReview($review, $f['manager'], 4.5, 'Strong quarter, exceeded expectations on X.', [
        'competency_ratings'       => ['communication' => 4, 'ownership' => 5],
        'improvement_plan'         => 'Focus on delegation next quarter.',
        'promotion_recommendation' => 'Ready for senior role in 2 cycles.',
    ]);

    expect($review->status)->toBe('completed')
        ->and((float) $review->manager_rating)->toBe(4.5)
        ->and($review->manager_comments)->toBe('Strong quarter, exceeded expectations on X.')
        ->and($review->competency_ratings)->toMatchArray(['communication' => 4, 'ownership' => 5])
        ->and($review->improvement_plan)->toBe('Focus on delegation next quarter.')
        ->and($review->promotion_recommendation)->toBe('Ready for senior role in 2 cycles.')
        ->and($review->completed_at)->not->toBeNull();
});

// ---------------------------------------------------------------------
// Employees must only complete their own self-review.
// ---------------------------------------------------------------------
it('refuses submitSelfReview() for anyone other than the review\'s own employee', function () {
    $f = perfFixture();
    $service = app(PerformanceService::class);
    app(PerformanceService::class)->launch($f['cycle'], $f['hrUser']);
    $review = PerformanceReview::query()->where('cycle_id', $f['cycle']->id)->where('employee_id', $f['employee']->id)->firstOrFail();

    $otherUser = perfUser($f['company'], [], 'perf_other_'.uniqid());
    $other = Employee::query()->create(['company_id' => $f['company']->id, 'user_id' => $otherUser->id, 'name' => 'Someone Else']);

    expect(fn () => $service->submitSelfReview($review, $other, 5.0))
        ->toThrow(RuntimeException::class, 'not available for employee self-review');
});

// ---------------------------------------------------------------------
// Managers must only review employees within their authorized hierarchy.
// ---------------------------------------------------------------------
it('refuses completeManagerReview() for anyone other than the assigned reviewer', function () {
    $f = perfFixture();
    $service = app(PerformanceService::class);
    app(PerformanceService::class)->launch($f['cycle'], $f['hrUser']);
    $review = PerformanceReview::query()->where('cycle_id', $f['cycle']->id)->where('employee_id', $f['employee']->id)->firstOrFail();
    $service->submitSelfReview($review, $f['employee'], 4.0);

    $otherUser = perfUser($f['company'], [], 'perf_stranger_'.uniqid());
    $stranger = Employee::query()->create(['company_id' => $f['company']->id, 'user_id' => $otherUser->id, 'name' => 'Unrelated Manager']);

    expect(fn () => $service->completeManagerReview($review, $stranger, 4.0))
        ->toThrow(RuntimeException::class, 'not the assigned manager reviewer');
});

// ---------------------------------------------------------------------
// The generic EditAction gap: confirmed exploitable before the fix, now
// blocked for a plain employee/manager, still available to HR.
// ---------------------------------------------------------------------
it('denies a plain employee the generic edit action on their own review (the confirmed pre-fix gap)', function () {
    $f = perfFixture();
    app(PerformanceService::class)->launch($f['cycle'], $f['hrUser']);
    $review = PerformanceReview::query()->where('cycle_id', $f['cycle']->id)->where('employee_id', $f['employee']->id)->firstOrFail();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($f['employeeUser']);

    Livewire::test(ManagePerformanceReviews::class)
        ->assertTableActionHidden('edit', $review)
        ->assertTableActionHidden('complete_manager_review', $review)
        ->assertTableActionVisible('submit_self_review', $review);
});

it('denies the assigned manager the generic edit action, but allows the dedicated complete_manager_review action', function () {
    $f = perfFixture();
    app(PerformanceService::class)->launch($f['cycle'], $f['hrUser']);
    $review = PerformanceReview::query()->where('cycle_id', $f['cycle']->id)->where('employee_id', $f['employee']->id)->firstOrFail();
    app(PerformanceService::class)->submitSelfReview($review, $f['employee'], 4.0);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($f['managerUser']);

    Livewire::test(ManagePerformanceReviews::class)
        ->assertTableActionHidden('edit', $review->fresh())
        ->assertTableActionVisible('complete_manager_review', $review->fresh())
        ->assertTableActionHidden('submit_self_review', $review->fresh());
});

it('allows HR (ManagePerformance) to use the generic edit action', function () {
    $f = perfFixture();
    app(PerformanceService::class)->launch($f['cycle'], $f['hrUser']);
    $review = PerformanceReview::query()->where('cycle_id', $f['cycle']->id)->where('employee_id', $f['employee']->id)->firstOrFail();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($f['hrUser']);

    Livewire::test(ManagePerformanceReviews::class)
        ->assertTableActionVisible('edit', $review);
});

// ---------------------------------------------------------------------
// Cycle launch is HR-only; a read-only (ViewPerformance) user cannot launch.
// ---------------------------------------------------------------------
it('denies launching a cycle to a user with only ViewPerformance (read-only) access', function () {
    $f = perfFixture();
    $readOnlyUser = perfUser($f['company'], [HrPermissions::ViewPerformance], 'perf_readonly_'.uniqid());

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($readOnlyUser);

    Livewire::test(ManagePerformanceCycles::class)
        ->assertTableActionHidden('launch', $f['cycle']);

    expect($f['cycle']->fresh()->status)->toBe('draft');
});

it('allows launching a cycle to a user with ManagePerformance', function () {
    $f = perfFixture();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    test()->actingAs($f['hrUser']);

    Livewire::test(ManagePerformanceCycles::class)
        ->assertTableActionVisible('launch', $f['cycle']);
});

// ---------------------------------------------------------------------
// Visibility respects company and hierarchy.
// ---------------------------------------------------------------------
it('scopes review visibility: employee sees only their own review, unrelated employee sees none', function () {
    $f = perfFixture();
    app(PerformanceService::class)->launch($f['cycle'], $f['hrUser']);

    $unrelatedUser = perfUser($f['company'], [], 'perf_unrelated_'.uniqid());
    Employee::query()->create(['company_id' => $f['company']->id, 'user_id' => $unrelatedUser->id, 'name' => 'Unrelated']);

    Auth::login($f['employeeUser']);
    $visibleIds = PerformanceReviewResource::getEloquentQuery()->pluck('employee_id')->all();
    expect($visibleIds)->toBe([$f['employee']->id]);

    Auth::login($unrelatedUser);
    $visibleIds = PerformanceReviewResource::getEloquentQuery()->pluck('id')->all();
    expect($visibleIds)->toBe([]);
});

it('denies cross-company review visibility entirely', function () {
    $f = perfFixture();
    app(PerformanceService::class)->launch($f['cycle'], $f['hrUser']);

    $otherCompany = Company::factory()->create(['is_active' => true]);
    $outsiderUser = perfUser($otherCompany, [HrPermissions::ManagePerformance], 'perf_outsider_'.uniqid());

    Auth::login($outsiderUser);
    $visibleIds = PerformanceReviewResource::getEloquentQuery()->pluck('id')->all();
    expect($visibleIds)->toBe([]);
});
