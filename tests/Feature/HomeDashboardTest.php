<?php

/**
 * The role-based home dashboard (App\Filament\Pages\Dashboard): which
 * widgets each role gets, that every number is limited to the active
 * company (and, for the CEO snapshot, to the user's own companies), and a
 * query ceiling per role so the home page stays fast.
 */

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AnnouncementsWidget;
use App\Filament\Widgets\CelebrationsWidget;
use App\Filament\Widgets\CompanySnapshotWidget;
use App\Filament\Widgets\EmployeeDashboardOverviewWidget;
use App\Filament\Widgets\HomeGreetingWidget;
use App\Filament\Widgets\MyDayWidget;
use App\Filament\Widgets\PendingActionsCenterWidget;
use App\Filament\Widgets\RecruitmentPipelineWidget;
use App\Filament\Widgets\TeamTodayWidget;
use App\Filament\Widgets\TodayAttendanceRollCallWidget;
use App\Services\Dashboard\CompanySnapshotService;
use App\Services\Dashboard\HomeDashboardContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Services\AnnouncementService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function () {
    // Wednesday 28 Oct 2026, 09:30 in Karachi (UTC+5).
    $this->travelTo(CarbonImmutable::parse('2026-10-28 04:30:00', 'UTC'));
    config([
        'attendance.monthly_report.office_start'       => '10:00',
        'attendance.monthly_report.office_end'         => '18:00',
        'attendance.monthly_report.late_grace_minutes' => 10,
        'attendance.monthly_report.timezone'           => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'       => [6, 7],
        'hr_attendance_geofence.enabled'               => true,
    ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function hdCompany(string $name): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true]);
}

/** @param  array<int, string>  $permissions */
function hdUser(Company $company, string $role, array $permissions = [], array $extraCompanies = []): User
{
    $roleModel = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    foreach ($permissions as $name) {
        $roleModel->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->assignRole($roleModel);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id, ...array_map(fn (Company $other): int => $other->id, $extraCompanies)]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function hdEmployee(Company $company, string $name, ?User $user = null, ?Employee $manager = null): Employee
{
    return Employee::query()->create([
        'company_id' => $company->id,
        'user_id'    => $user?->id,
        'parent_id'  => $manager?->id,
        'name'       => $name,
        'is_active'  => true,
        'time_zone'  => 'Asia/Karachi',
    ]);
}

function hdPunch(Employee $employee, string $localTime): void
{
    AttendanceRecord::query()->create([
        'company_id'      => $employee->company_id,
        'employee_id'     => $employee->id,
        'attendance_date' => '2026-10-28',
        'check_in'        => CarbonImmutable::parse('2026-10-28 '.$localTime, 'Asia/Karachi')->utc()->toDateTimeString(),
        'status'          => 'present',
        'source'          => 'manual',
    ]);
}

function hdApplicant(Company $company, string $name, ?float $score = null): Applicant
{
    $candidate = Candidate::query()->create(['company_id' => $company->id, 'name' => $name, 'email_from' => str()->slug($name).'-'.uniqid().'@example.test', 'is_active' => true]);

    return Applicant::query()->create([
        'company_id'      => $company->id,
        'candidate_id'    => $candidate->id,
        'is_active'       => true,
        'screening_score' => $score,
    ]);
}

/** @return array<int, class-string> */
function hdWidgetsFor(User $user): array
{
    test()->actingAs($user);
    app(HomeDashboardContext::class)->flush();

    return array_values(array_filter((new Dashboard)->getWidgets(), fn (string $widget): bool => $widget::canView()));
}

/**
 * Render the home page and return [response, number of queries]. A first
 * request warms the per-request caches and runs the after-response work
 * queued while the test data was created, so the count is the page's own.
 */
function hdRender(User $user): array
{
    test()->actingAs($user);
    test()->get('/admin')->assertOk();
    app(HomeDashboardContext::class)->flush();
    // The announcements feed is remembered per request (scoped service); count its query again.
    app()->forgetInstance(AnnouncementService::class);

    // The warm-up request already ran the after-response work (chatter
    // notifications of the test data); the test process never clears the
    // terminating callbacks, so drop them instead of counting them again.
    $terminating = new ReflectionProperty(app(), 'terminatingCallbacks');
    $terminating->setValue(app(), []);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = test()->get('/admin');
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$response, $count];
}

it('gives an employee their day, leave and pending actions only', function () {
    $company = hdCompany('Home Employee Co');
    $user = hdUser($company, 'hd_employee');
    hdEmployee($company, 'Hina Qureshi', $user);

    expect(hdWidgetsFor($user))->toBe([
        HomeGreetingWidget::class,
        PendingActionsCenterWidget::class,
        MyDayWidget::class,
        EmployeeDashboardOverviewWidget::class,
    ]);

    [$response, $queries] = hdRender($user);

    $response->assertOk()
        ->assertSee('Good morning, Hina')
        ->assertSee('Wednesday 28 Oct')
        ->assertSee('Home Employee Co')
        ->assertSee('My day')
        ->assertSee('Grace balance')
        ->assertSee('Pending actions')
        ->assertDontSee('Your companies')
        ->assertDontSee('My team today')
        ->assertDontSee('Best new applicants');

    expect($queries)->toBeLessThanOrEqual(45);
});

it('shows a line manager their team today with names and only their team', function () {
    $company = hdCompany('Home Team Co');
    $user = hdUser($company, 'hd_line_manager', [HrPermissions::ManageAttendance]);
    $manager = hdEmployee($company, 'Kamran Shah', $user);
    $inEarly = hdEmployee($company, 'Sana Early', null, $manager);
    $late = hdEmployee($company, 'Fahad Late', null, $manager);
    hdEmployee($company, 'Rabia Missing', null, $manager);
    hdPunch($inEarly, '09:50');
    hdPunch($late, '10:40');
    $outsider = hdEmployee($company, 'Not My Report');
    hdPunch($outsider, '09:30');

    $this->travelTo(CarbonImmutable::parse('2026-10-28 07:00:00', 'UTC'));

    expect(hdWidgetsFor($user))->toBe([
        HomeGreetingWidget::class,
        PendingActionsCenterWidget::class,
        MyDayWidget::class,
        TeamTodayWidget::class,
        EmployeeDashboardOverviewWidget::class,
    ]);

    Livewire::test(TeamTodayWidget::class)
        ->assertViewHas('teamSize', 3)
        ->assertViewHas('groups', fn (array $groups): bool => $groups['present']['count'] === 2
            && $groups['late']['names'] === ['Fahad Late']
            && $groups['absent']['names'] === ['Rabia Missing'])
        ->assertSee('Sana Early')
        ->assertDontSee('Not My Report');

    [$response, $queries] = hdRender($user);

    $response->assertOk()
        ->assertSee('Good afternoon, Kamran')
        ->assertSee('Line Manager')
        ->assertSee('My team today')
        ->assertDontSee("Today's Roll Call", false)
        ->assertDontSee('Your companies');

    expect($queries)->toBeLessThanOrEqual(65);
});

it('gives HR the company roll call and hiring, scoped to the active company', function () {
    $company = hdCompany('Home HR Co');
    $other = hdCompany('Home HR Other Co');
    $user = hdUser($company, 'hd_hr_manager', [
        HrPermissions::ViewAllRecords,
        HrPermissions::ViewAttendance,
        HrPermissions::ManageAttendance,
        'view_any_recruitment_applicant',
        'view_any_recruitment_interview',
    ], [$other]);
    hdEmployee($company, 'Zainab HR', $user);
    hdApplicant($company, 'Our Applicant', 82);
    hdApplicant($other, 'Other Company Applicant', 95);

    expect(hdWidgetsFor($user))->toBe([
        HomeGreetingWidget::class,
        PendingActionsCenterWidget::class,
        MyDayWidget::class,
        TodayAttendanceRollCallWidget::class,
        RecruitmentPipelineWidget::class,
        EmployeeDashboardOverviewWidget::class,
    ]);

    Livewire::test(RecruitmentPipelineWidget::class)
        ->assertViewHas('newCount', 1)
        ->assertSee('Our Applicant')
        ->assertSee('82')
        ->assertDontSee('Other Company Applicant');

    [$response, $queries] = hdRender($user);

    $response->assertOk()
        ->assertSee('HR Manager')
        ->assertSee("Today's Roll Call", false)
        ->assertSee('Hiring')
        ->assertSee("Today's attendance")
        ->assertSee('Attendance report')
        ->assertSee('You work in 2 companies')
        ->assertDontSee('My team today')
        ->assertDontSee('Your companies');

    expect($queries)->toBeLessThanOrEqual(80);
});

it('gives a recruiter hiring cards without HR or employee widgets', function () {
    $company = hdCompany('Home Recruit Co');
    $user = hdUser($company, 'hd_recruiter', ['view_any_recruitment_applicant', 'view_any_recruitment_interview']);
    hdApplicant($company, 'Strong Candidate', 91);
    hdApplicant($company, 'Unscored Candidate');

    expect(hdWidgetsFor($user))->toBe([
        HomeGreetingWidget::class,
        PendingActionsCenterWidget::class,
        RecruitmentPipelineWidget::class,
    ]);

    [$response, $queries] = hdRender($user);

    $response->assertOk()
        ->assertSee('Recruiter')
        ->assertSee('Best new applicants')
        ->assertSee('Strong Candidate')
        ->assertSee('Not scored')
        ->assertDontSee('My day')
        ->assertDontSee("Today's Roll Call", false);

    expect($queries)->toBeLessThanOrEqual(30);
});

it('shows the CEO a snapshot of their own companies only, each switching company', function () {
    $main = hdCompany('Home Group Main');
    $ship = hdCompany('Home Group Ship');
    $notMine = hdCompany('Home Group Not Mine');
    $user = hdUser($main, 'Admin', [], [$ship]);

    hdEmployee($main, 'Main One');
    $present = hdEmployee($main, 'Main Two');
    hdPunch($present, '09:40');
    hdEmployee($ship, 'Ship One');
    hdEmployee($notMine, 'Hidden One');
    EmployeeJobPosition::query()->create(['company_id' => $ship->id, 'name' => 'Driver', 'is_active' => true, 'posting_status' => 'published']);
    hdApplicant($ship, 'Ship Applicant');
    hdApplicant($notMine, 'Hidden Applicant');

    $snapshot = collect(app(CompanySnapshotService::class)->forUser($user))->keyBy('name');

    expect($snapshot->keys()->all())->toContain('Home Group Main', 'Home Group Ship')
        ->and($snapshot->keys()->all())->not->toContain('Home Group Not Mine')
        ->and($snapshot['Home Group Main'])->toMatchArray(['headcount' => 2, 'present_today' => 1, 'present_percentage' => 50, 'is_active' => true])
        ->and($snapshot['Home Group Ship'])->toMatchArray(['headcount' => 1, 'open_jobs' => 1, 'applicants_this_month' => 1, 'is_active' => false]);

    expect(hdWidgetsFor($user))->toContain(HomeGreetingWidget::class, CompanySnapshotWidget::class, PendingActionsCenterWidget::class)
        ->not->toContain(MyDayWidget::class, TeamTodayWidget::class);

    [$response, $queries] = hdRender($user);

    $response->assertOk()
        ->assertSee('Your companies')
        ->assertSee('Home Group Ship')
        ->assertDontSee('Home Group Not Mine')
        ->assertSee(route('security.switch-company'), false);

    expect($queries)->toBeLessThanOrEqual(55);

    $this->post(route('security.switch-company'), ['company_id' => $ship->id])->assertRedirect();
    expect($user->fresh()->default_company_id)->toBe($ship->id);

    $this->post(route('security.switch-company'), ['company_id' => $notMine->id])->assertForbidden();
});

it('shows announcements and birthdays of the week to an employee, within the employee budget', function () {
    $company = hdCompany('Home News Co');
    $hr = hdUser($company, 'hd_news_hr', [HrPermissions::ManageAnnouncements]);
    $user = hdUser($company, 'hd_news_employee');
    hdEmployee($company, 'Hina Qureshi', $user);
    $colleagueUser = hdUser($company, 'hd_news_colleague');
    $colleague = Employee::query()->create([
        'company_id' => $company->id,
        'user_id'    => $colleagueUser->id,
        'name'       => 'Bilal Birthday',
        'is_active'  => true,
        'birthday'   => '1991-10-30',
    ]);
    $pinned = app(AnnouncementService::class)->create($hr, ['title' => 'Eid holidays announced', 'body' => '<p>Office closed on Friday.</p>', 'audience' => 'all', 'is_pinned' => true]);
    $news = app(AnnouncementService::class)->create($hr, ['title' => 'New canteen menu', 'audience' => 'all']);

    expect(hdWidgetsFor($user))->toBe([
        HomeGreetingWidget::class,
        PendingActionsCenterWidget::class,
        AnnouncementsWidget::class,
        MyDayWidget::class,
        EmployeeDashboardOverviewWidget::class,
        CelebrationsWidget::class,
    ]);

    Livewire::test(AnnouncementsWidget::class)
        ->assertSee('Eid holidays announced')
        ->assertSee('New canteen menu')
        ->call('markRead', $news->id)
        ->assertDontSee('New canteen menu')
        ->assertSee('Eid holidays announced');

    Livewire::test(CelebrationsWidget::class)
        ->assertSee('Bilal Birthday')
        ->assertSee('Fri 30 Oct')
        ->assertDontSee('1991')
        ->call('sendWishes', $colleague->id, 'birthday')
        ->assertSee('Sent');
    expect($colleagueUser->fresh()->notifications)->toHaveCount(1);

    [$response, $queries] = hdRender($user);

    $response->assertOk()
        ->assertSee('Announcements')
        ->assertSee('Eid holidays announced')
        ->assertSee('This week')
        ->assertSee('Bilal Birthday')
        ->assertDontSee('1991');

    expect($queries)->toBeLessThanOrEqual(45);

    // Nothing to read and nobody celebrating: neither card is shown.
    $colleague->update(['show_birthday' => false]);
    app(AnnouncementService::class)->markRead($pinned, $user);
    $pinned->update(['is_pinned' => false]);

    expect(hdWidgetsFor($user))->not->toContain(AnnouncementsWidget::class, CelebrationsWidget::class);
});

it('hides home widgets on the server for users without the role', function () {
    $company = hdCompany('Home Plain Co');
    $user = hdUser($company, 'hd_plain');

    $this->actingAs($user);
    app(HomeDashboardContext::class)->flush();

    expect(CompanySnapshotWidget::canView())->toBeFalse()
        ->and(MyDayWidget::canView())->toBeFalse()
        ->and(TeamTodayWidget::canView())->toBeFalse()
        ->and(RecruitmentPipelineWidget::canView())->toBeFalse()
        ->and(AnnouncementsWidget::canView())->toBeFalse()
        ->and(CelebrationsWidget::canView())->toBeFalse()
        ->and(HomeGreetingWidget::canView())->toBeTrue();
});
