<?php

/**
 * Appraisal forms on the performance cycles (HR Parity 3.2): per-company
 * templates (seeded once per company), HR launching a cycle, the self ->
 * manager -> calibration -> acknowledgement flow, the weighted score and its
 * band, who sees what and when, HR standing in for a missing line manager,
 * the daily reminders (once each), company isolation, the PDF, the Excel
 * export and the home dashboard's pending actions.
 */

use App\Services\Dashboard\HomeDashboardContext;
use App\Services\Dashboard\RoleActionCenterService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\AppraisalTemplateResource;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\AppraisalTemplateResource\Pages\ListAppraisalTemplates;
use Webkul\Employee\Filament\Pages\MyAppraisals;
use Webkul\Employee\Filament\Pages\TeamAppraisals;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource\Pages\CycleOverview;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource\Pages\ManagePerformanceCycles;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource\Pages\ViewAppraisal;
use Webkul\Employee\Models\AppraisalTemplate;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\AnnouncementService;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Services\Appraisals\AppraisalCycleExport;
use Webkul\Employee\Services\Appraisals\AppraisalScoring;
use Webkul\Employee\Services\Appraisals\AppraisalSheet;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Employee\Support\DefaultAppraisalTemplate;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

beforeEach(function () {
    config([
        'attendance.monthly_report.timezone' => 'Asia/Karachi',
        'appraisals.reminder_days_before'    => 3,
        'appraisals.overdue_repeat_days'     => 7,
    ]);
    // Monday 2 Nov 2026, 10:00 in Karachi.
    test()->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Karachi')->utc());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

/** @param  array<int, string>  $permissions */
function aprUser(Company $company, array $permissions = [], array $extraCompanies = []): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id, ...array_map(fn (Company $other): int => $other->id, $extraCompanies)]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    CurrentCompany::flush();

    return $user->fresh();
}

/** Appraisal HR of the company and one of its HR approvers. */
function aprHr(Company $company, array $extraCompanies = []): User
{
    return aprUser($company, [HrPermissions::ViewAllRecords, HrPermissions::ManagePerformance, HrPermissions::ApproveLeave], $extraCompanies);
}

function aprEmployee(Company $company, string $name, ?User $user = null, ?Employee $manager = null, ?Department $department = null): Employee
{
    return Employee::query()->create([
        'company_id'    => $company->id,
        'user_id'       => $user?->id,
        'parent_id'     => $manager?->id,
        'department_id' => $department?->id,
        'name'          => $name,
        'is_active'     => true,
    ]);
}

/**
 * A company with HR, a line manager with one report, an employee without a
 * line manager, and a draft cycle on the default template.
 *
 * @return array<string, mixed>
 */
function aprFixture(string $name = 'Appraisal Co'): array
{
    $company = Company::factory()->create(['name' => $name.' '.uniqid(), 'is_active' => true]);

    $hrUser = aprHr($company);
    $hr = aprEmployee($company, 'Hina HR', $hrUser);

    // A line manager holds hr_manage_performance through the manager role bundle, but no company-wide scope.
    $managerUser = aprUser($company, [HrPermissions::ManagePerformance]);
    $manager = aprEmployee($company, 'Kamran Manager', $managerUser);

    $employeeUser = aprUser($company);
    $employee = aprEmployee($company, 'Sana Employee', $employeeUser, $manager);

    $colleagueUser = aprUser($company);
    $colleague = aprEmployee($company, 'Bilal Colleague', $colleagueUser, $manager);

    $orphanUser = aprUser($company);
    $orphan = aprEmployee($company, 'Omar No Manager', $orphanUser);

    $cycle = PerformanceCycle::query()->create([
        'company_id'            => $company->id,
        'name'                  => 'Annual appraisal 2026',
        'starts_on'             => '2026-01-01',
        'ends_on'               => '2026-12-31',
        'status'                => AppraisalKinds::CycleDraft,
        'template_id'           => AppraisalTemplate::query()->where('company_id', $company->id)->where('code', DefaultAppraisalTemplate::Code)->value('id'),
        'self_review_due_on'    => '2026-11-06',
        'manager_review_due_on' => '2026-11-13',
    ]);

    return compact('company', 'hrUser', 'hr', 'managerUser', 'manager', 'employeeUser', 'employee', 'colleagueUser', 'colleague', 'orphanUser', 'orphan', 'cycle');
}

/** @return array<string, array{rating: int, comment: ?string}> */
function aprAnswers(array $ratings, ?string $comment = null): array
{
    return ['items' => collect($ratings)->map(fn (int $rating): array => ['rating' => $rating, 'comment' => $comment])->all()];
}

/** Manager ratings of the default template that score 3.95 (weights 20/20/20/10/10/10/5/5). */
function aprManagerRatings(): array
{
    return ['quality' => 5, 'delivery' => 4, 'goals' => 4, 'knowledge' => 3, 'communication' => 3, 'teamwork' => 4, 'ownership' => 5, 'attendance' => 2];
}

function aprAllFours(): array
{
    return array_fill_keys(['quality', 'delivery', 'goals', 'knowledge', 'communication', 'teamwork', 'ownership', 'attendance'], 4);
}

function aprReview(PerformanceCycle $cycle, Employee $employee): PerformanceReview
{
    return PerformanceReview::query()->where('cycle_id', $cycle->id)->where('employee_id', $employee->id)->firstOrFail();
}

function aprNotifications(User $user, string $needle): int
{
    return DB::table('notifications')
        ->where('notifiable_id', $user->id)
        ->where('notifiable_type', $user->getMorphClass())
        ->where('data', 'like', '%'.$needle.'%')
        ->count();
}

it('gives every company one default template, idempotently, including companies created later', function () {
    $company = Company::factory()->create(['is_active' => true]);

    $templates = AppraisalTemplate::query()->where('company_id', $company->id)->get();
    expect($templates)->toHaveCount(1)
        ->and($templates->first()->code)->toBe(DefaultAppraisalTemplate::Code)
        ->and($templates->first()->is_default)->toBeTrue()
        ->and($templates->first()->totalWeight())->toBe(100.0)
        ->and($templates->first()->itemCount())->toBe(8);

    expect(DefaultAppraisalTemplate::provisionForCompany($company->id))->toBeFalse()
        ->and(DefaultAppraisalTemplate::provisionForCompany($company->id))->toBeFalse();

    expect(AppraisalTemplate::query()->where('company_id', $company->id)->count())->toBe(1);
});

it('runs the appraisal end to end: launch, self review, manager review, calibration, close, acknowledgement', function () {
    $f = aprFixture();
    $service = app(PerformanceService::class);
    $access = app(AppraisalAccess::class);

    $reviews = $service->launch($f['cycle'], $f['hrUser']);
    $cycle = $f['cycle']->fresh();

    expect($cycle->status)->toBe(AppraisalKinds::CycleActive)
        ->and($cycle->template_snapshot['sections'])->toHaveCount(2)
        ->and($cycle->launched_by)->toBe($f['hrUser']->id)
        ->and($reviews)->toHaveCount(5);

    $review = aprReview($cycle, $f['employee']);
    expect($review->status)->toBe(AppraisalKinds::StatusSelfReview)
        ->and($review->reviewer_id)->toBe($f['manager']->id)
        ->and(aprNotifications($f['employeeUser'], 'Performance review cycle is now open'))->toBe(1)
        ->and(aprNotifications($f['managerUser'], '2 reports to review'))->toBe(1);

    // A later template edit does not change the running cycle.
    AppraisalTemplate::query()->whereKey($cycle->template_id)->first()->update(['sections' => [['title' => 'Changed', 'items' => [['title' => 'Only item', 'weight' => 1]]]]]);
    expect(AppraisalScoring::items($cycle->fresh()))->toHaveCount(8);

    // Draft, then an incomplete submit is refused, then the full self review.
    $service->saveSelfReview($review, $f['employeeUser'], aprAnswers(['quality' => 4]), submit: false);
    expect($review->fresh()->status)->toBe(AppraisalKinds::StatusSelfReview)
        ->and($access->canSeeSelfReview($f['managerUser'], $review->fresh()))->toBeFalse();

    expect(fn () => $service->saveSelfReview($review, $f['employeeUser'], aprAnswers(['quality' => 4]), submit: true))
        ->toThrow(RuntimeException::class, 'Rate every item');

    $review = $service->saveSelfReview($review, $f['employeeUser'], aprAnswers(aprAllFours(), 'Did well') + ['comments' => 'A good year'], submit: true);
    expect($review->status)->toBe(AppraisalKinds::StatusManagerReview)
        ->and((float) $review->self_rating)->toBe(4.0)
        ->and($review->self_comments)->toBe('A good year')
        ->and(aprNotifications($f['managerUser'], 'Self-review submitted'))->toBe(1);

    // Only the reviewer writes the manager review; the employee and a colleague cannot.
    expect(fn () => $service->saveManagerReview($review, $f['employeeUser'], aprAnswers(aprManagerRatings()), true))->toThrow(RuntimeException::class)
        ->and(fn () => $service->saveManagerReview($review, $f['colleagueUser'], aprAnswers(aprManagerRatings()), true))->toThrow(RuntimeException::class)
        ->and(fn () => $service->saveManagerReview($review, $f['hrUser'], aprAnswers(aprManagerRatings()), true))->toThrow(RuntimeException::class);

    $review = $service->saveManagerReview($review, $f['managerUser'], aprAnswers(aprManagerRatings()) + [
        'comments'                 => 'Strong delivery, watch punctuality',
        'improvement_plan'         => 'Arrive on time',
        'promotion_recommendation' => 'Consider for senior role',
        'goals'                    => [['title' => 'Lead the Q1 route review', 'due_date' => '2027-03-31', 'weight' => 50], ['title' => '']],
    ], submit: true);

    expect($review->status)->toBe(AppraisalKinds::StatusCompleted)
        ->and((float) $review->manager_rating)->toBe(3.95)
        ->and((float) $review->final_rating)->toBe(3.95)
        ->and($review->final_band)->toBe('Exceeds expectations')
        ->and($review->manager_reviewed_by)->toBe($f['managerUser']->id)
        ->and($review->nextPeriodGoals()->pluck('title')->all())->toBe(['Lead the Q1 route review'])
        ->and(aprNotifications($f['employeeUser'], 'You will see it when HR closes the cycle'))->toBe(1);

    // Default: the employee sees the manager review only once HR closes the cycle.
    expect($access->canSeeManagerReview($f['employeeUser'], $review))->toBeFalse()
        ->and($access->canAcknowledge($f['employeeUser'], $review))->toBeFalse()
        ->and($access->canSeeManagerReview($f['managerUser'], $review))->toBeTrue();

    // HR calibration: a reason is required, the line manager cannot, the manager score is kept.
    expect(fn () => $service->calibrate($review, $f['hrUser'], 4.6, ''))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $service->calibrate($review, $f['managerUser'], 4.6, 'Not HR'))->toThrow(RuntimeException::class);

    $review = $service->calibrate($review, $f['hrUser'], 4.6, 'Delivered the warehouse migration single-handed');
    expect((float) $review->final_rating)->toBe(4.6)
        ->and($review->final_band)->toBe('Outstanding')
        ->and((float) $review->manager_rating)->toBe(3.95)
        ->and($review->calibrated_by)->toBe($f['hrUser']->id);

    $result = $service->closeCycle($cycle, $f['hrUser']);
    expect($result)->toBe(['shared' => 1, 'unfinished' => 4])
        ->and(aprNotifications($f['employeeUser'], 'Your appraisal is ready'))->toBe(1);

    $review = $review->fresh();
    expect($access->canSeeManagerReview($f['employeeUser'], $review))->toBeTrue()
        ->and($access->canCalibrate($f['hrUser'], $review))->toBeFalse();

    $review = $service->acknowledge($review, $f['employeeUser'], 'Thanks, agreed.');
    expect($review->status)->toBe(AppraisalKinds::StatusAcknowledged)
        ->and($review->acknowledgement_comment)->toBe('Thanks, agreed.')
        ->and($review->acknowledged_at)->not->toBeNull()
        ->and(aprNotifications($f['managerUser'], 'acknowledged their appraisal'))->toBe(1);

    expect(fn () => $service->acknowledge($review->fresh(), $f['employeeUser']))->toThrow(RuntimeException::class);

    $stats = $service->cycleStats($cycle->fresh());
    expect($stats['done'])->toBe(1)
        ->and($stats['total'])->toBe(5)
        ->and(collect($stats['bands'])->firstWhere('label', 'Outstanding')['count'])->toBe(1);
});

it('scores the weighted average and maps the configurable bands', function () {
    $items = [
        'a' => ['weight' => 50.0],
        'b' => ['weight' => 30.0],
        'c' => ['weight' => 20.0],
        'd' => ['weight' => 0.0],
    ];

    expect(AppraisalScoring::weightedScore($items, ['a' => 5, 'b' => 3, 'c' => 1, 'd' => 1]))->toBe(3.6)
        ->and(AppraisalScoring::weightedScore($items, ['a' => 4]))->toBe(4.0)
        ->and(AppraisalScoring::weightedScore($items, []))->toBeNull()
        ->and(AppraisalScoring::weightedScore(['x' => ['weight' => 0.0], 'y' => ['weight' => 0.0]], ['x' => 2, 'y' => 5]))->toBe(3.5)
        ->and(AppraisalScoring::weightedScore($items, ['a' => 7, 'b' => 0]))->toBeNull();

    $bands = AppraisalKinds::defaultRatingBands();
    expect(AppraisalScoring::band(4.5, $bands)['label'])->toBe('Outstanding')
        ->and(AppraisalScoring::band(4.49, $bands)['label'])->toBe('Exceeds expectations')
        ->and(AppraisalScoring::band(2.5, $bands)['label'])->toBe('Meets expectations')
        ->and(AppraisalScoring::band(1.0, $bands)['label'])->toBe('Unsatisfactory')
        ->and(AppraisalScoring::band(null, $bands))->toBeNull();

    // A company's own bands (stored in any order) and scale labels go with the cycle.
    $f = aprFixture();
    $template = AppraisalTemplate::query()->findOrFail($f['cycle']->template_id);
    $template->update([
        'rating_bands' => [['min' => 0, 'label' => 'C', 'color' => 'danger'], ['min' => 4, 'label' => 'A', 'color' => 'success'], ['min' => 3, 'label' => 'B', 'color' => 'info']],
        'rating_scale' => [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'],
    ]);
    app(PerformanceService::class)->launch($f['cycle'], $f['hrUser']);
    $cycle = $f['cycle']->fresh();

    expect(collect(AppraisalScoring::bands($cycle))->pluck('label')->all())->toBe(['A', 'B', 'C'])
        ->and(AppraisalScoring::scale($cycle)[5])->toBe('Excellent');

    $review = app(PerformanceService::class)->saveSelfReview(aprReview($cycle, $f['employee']), $f['employeeUser'], aprAnswers(aprAllFours()), true);
    $review = app(PerformanceService::class)->saveManagerReview($review, $f['managerUser'], aprAnswers(aprManagerRatings()), true);

    expect($review->final_band)->toBe('B');
});

it('lets the employee see only their own appraisal, and the manager review only once it is shared', function () {
    $f = aprFixture();
    $service = app(PerformanceService::class);
    $access = app(AppraisalAccess::class);
    $service->launch($f['cycle'], $f['hrUser']);
    $review = aprReview($f['cycle'], $f['employee']);
    $colleagueReview = aprReview($f['cycle'], $f['colleague']);

    // Lists: own only for the employee; the reports for the manager; the company for HR.
    test()->actingAs($f['employeeUser']);
    expect($access->visibleQuery($f['employeeUser'])->pluck('employee_id')->all())->toBe([$f['employee']->id]);
    expect($access->teamQuery($f['employeeUser'], $f['company']->id)->count())->toBe(0);

    expect($access->teamQuery($f['managerUser'], $f['company']->id)->pluck('employee_id')->sort()->values()->all())
        ->toBe(collect([$f['employee']->id, $f['colleague']->id])->sort()->values()->all())
        ->and($access->visibleQuery($f['managerUser'])->pluck('employee_id')->all())->not->toContain($f['orphan']->id, $f['hr']->id);

    expect($access->visibleQuery($f['hrUser'])->count())->toBe(5);

    // Pages: the colleague's appraisal is refused to the employee.
    test()->get(aprUrl($review))->assertOk()->assertSee('Your self review');
    expect(test()->get(aprUrl($colleagueReview))->status())->toBeIn([403, 404]);
    test()->get(MyAppraisals::getUrl())->assertOk()->assertSee('Annual appraisal 2026')->assertDontSee('Bilal Colleague');
    test()->get(TeamAppraisals::getUrl())->assertForbidden();

    $service->saveSelfReview($review, $f['employeeUser'], aprAnswers(aprAllFours()), true);
    $service->saveManagerReview($review->fresh(), $f['managerUser'], aprAnswers(aprManagerRatings()) + ['comments' => 'Manager secret note'], true);

    // Reviewed, cycle still running: the employee does not see the manager's comments or score.
    test()->actingAs($f['employeeUser']);
    test()->get(aprUrl($review))->assertOk()
        ->assertDontSee('Manager secret note')
        ->assertDontSee('3.95')
        ->assertSee('when HR closes the cycle');
    expect(test()->get(route('employees.appraisals.pdf', ['review' => $review->id]))->status())->toBe(403);

    test()->actingAs($f['managerUser']);
    test()->get(aprUrl($review))->assertOk()->assertSee('Manager secret note')->assertSee('3.95');
    test()->get(TeamAppraisals::getUrl())->assertOk()->assertSee('Sana Employee')->assertDontSee('Omar No Manager');

    // The cycle can share results as soon as the manager submits.
    $f['cycle']->fresh()->update(['results_visibility' => AppraisalKinds::VisibleAfterManagerReview]);
    test()->actingAs($f['employeeUser']);
    test()->get(aprUrl($review))->assertOk()->assertSee('Manager secret note')->assertSee('3.95');
    expect($access->canAcknowledge($f['employeeUser'], $review->fresh()))->toBeTrue();
});

it('sends the manager review to HR when the employee has no line manager who can log in', function () {
    $f = aprFixture();
    $service = app(PerformanceService::class);
    $access = app(AppraisalAccess::class);

    // A manager without a login also counts as no manager.
    $noLoginManager = aprEmployee($f['company'], 'Manager Without Login');
    $driver = aprEmployee($f['company'], 'Driver With Login', aprUser($f['company']), $noLoginManager);

    $service->launch($f['cycle'], $f['hrUser']);
    $review = aprReview($f['cycle'], $f['orphan']);
    $driverReview = aprReview($f['cycle'], $driver);
    $noLoginReview = aprReview($f['cycle'], $noLoginManager);

    expect($review->reviewer_id)->toBeNull()
        ->and($access->hasManager($review))->toBeFalse()
        ->and($access->hasManager($driverReview))->toBeFalse()
        ->and(aprNotifications($f['hrUser'], 'without a line manager'))->toBe(1)
        // No login: straight to the manager review.
        ->and($noLoginReview->status)->toBe(AppraisalKinds::StatusManagerReview)
        ->and($noLoginReview->self_skipped_at)->not->toBeNull();

    $service->saveSelfReview($review, $f['orphanUser'], aprAnswers(aprAllFours()), true);
    expect(aprNotifications($f['hrUser'], 'Routed to HR'))->toBe(1);

    expect($access->canEditManagerReview($f['hrUser'], $review->fresh()))->toBeTrue()
        ->and($access->canEditManagerReview($f['managerUser'], $review->fresh()))->toBeFalse()
        ->and($access->teamQuery($f['hrUser'], $f['company']->id)->pluck('employee_id')->all())->toContain($f['orphan']->id, $driver->id)
        ->and($access->teamQuery($f['managerUser'], $f['company']->id)->pluck('employee_id')->all())->not->toContain($f['orphan']->id);

    $review = $service->saveManagerReview($review->fresh(), $f['hrUser'], aprAnswers(aprManagerRatings()), true);
    expect($review->status)->toBe(AppraisalKinds::StatusCompleted)
        ->and($review->manager_reviewed_by)->toBe($f['hrUser']->id);

    // HR never reviews their own appraisal, even without a manager.
    $hrReview = aprReview($f['cycle'], $f['hr']);
    expect($access->canEditSelfReview($f['hrUser'], $hrReview))->toBeTrue()
        ->and($access->canCalibrate($f['hrUser'], $hrReview))->toBeFalse();
    $service->saveSelfReview($hrReview, $f['hrUser'], aprAnswers(aprAllFours()), true);
    expect($access->canEditManagerReview($f['hrUser'], $hrReview->fresh()))->toBeFalse();

    // HR hands an appraisal to another reviewer.
    $service->reassignReviewer($driverReview, $f['hrUser'], $f['manager']->id);
    expect($access->hasManager($driverReview->fresh()))->toBeTrue();
    expect(fn () => $service->reassignReviewer($driverReview, $f['managerUser'], null))->toThrow(RuntimeException::class);
});

it('reminds before the deadline and when overdue, each reminder once', function () {
    $f = aprFixture();
    $service = app(PerformanceService::class);
    $service->launch($f['cycle'], $f['hrUser']);
    $service->saveSelfReview(aprReview($f['cycle'], $f['colleague']), $f['colleagueUser'], aprAnswers(aprAllFours()), true);

    $reminders = fn (string $date) => test()->artisan('hr:appraisal-reminders', ['--company' => $f['company']->id, '--date' => $date])->assertSuccessful()->run();

    // 4 Nov: self reviews due 6 Nov (2 days) -> due soon; the manager review (due 13 Nov) not yet.
    $reminders('2026-11-04');
    expect(aprNotifications($f['employeeUser'], 'appraisal_reminder'))->toBe(1)
        ->and(aprNotifications($f['employeeUser'], 'Appraisal due soon'))->toBe(1)
        ->and(aprNotifications($f['managerUser'], 'appraisal_reminder'))->toBe(1); // his own self review only

    // Same day again, and the next day: nothing new.
    $reminders('2026-11-04');
    $reminders('2026-11-05');
    expect(aprNotifications($f['employeeUser'], 'appraisal_reminder'))->toBe(1);

    // 7 Nov: overdue, once.
    $reminders('2026-11-07');
    $reminders('2026-11-07');
    expect(aprNotifications($f['employeeUser'], 'Appraisal overdue'))->toBe(1);

    // 10 Nov: the manager review of the colleague is due 13 Nov -> one due-soon to the manager.
    $before = aprNotifications($f['managerUser'], 'appraisal_reminder');
    $reminders('2026-11-10');
    expect(aprNotifications($f['managerUser'], 'appraisal_reminder'))->toBe($before + 1)
        ->and(aprNotifications($f['employeeUser'], 'appraisal_reminder'))->toBe(2);

    // 14 Nov: a week after the first overdue reminder, the employee is reminded again.
    $reminders('2026-11-14');
    expect(aprNotifications($f['employeeUser'], 'Appraisal overdue'))->toBe(2);

    // HR moves the deadline: the reminders start again for the new date.
    $f['cycle']->fresh()->update(['self_review_due_on' => '2026-11-20']);
    $reminders('2026-11-18');
    $reminders('2026-11-18');
    expect(aprNotifications($f['employeeUser'], 'Appraisal due soon'))->toBe(2);

    // A closed cycle reminds nobody.
    $service->closeCycle($f['cycle']->fresh(), $f['hrUser']);
    $count = DB::table('employees_performance_review_reminders')->count();
    $reminders('2026-11-25');
    expect(DB::table('employees_performance_review_reminders')->count())->toBe($count);
});

it('keeps appraisals inside their company', function () {
    $f = aprFixture();
    $other = Company::factory()->create(['is_active' => true]);
    $outsiderHr = aprHr($other);
    // HR of both companies, working in the other one right now.
    $dualHr = aprHr($other, [$f['company']]);
    $service = app(PerformanceService::class);
    $access = app(AppraisalAccess::class);

    $service->launch($f['cycle'], $f['hrUser']);
    $review = aprReview($f['cycle'], $f['employee']);
    $service->saveSelfReview($review, $f['employeeUser'], aprAnswers(aprAllFours()), true);
    $review = $service->saveManagerReview($review->fresh(), $f['managerUser'], aprAnswers(aprManagerRatings()), true);

    foreach ([$outsiderHr, $dualHr] as $user) {
        expect($access->visibleQuery($user)->whereKey($review->id)->exists())->toBeFalse()
            ->and($access->canView($user, $review))->toBeFalse()
            ->and($access->canViewCycle($user, $f['cycle']->fresh()))->toBeFalse()
            ->and(fn () => $service->calibrate($review, $user, 4.0, 'Should not be allowed'))->toThrow(RuntimeException::class)
            ->and(fn () => $service->closeCycle($f['cycle']->fresh(), $user))->toThrow(RuntimeException::class);

        test()->actingAs($user);
        expect(test()->get(aprUrl($review))->status())->toBeIn([403, 404])
            ->and(test()->get(route('employees.appraisals.pdf', ['review' => $review->id]))->status())->toBe(403)
            ->and(test()->get(CycleOverview::getUrl(['record' => $f['cycle']]))->status())->toBeIn([403, 404]);

        expect(fn () => app(AppraisalCycleExport::class)->spreadsheet($user, $f['cycle']->fresh()))
            ->toThrow(HttpException::class);
    }

    // Switched to the company, the dual HR sees it.
    $dualHr->forceFill(['default_company_id' => $f['company']->id])->save();
    CurrentCompany::flush();
    expect($access->canView($dualHr->fresh(), $review))->toBeTrue();

    // A line manager's hr_manage_performance is not HR: no cycles, no company-wide list.
    test()->actingAs($f['managerUser']);
    expect(PerformanceCycleResource::canViewAny())->toBeFalse()
        ->and($f['managerUser']->can('launch', $f['cycle']->fresh()))->toBeFalse()
        ->and($access->visibleQuery($f['managerUser'])->pluck('employee_id')->all())->not->toContain($f['orphan']->id);
});

it('renders the PDF and the Excel export, and the HR overview', function () {
    $f = aprFixture();
    $service = app(PerformanceService::class);
    $service->launch($f['cycle'], $f['hrUser']);
    $review = aprReview($f['cycle'], $f['employee']);
    $service->saveSelfReview($review, $f['employeeUser'], aprAnswers(aprAllFours(), 'Self comment'), true);
    $service->saveManagerReview($review->fresh(), $f['managerUser'], aprAnswers(aprManagerRatings()) + ['promotion_recommendation' => 'Promote next year'], true);
    $service->calibrate($review->fresh(), $f['hrUser'], 4.2, 'Calibrated across the department');

    test()->actingAs($f['hrUser']);
    $response = test()->get(route('employees.appraisals.pdf', ['review' => $review->id]));
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');

    // Manager review not finished yet: no PDF.
    expect(test()->get(route('employees.appraisals.pdf', ['review' => aprReview($f['cycle'], $f['colleague'])->id]))->status())->toBe(403);

    $service->closeCycle($f['cycle']->fresh(), $f['hrUser']);
    test()->actingAs($f['employeeUser']);
    $employeePdf = test()->get(route('employees.appraisals.pdf', ['review' => $review->id]));
    $employeePdf->assertOk();
    expect(substr((string) $employeePdf->getContent(), 0, 4))->toBe('%PDF');

    // The sheet the employee's PDF is built from has no calibration reason or promotion note.
    $sheet = app(AppraisalSheet::class)->build($review->fresh(), $f['employeeUser']);
    expect($sheet['calibration'])->toBeNull()
        ->and($sheet['promotion_recommendation'])->toBeNull()
        ->and($sheet['final_score'])->toBe(4.2);

    $spreadsheet = app(AppraisalCycleExport::class)->spreadsheet($f['hrUser'], $f['cycle']->fresh());
    $rows = $spreadsheet->getSheetByName('Appraisals')->toArray();
    $sana = collect($rows)->first(fn (array $row): bool => $row[1] === 'Sana Employee');
    expect($rows[0][1])->toBe('Employee')
        ->and($rows)->toHaveCount(6)
        ->and((float) $sana[8])->toBe(3.95)
        ->and((float) $sana[10])->toBe(4.2)
        ->and($sana[11])->toBe('Exceeds expectations')
        ->and($spreadsheet->getSheetByName('Summary')->getCell('B5')->getValue())->toBe(1);

    test()->actingAs($f['hrUser']);
    Livewire::test(CycleOverview::class, ['record' => $f['cycle']->id])
        ->assertOk()
        ->assertSee('Rating distribution')
        ->assertSee('Sana Employee')
        ->assertSee('Omar No Manager');
});

it('fills in the self and manager review on the appraisal page', function () {
    $f = aprFixture();
    app(PerformanceService::class)->launch($f['cycle'], $f['hrUser']);
    $review = aprReview($f['cycle'], $f['employee']);

    test()->actingAs($f['employeeUser']);
    $page = Livewire::test(ViewAppraisal::class, ['record' => $review->id]);
    foreach (aprAllFours() as $key => $rating) {
        $page->set("selfData.items.{$key}.rating", $rating);
    }
    $page->set('selfData.comments', 'Typed on the page')->call('saveSelf', true);

    $review = $review->fresh();
    expect($review->status)->toBe(AppraisalKinds::StatusManagerReview)
        ->and($review->self_comments)->toBe('Typed on the page');

    // The employee cannot write the manager review.
    test()->actingAs($f['employeeUser']);
    Livewire::test(ViewAppraisal::class, ['record' => $review->id])
        ->set('managerData.items.quality.rating', 1)
        ->call('saveManager', true)
        ->assertNotified();
    expect($review->fresh()->status)->toBe(AppraisalKinds::StatusManagerReview);

    test()->actingAs($f['managerUser']);
    $page = Livewire::test(ViewAppraisal::class, ['record' => $review->id])->assertSee('Self: 4');
    foreach (aprManagerRatings() as $key => $rating) {
        $page->set("managerData.items.{$key}.rating", $rating);
    }
    $page->call('saveManager', true);

    expect($review->fresh()->status)->toBe(AppraisalKinds::StatusCompleted)
        ->and((float) $review->fresh()->final_rating)->toBe(3.95);
});

it('shows appraisal tasks in the home dashboard pending actions with one query', function () {
    $f = aprFixture();
    $service = app(PerformanceService::class);
    $service->launch($f['cycle'], $f['hrUser']);
    $center = app(RoleActionCenterService::class);

    test()->actingAs($f['employeeUser']);
    $items = collect($center->getPendingActionsFor($f['employeeUser'], $f['company']->id)['items'])->keyBy('id');
    expect($items)->toHaveKey('appraisal_self_review')
        ->and($items['appraisal_self_review']['description'])->toContain('Fri 6 Nov');

    $service->saveSelfReview(aprReview($f['cycle'], $f['employee']), $f['employeeUser'], aprAnswers(aprAllFours()), true);
    $service->saveSelfReview(aprReview($f['cycle'], $f['orphan']), $f['orphanUser'], aprAnswers(aprAllFours()), true);

    // Permissions, roles and company memberships are loaded once per request anyway; count the second call.
    $service->taskCountsFor($f['managerUser'], $f['company']->id);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $counts = $service->taskCountsFor($f['managerUser'], $f['company']->id);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($counts['manager'])->toBe(1)
        ->and($counts['self'])->toBe(1) // his own self review
        ->and($queries)->toBeLessThanOrEqual(1);

    test()->actingAs($f['managerUser']);
    $items = collect($center->getPendingActionsFor($f['managerUser'], $f['company']->id)['items'])->keyBy('id');
    expect($items['appraisal_manager_reviews']['title'])->toContain('1 Appraisal');

    // HR: the appraisal without a line manager is theirs to review.
    expect($service->taskCountsFor($f['hrUser'], $f['company']->id)['manager'])->toBe(1);

    // Overdue after the deadline.
    test()->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Karachi')->utc());
    expect($service->taskCountsFor($f['managerUser'], $f['company']->id)['manager_overdue'])->toBe(1);

    $service->saveManagerReview(aprReview($f['cycle'], $f['employee']), $f['managerUser'], aprAnswers(aprManagerRatings()), true);
    expect($service->taskCountsFor($f['employeeUser'], $f['company']->id)['acknowledge'])->toBe(0);
    $service->closeCycle($f['cycle']->fresh(), $f['hrUser']);
    expect($service->taskCountsFor($f['employeeUser'], $f['company']->id)['acknowledge'])->toBe(1);

    // On the home page the appraisal card costs one query (HomeDashboardTest holds the page budgets).
    test()->actingAs($f['employeeUser']);
    test()->get('/admin')->assertOk();
    app(HomeDashboardContext::class)->flush();
    app()->forgetInstance(AnnouncementService::class);
    (new ReflectionProperty(app(), 'terminatingCallbacks'))->setValue(app(), []);
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get('/admin')->assertOk()->assertSee('Your Appraisal Result Is Ready');
    $appraisalQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'employees_performance_'))->count();
    DB::disableQueryLog();

    expect($appraisalQueries)->toBe(1);
});

function aprUrl(PerformanceReview $review): string
{
    return route('filament.admin.resources.performance-reviews.view', ['record' => $review->id]);
}

it('launches for selected departments only, and HR creates the cycle on the cycles screen', function () {
    $f = aprFixture();
    $operations = Department::query()->create(['company_id' => $f['company']->id, 'name' => 'Operations '.uniqid()]);
    $finance = Department::query()->create(['company_id' => $f['company']->id, 'name' => 'Finance '.uniqid()]);
    $f['employee']->update(['department_id' => $operations->id]);
    $f['colleague']->update(['department_id' => $finance->id]);

    test()->actingAs($f['hrUser']);
    Livewire::test(ManagePerformanceCycles::class)
        ->callAction(TestAction::make('create')->table(), [
            'name'                  => 'Operations mid-year',
            'starts_on'             => '2026-01-01',
            'ends_on'               => '2026-06-30',
            'template_id'           => $f['cycle']->template_id,
            'results_visibility'    => AppraisalKinds::VisibleAfterManagerReview,
            'self_review_due_on'    => '2026-11-10',
            'manager_review_due_on' => '2026-11-20',
            'participant_scope'     => AppraisalKinds::ParticipantsDepartments,
            'department_ids'        => [$operations->id],
        ])
        ->assertHasNoActionErrors();

    $cycle = PerformanceCycle::query()->where('company_id', $f['company']->id)->where('name', 'Operations mid-year')->firstOrFail();
    expect($cycle->status)->toBe(AppraisalKinds::CycleDraft)
        ->and($cycle->department_ids)->toBe([$operations->id]);

    Livewire::test(ManagePerformanceCycles::class)
        ->assertTableActionVisible('launch', $cycle)
        ->callTableAction('launch', $cycle);

    expect($cycle->fresh()->status)->toBe(AppraisalKinds::CycleActive)
        ->and($cycle->reviews()->pluck('employee_id')->all())->toBe([$f['employee']->id]);

    // The line manager has no cycles screen.
    test()->actingAs($f['managerUser']);
    Livewire::test(ManagePerformanceCycles::class)->assertForbidden();
});

it('lets HR edit appraisal templates with stable item keys, and nobody else', function () {
    $f = aprFixture();

    test()->actingAs($f['hrUser']);
    expect(AppraisalTemplateResource::canViewAny())->toBeTrue()
        ->and($f['hrUser']->can('create', AppraisalTemplate::class))->toBeTrue();

    $template = AppraisalTemplate::query()->create([
        'company_id'   => $f['company']->id,
        'name'         => 'Drivers',
        'sections'     => [['title' => 'Driving', 'items' => [['title' => 'Safety record', 'weight' => 70], ['title' => 'On-time delivery', 'weight' => 30], ['title' => '']]]],
        'rating_scale' => [5 => 'Excellent'],
        'rating_bands' => [],
    ]);

    $keys = collect($template->sections[0]['items'])->pluck('key');
    expect($template->sections[0]['items'])->toHaveCount(2)
        ->and($keys->filter()->unique())->toHaveCount(2)
        ->and($template->rating_scale[5])->toBe('Excellent')
        ->and($template->rating_scale[1])->toBe('Unsatisfactory')
        ->and($template->rating_bands)->toBe(AppraisalKinds::defaultRatingBands());

    // Saving again keeps the keys (answers are stored by key).
    $template->update(['name' => 'Drivers 2027']);
    expect(collect($template->fresh()->sections[0]['items'])->pluck('key')->all())->toBe($keys->all());

    Livewire::test(ListAppraisalTemplates::class)->assertOk()->assertSee('Drivers 2027')->assertSee('Annual appraisal');

    // The default template is switched off, never deleted.
    $default = AppraisalTemplate::query()->findOrFail($f['cycle']->template_id);
    expect($f['hrUser']->can('delete', $default))->toBeFalse()
        ->and($f['hrUser']->can('delete', $template))->toBeTrue();

    test()->actingAs($f['managerUser']);
    expect(AppraisalTemplateResource::canViewAny())->toBeFalse()
        ->and($f['managerUser']->can('update', $template))->toBeFalse();
});
