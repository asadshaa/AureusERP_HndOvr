<?php

/**
 * Announcements, birthdays and work anniversaries (HR Parity 2.6): HR posts
 * per company with a sanitised body; the audience (everyone / departments
 * with their sub-departments), publish and expiry times decide who sees
 * what; read and acknowledgement tracking; birthdays show day and month
 * only, never for someone who opted out, and "Send wishes" once a day.
 */

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\Announcements;
use Webkul\Employee\Models\Announcement;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\AnnouncementService;
use Webkul\Employee\Services\CelebrationService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function () {
    config(['attendance.monthly_report.timezone' => 'Asia/Karachi']);
    // Wednesday 28 Oct 2026, 10:00 in Karachi; this week is Mon 26 Oct - Sun 1 Nov.
    test()->travelTo(CarbonImmutable::parse('2026-10-28 10:00', 'Asia/Karachi')->utc());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Cache::flush();
});

function anCompany(string $name = 'News Co'): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true]);
}

/** @param  array<int, string>  $permissions */
function anUser(Company $company, array $permissions = []): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function anEmployee(Company $company, string $name, ?User $user = null, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id' => $company->id,
        'name'       => $name,
        'is_active'  => true,
        'user_id'    => $user?->id,
    ]);
}

function anDepartment(Company $company, string $name, ?Department $parent = null): Department
{
    return Department::query()->create(['company_id' => $company->id, 'name' => $name, 'parent_id' => $parent?->id]);
}

/** @param  array<string, mixed>  $data */
function anPost(User $hr, array $data): Announcement
{
    return app(AnnouncementService::class)->create($hr, $data + ['audience' => Announcement::AudienceAll]);
}

/** @return array<int, string> titles of the live announcements the user sees */
function anVisible(User $user, Company $company): array
{
    return app(AnnouncementService::class)->visibleQuery($user, $company->id)->orderBy('id')->pluck('title')->all();
}

// ---------------------------------------------------------------------
// Announcements
// ---------------------------------------------------------------------
it('lets HR post a sanitised announcement that only its company sees', function () {
    $company = anCompany();
    $hr = anUser($company, [HrPermissions::ManageAnnouncements]);
    $staff = anUser($company);
    anEmployee($company, 'Staff Member', $staff);
    $other = anCompany('Other News Co');
    $outsider = anUser($other, [HrPermissions::ManageAnnouncements]);

    $announcement = anPost($hr, [
        'title' => 'Office closed on Monday',
        'body'  => '<p>Enjoy the <strong>long weekend</strong>.</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>',
    ]);

    expect($announcement->company_id)->toBe($company->id)
        ->and($announcement->body)->toContain('<strong>long weekend</strong>')
        ->and($announcement->body)->not->toContain('<script')
        ->and($announcement->body)->not->toContain('javascript:')
        ->and(anVisible($staff, $company))->toBe(['Office closed on Monday'])
        ->and(anVisible($outsider, $other))->toBe([])
        ->and(anVisible($outsider, $company))->toBe([]);

    // Only HR of the company may post or change.
    expect(fn () => anPost($staff, ['title' => 'Not allowed']))->toThrow(AuthorizationException::class);
    expect(fn () => app(AnnouncementService::class)->update($announcement, $outsider, ['title' => 'Hijack', 'audience' => 'all']))->toThrow(AuthorizationException::class);
    expect(fn () => app(AnnouncementService::class)->delete($announcement, $staff))->toThrow(AuthorizationException::class);
});

it('shows department announcements to those departments and their sub-departments only', function () {
    $company = anCompany();
    $hr = anUser($company, [HrPermissions::ManageAnnouncements]);
    $operations = anDepartment($company, 'Operations');
    $dispatch = anDepartment($company, 'Dispatch', $operations);
    $finance = anDepartment($company, 'Finance');

    $dispatcher = anUser($company);
    anEmployee($company, 'Dispatcher', $dispatcher, ['department_id' => $dispatch->id]);
    $accountant = anUser($company);
    anEmployee($company, 'Accountant', $accountant, ['department_id' => $finance->id]);
    $noRecord = anUser($company);

    anPost($hr, ['title' => 'For everyone']);
    anPost($hr, ['title' => 'Operations only', 'audience' => Announcement::AudienceDepartments, 'department_ids' => [$operations->id]]);

    expect(anVisible($dispatcher, $company))->toBe(['For everyone', 'Operations only'])
        ->and(anVisible($accountant, $company))->toBe(['For everyone'])
        ->and(anVisible($noRecord, $company))->toBe(['For everyone']);

    // A department of another company is refused.
    $foreign = anDepartment(anCompany('Foreign Dept Co'), 'Foreign');
    expect(fn () => anPost($hr, ['title' => 'Bad', 'audience' => Announcement::AudienceDepartments, 'department_ids' => [$foreign->id]]))
        ->toThrow(ValidationException::class);
});

it('shows announcements between publish and expiry, and HR sees the rest on the page', function () {
    $company = anCompany();
    $hr = anUser($company, [HrPermissions::ManageAnnouncements]);
    $staff = anUser($company);
    anEmployee($company, 'Reader', $staff);

    anPost($hr, ['title' => 'Live now', 'expires_at' => now()->addDay()->toDateTimeString()]);
    anPost($hr, ['title' => 'Scheduled', 'publish_at' => now()->addDays(2)->toDateTimeString()]);
    $expiring = anPost($hr, ['title' => 'Expires soon', 'expires_at' => now()->addHour()->toDateTimeString()]);

    expect(anVisible($staff, $company))->toBe(['Live now', 'Expires soon']);

    $this->travel(2)->hours();
    expect(anVisible($staff, $company))->toBe(['Live now']);

    $this->travel(2)->days();
    expect(anVisible($staff, $company))->toBe(['Scheduled']);

    expect(fn () => app(AnnouncementService::class)->update($expiring, $hr, ['title' => 'x', 'audience' => 'all', 'publish_at' => '2026-11-05 10:00', 'expires_at' => '2026-11-04 10:00']))
        ->toThrow(ValidationException::class);

    // The page: everyone their live ones; HR all of the company's, with state.
    $this->actingAs($staff);
    Livewire::test(Announcements::class)->assertOk()->assertSee('Scheduled')->assertDontSee('Expires soon');

    $this->actingAs($hr);
    Livewire::test(Announcements::class)->assertOk()->assertSee('Expires soon')->assertSee('Expired');
});

it('tracks reads and acknowledgements, and keeps the home feed to three, pinned first', function () {
    $company = anCompany();
    $hr = anUser($company, [HrPermissions::ManageAnnouncements]);
    $staff = anUser($company);
    $employee = anEmployee($company, 'Reader', $staff);
    $colleague = anUser($company);
    anEmployee($company, 'Colleague', $colleague);
    $service = app(AnnouncementService::class);

    $policy = anPost($hr, ['title' => 'New leave policy', 'requires_acknowledgement' => true]);
    $pinned = anPost($hr, ['title' => 'Pinned note', 'is_pinned' => true]);
    $this->travel(1)->minutes();
    $first = anPost($hr, ['title' => 'News one']);
    $this->travel(1)->minutes();
    anPost($hr, ['title' => 'News two']);

    $titles = fn (): array => $service->dashboardFeed($staff, $company->id, $employee)->pluck('title')->all();
    expect($titles())->toBe(['Pinned note', 'News two', 'News one']);

    // Read news leave the card; a read pinned one stays.
    $service->markRead($first, $staff);
    $service->markRead($pinned, $staff);
    expect($titles())->toBe(['Pinned note', 'News two', 'New leave policy']);

    // Acknowledgement: reading is not enough; acknowledging a plain one is refused.
    $service->markRead($policy, $staff);
    expect($titles())->toContain('New leave policy');
    $read = $service->acknowledge($policy, $staff);
    expect($read->acknowledged_at)->not->toBeNull()
        ->and($titles())->not->toContain('New leave policy');
    expect(fn () => $service->acknowledge($first, $staff))->toThrow(ValidationException::class);

    // Counts for HR: 2 people in the audience, 1 read and acknowledged.
    $counted = $service->withReadState(Announcement::query()->whereKey($policy->id), $hr)
        ->withCount(['reads as read_count' => fn ($q) => $q->whereNotNull('read_at'), 'reads as acknowledged_count' => fn ($q) => $q->whereNotNull('acknowledged_at')])
        ->first();
    expect($counted->read_count)->toBe(1)
        ->and($counted->acknowledged_count)->toBe(1)
        ->and($service->audienceCount($policy))->toBe(2);

    // Someone outside the audience cannot mark it.
    $outsider = anUser(anCompany('Outside Co'));
    expect(fn () => $service->markRead($policy, $outsider))->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------
// Birthdays and anniversaries
// ---------------------------------------------------------------------
it('lists this week\'s birthdays without the year and anniversaries, and respects the opt-out', function () {
    $company = anCompany('ShipLine Test Co');
    $hina = anEmployee($company, 'Hina Birthday', anUser($company), ['birthday' => '1990-10-30']);
    anEmployee($company, 'Private Person', anUser($company), ['birthday' => '1988-10-29', 'show_birthday' => false, 'joining_date' => '2021-10-29']);
    anEmployee($company, 'Next Week', null, ['birthday' => '1995-11-03']);
    anEmployee($company, 'Left Already', null, ['birthday' => '1991-10-27', 'is_active' => false]);
    anEmployee(anCompany('Other Birthday Co'), 'Other Company', null, ['birthday' => '1992-10-28']);

    $rows = app(CelebrationService::class)->thisWeek($company->id);

    expect(collect($rows)->map(fn (array $row): string => $row['name'].'|'.$row['label'].'|'.$row['day_label'])->all())->toBe([
        'Private Person|5 years at ShipLine Test Co|Thu 29 Oct',
        'Hina Birthday|Birthday|Fri 30 Oct',
    ]);

    // Never the birth year or an age, anywhere in the data.
    $json = json_encode($rows);
    expect($json)->not->toContain('1990')->not->toContain('1988')->not->toContain('"36"');

    // The employee switches their birthday off; the cached list is refreshed.
    $this->actingAs($hina->user);
    app(CelebrationService::class)->setOwnBirthdayVisibility($hina->user, false);
    expect(collect(app(CelebrationService::class)->thisWeek($company->id))->pluck('name')->all())->toBe(['Private Person']);
});

it('sends wishes once a day to someone celebrating this week only', function () {
    $company = anCompany('Wishes Co');
    $sender = anUser($company);
    anEmployee($company, 'Sender', $sender);
    $birthdayUser = anUser($company);
    $birthday = anEmployee($company, 'Birthday Person', $birthdayUser, ['birthday' => '1990-10-28']);
    $quiet = anEmployee($company, 'No Birthday', anUser($company), ['birthday' => '1990-06-01']);
    $service = app(CelebrationService::class);

    expect($service->sendWishes($sender, $birthday->id, CelebrationService::Birthday))->toBeTrue()
        ->and($service->sendWishes($sender, $birthday->id, CelebrationService::Birthday))->toBeFalse()
        ->and($service->sendWishes($sender, $quiet->id, CelebrationService::Birthday))->toBeFalse()
        ->and($service->sendWishes($birthdayUser, $birthday->id, CelebrationService::Birthday))->toBeFalse();

    $notifications = $birthdayUser->fresh()->notifications;
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->data['title'])->toBe($sender->name.' wishes you a happy birthday');
});

it('lets HR post from the Announcements page and employees read, acknowledge and hide their birthday there', function () {
    $company = anCompany('Page News Co');
    $sales = anDepartment($company, 'Sales');
    $hr = anUser($company, [HrPermissions::ManageAnnouncements]);
    anEmployee($company, 'HR Person', $hr);
    $staff = anUser($company);
    $employee = anEmployee($company, 'Sales Person', $staff, ['department_id' => $sales->id, 'birthday' => '1990-10-29']);

    $this->actingAs($hr);
    Livewire::test(Announcements::class)
        ->assertActionVisible('create')
        ->callAction('create', data: [
            'title'                    => 'Sales kick-off',
            'body'                     => '<p>Monday <em>09:00</em> in the hall.</p><img src=x onerror=alert(1)>',
            'audience'                 => Announcement::AudienceDepartments,
            'department_ids'           => [$sales->id],
            'is_pinned'                => true,
            'requires_acknowledgement' => true,
        ])
        ->assertHasNoActionErrors();

    $announcement = Announcement::query()->where('company_id', $company->id)->where('title', 'Sales kick-off')->firstOrFail();
    expect($announcement->body)->not->toContain('onerror')
        ->and($announcement->departments()->pluck('employees_departments.id')->all())->toBe([$sales->id]);

    $this->actingAs($staff);
    Livewire::withQueryParams(['announcement' => $announcement->id])
        ->test(Announcements::class)
        ->assertActionHidden('create')
        ->assertSee('Monday')
        ->assertSee('I have read and understood this')
        ->call('acknowledge', $announcement->id)
        ->assertSee('Acknowledged')
        ->callAction('birthday_privacy');

    expect($employee->fresh()->show_birthday)->toBeFalse()
        ->and($announcement->reads()->whereNotNull('acknowledged_at')->where('user_id', $staff->id)->exists())->toBeTrue();
});
