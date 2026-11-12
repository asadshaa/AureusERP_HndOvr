<?php

/**
 * Every company works on its own working schedule: the migration copies a
 * schedule owned by another company for the company that uses it, the
 * Working Schedules tabs count only the schedules the user can see, and a
 * new demo company gets its own copy instead of sharing Truck It In's.
 */

use Database\Seeders\DemoActivitySeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Filament\Resources\CalendarResource\Pages\ListCalendars;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\Company;

function scheduleFixture(): array
{
    $owner = Company::factory()->create(['name' => 'Schedule Owner Co', 'is_active' => true]);
    $other = Company::factory()->create(['name' => 'Schedule User Co', 'is_active' => true]);

    $schedule = Calendar::query()->create([
        'name'                     => 'Test 10-6',
        'timezone'                 => 'Asia/Karachi',
        'hours_per_day'            => 8,
        'full_time_required_hours' => 40,
        'flexible_hours'           => false,
        'is_active'                => true,
        'company_id'               => $owner->id,
    ]);

    foreach (['monday', 'tuesday', 'wednesday'] as $index => $day) {
        CalendarAttendance::query()->create([
            'name'          => ucfirst($day).' Working Hours',
            'day_of_week'   => $day,
            'day_period'    => 'morning',
            'hour_from'     => 10,
            'hour_to'       => 18,
            'duration_days' => 1,
            'sort'          => $index + 1,
            'calendar_id'   => $schedule->id,
        ]);
    }

    return [$owner, $other, $schedule];
}

function scheduleEmployee(Company $company, string $name, ?int $calendarId): Employee
{
    return Employee::query()->create([
        'company_id'  => $company->id,
        'name'        => $name,
        'is_active'   => true,
        'calendar_id' => $calendarId,
    ]);
}

function runScheduleMigration(): void
{
    (require base_path('plugins/webkul/employees/database/migrations/2026_11_12_100001_give_each_company_its_own_working_schedule.php'))->up();
}

it('copies a schedule owned by another company for the company that uses it', function () {
    [$owner, $other, $schedule] = scheduleFixture();
    $ownersEmployee = scheduleEmployee($owner, 'Owner Staff', $schedule->id);
    $usersEmployee = scheduleEmployee($other, 'Borrowing Staff', $schedule->id);
    $global = Calendar::query()->create(['name' => 'Shared by everyone', 'timezone' => 'UTC', 'hours_per_day' => 8, 'is_active' => true, 'company_id' => null]);
    $globalEmployee = scheduleEmployee($other, 'On Shared', $global->id);

    runScheduleMigration();

    $copy = Calendar::query()->where('company_id', $other->id)->where('name', 'Test 10-6')->first();

    expect($copy)->not->toBeNull()
        ->and($copy->id)->not->toBe($schedule->id)
        ->and($copy->timezone)->toBe('Asia/Karachi')
        ->and((float) $copy->hours_per_day)->toBe(8.0)
        ->and($copy->attendance()->orderBy('sort')->pluck('day_of_week')->all())->toBe(['monday', 'tuesday', 'wednesday'])
        ->and($copy->attendance()->first()->hour_from)->toEqual($schedule->attendance()->first()->hour_from)
        ->and($usersEmployee->fresh()->calendar_id)->toBe($copy->id)
        ->and($ownersEmployee->fresh()->calendar_id)->toBe($schedule->id)
        ->and($globalEmployee->fresh()->calendar_id)->toBe($global->id)
        ->and($schedule->attendance()->count())->toBe(3);
});

it('is idempotent and reuses an existing copy', function () {
    [, $other, $schedule] = scheduleFixture();
    scheduleEmployee($other, 'Borrowing Staff', $schedule->id);

    runScheduleMigration();
    $copies = Calendar::query()->where('company_id', $other->id)->count();
    $lines = CalendarAttendance::query()->whereIn('calendar_id', Calendar::query()->where('company_id', $other->id)->pluck('id'))->count();

    runScheduleMigration();

    expect(Calendar::query()->where('company_id', $other->id)->count())->toBe($copies)->toBe(1)
        ->and(CalendarAttendance::query()->whereIn('calendar_id', Calendar::query()->where('company_id', $other->id)->pluck('id'))->count())->toBe($lines)->toBe(3);

    // A second borrowing employee added later joins the same copy.
    $late = scheduleEmployee($other, 'Late Borrower', $schedule->id);
    runScheduleMigration();

    expect(Calendar::query()->where('company_id', $other->id)->count())->toBe(1)
        ->and($late->fresh()->calendar_id)->toBe(Calendar::query()->where('company_id', $other->id)->value('id'));
});

it('moves the leave requests of that company onto the copy too', function () {
    [$owner, $other, $schedule] = scheduleFixture();
    $usersStaff = scheduleEmployee($other, 'Borrowing Staff', $schedule->id);
    $ownersStaff = scheduleEmployee($owner, 'Owner Staff', $schedule->id);
    $leaveType = DB::table('time_off_leave_types')->value('id');

    if ($leaveType === null) {
        $this->markTestSkipped('No leave type in the test database.');
    }

    $row = fn (int $companyId, int $employeeId): array => [
        'company_id'        => $companyId,
        'employee_id'       => $employeeId,
        'calendar_id'       => $schedule->id,
        'holiday_status_id' => $leaveType,
        'request_date_from' => '2026-11-10',
        'request_date_to'   => '2026-11-10',
        'date_from'         => '2026-11-10 05:00:00',
        'date_to'           => '2026-11-10 13:00:00',
        'number_of_days'    => 1,
        'state'             => 'confirm',
        'created_at'        => now(),
        'updated_at'        => now(),
    ];
    $ownersLeave = DB::table('time_off_leaves')->insertGetId($row($owner->id, $ownersStaff->id));
    $usersLeave = DB::table('time_off_leaves')->insertGetId($row($other->id, $usersStaff->id));

    runScheduleMigration();

    $copyId = Calendar::query()->where('company_id', $other->id)->value('id');

    expect((int) DB::table('time_off_leaves')->where('id', $usersLeave)->value('calendar_id'))->toBe($copyId)
        ->and((int) DB::table('time_off_leaves')->where('id', $ownersLeave)->value('calendar_id'))->toBe($schedule->id);
});

it('counts only the schedules the user can see in the Working Schedules tabs', function () {
    [$owner, $other] = scheduleFixture();
    Calendar::query()->create(['name' => 'Users own', 'timezone' => 'Asia/Karachi', 'hours_per_day' => 8, 'is_active' => true, 'company_id' => $other->id]);
    $trashed = Calendar::query()->create(['name' => 'Owners archived', 'timezone' => 'Asia/Karachi', 'hours_per_day' => 8, 'is_active' => true, 'company_id' => $owner->id]);
    $trashed->delete();
    $ownTrashed = Calendar::query()->create(['name' => 'Users archived', 'timezone' => 'Asia/Karachi', 'hours_per_day' => 8, 'is_active' => true, 'company_id' => $other->id]);
    $ownTrashed->delete();

    $role = Role::query()->firstOrCreate(['name' => 'schedule viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'view_any_support_calendar', 'guard_name' => 'web']));
    $user = User::factory()->create(['default_company_id' => $other->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->assignRole($role);
    $user->allowedCompanies()->syncWithoutDetaching([$other->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $visible = Calendar::query()->where(fn ($query) => $query->where('company_id', $other->id)->orWhereNull('company_id'));
    $tabs = Livewire::test(ListCalendars::class)->instance()->getTabs();

    expect((int) $tabs['all']->getBadge())->toBe($visible->count())
        ->and((int) $tabs['all']->getBadge())->toBeLessThan(Calendar::query()->count())
        ->and((int) $tabs['archived']->getBadge())->toBe((clone $visible)->onlyTrashed()->count())
        ->and((int) $tabs['archived']->getBadge())->toBe(1);
});

it('gives a demo company its own copy of the schedule instead of sharing the first company\'s', function () {
    $demo = Company::factory()->create(['name' => 'Schedule Demo Co', 'is_active' => true]);
    $seeder = app(DemoActivitySeeder::class);

    // The seeder copies the schedule company #1 (Truck It In) works on.
    $companyOneSchedule = (int) Employee::query()->where('company_id', 1)->whereNotNull('calendar_id')->value('calendar_id');

    if ($companyOneSchedule === 0) {
        $this->markTestSkipped('Company #1 has no employee with a working schedule in this database.');
    }

    $copyId = $seeder->ensureCompanyCalendar($demo);

    expect($copyId)->not->toBeNull()
        ->and($copyId)->not->toBe($companyOneSchedule)
        ->and((int) Calendar::query()->whereKey($copyId)->value('company_id'))->toBe($demo->id)
        ->and(CalendarAttendance::query()->where('calendar_id', $copyId)->count())
        ->toBe(CalendarAttendance::query()->where('calendar_id', $companyOneSchedule)->count())
        ->and($seeder->ensureCompanyCalendar($demo))->toBe($copyId);
});
