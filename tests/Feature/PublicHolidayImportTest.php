<?php

/**
 * Public holiday import (PublicHolidayImportService, hr:import-public-holidays,
 * the "Import Pakistan holidays" action): the bundled Pakistan 2026 list is
 * added once per company, dates that already are holidays are skipped and
 * nothing is changed or deleted, an HR CSV can mark tentative dates. The
 * imported days are holidays in the monthly attendance report, on the Today
 * page and in My Attendance (never "Missing").
 */

require_once __DIR__.'/../../plugins/webkul/employees/tests/Feature/Attendance/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\Attendance\TodayAttendance;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Filament\Clusters\Configurations\Resources\PublicHolidayResource\Pages\ListPublicHolidays;
use Webkul\TimeOff\Models\CalendarLeave;
use Webkul\TimeOff\Services\PublicHolidayImportService;

beforeEach(function () {
    // Monday 2 Nov 2026, 12:00 in Karachi.
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    config([
        'attendance.monthly_report.office_start' => '10:00',
        'attendance.monthly_report.office_end'   => '18:00',
        'attendance.monthly_report.timezone'     => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days' => [6, 7],
    ]);

    $this->service = app(PublicHolidayImportService::class);
    $this->companyA = Company::factory()->create(['name' => 'Holiday Import Co A', 'is_active' => true]);
    $this->companyB = Company::factory()->create(['name' => 'Holiday Import Co B', 'is_active' => true]);
    $this->pakistan2026 = $this->service->load($this->service->bundledFile(2026), 2026);
});

/** @return array<int, string> "name|from|to" of the company's own holidays, by date */
function companyHolidays(Company $company): array
{
    return CalendarLeave::query()
        ->where('company_id', $company->id)
        ->orderBy('date_from')
        ->orderBy('id')
        ->get()
        ->map(fn (CalendarLeave $holiday): string => $holiday->name.'|'.substr((string) $holiday->date_from, 0, 10).'|'.substr((string) $holiday->date_to, 0, 10))
        ->all();
}

function holidayUser(Company $company, string ...$permissions): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function holidayCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'holidays').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('adds the bundled Pakistan 2026 list once per company and skips the existing dates on a re-run', function () {
    expect($this->pakistan2026)->toHaveCount(11);

    $first = $this->service->import($this->companyA->id, $this->pakistan2026);

    expect($first['created'])->toHaveCount(10)
        ->and($first['skipped'])->toBe([['name' => 'Youm-e-Takbeer', 'date_from' => '2026-05-28', 'date_to' => '2026-05-28', 'reason' => 'already a holiday: Eid ul Adha']])
        ->and(companyHolidays($this->companyA))->toBe([
            'Kashmir Day|2026-02-05|2026-02-05',
            'Eid ul Fitr|2026-03-20|2026-03-21',
            'Pakistan Day|2026-03-23|2026-03-23',
            'Labour Day|2026-05-01|2026-05-01',
            'Eid ul Adha|2026-05-26|2026-05-28',
            'Ashura (9th and 10th Muharram)|2026-06-25|2026-06-26',
            'Independence Day|2026-08-14|2026-08-14',
            'Eid Milad un Nabi|2026-08-26|2026-08-26',
            'Allama Iqbal Day|2026-11-09|2026-11-09',
            'Quaid-e-Azam Day / Christmas|2026-12-25|2026-12-25',
        ]);

    $holiday = CalendarLeave::query()->where('company_id', $this->companyA->id)->first();
    expect($holiday->time_type)->toBe('leave')->and($holiday->calendar_id)->toBeNull();

    // A re-run adds nothing; another company gets its own copy.
    $again = $this->service->import($this->companyA->id, $this->pakistan2026);
    expect($again['created'])->toBe([])
        ->and($again['skipped'])->toHaveCount(11)
        ->and(companyHolidays($this->companyA))->toHaveCount(10);

    expect($this->service->import($this->companyB->id, $this->pakistan2026)['created'])->toHaveCount(10)
        ->and(companyHolidays($this->companyB))->toHaveCount(10)
        ->and(companyHolidays($this->companyA))->toHaveCount(10);
});

it('keeps what HR set up, adds only the free days and never deletes', function () {
    CalendarLeave::query()->create(['name' => 'Independence Day (office)', 'time_type' => 'leave', 'date_from' => '2026-08-14', 'date_to' => '2026-08-14', 'company_id' => $this->companyA->id]);
    CalendarLeave::query()->create(['name' => 'Office closure', 'time_type' => 'leave', 'date_from' => '2026-05-27', 'date_to' => '2026-05-27', 'company_id' => $this->companyA->id]);
    // A shared definition (no company) counts for every company.
    CalendarLeave::query()->create(['name' => 'Kashmir Solidarity Day', 'time_type' => 'leave', 'date_from' => '2026-02-05', 'date_to' => '2026-02-05', 'company_id' => null]);
    // Another company's holiday does not.
    CalendarLeave::query()->create(['name' => 'B only', 'time_type' => 'leave', 'date_from' => '2026-03-23', 'date_to' => '2026-03-23', 'company_id' => $this->companyB->id]);

    $result = $this->service->import($this->companyA->id, $this->pakistan2026);

    expect(collect($result['skipped'])->map(fn (array $row): string => "{$row['name']} {$row['date_from']}: {$row['reason']}")->all())->toBe([
        'Kashmir Day 2026-02-05: already a holiday: Kashmir Solidarity Day',
        'Eid ul Adha 2026-05-27: already a holiday: Office closure',
        'Youm-e-Takbeer 2026-05-28: already a holiday: Eid ul Adha',
        'Independence Day 2026-08-14: already a holiday: Independence Day (office)',
    ]);

    $rows = companyHolidays($this->companyA);
    expect($rows)->toContain('Independence Day (office)|2026-08-14|2026-08-14', 'Office closure|2026-05-27|2026-05-27', 'Eid ul Adha|2026-05-26|2026-05-26', 'Eid ul Adha|2026-05-28|2026-05-28', 'Pakistan Day|2026-03-23|2026-03-23')
        ->and($rows)->not->toContain('Independence Day|2026-08-14|2026-08-14')
        // HR's two rows plus nine added (Eid ul Adha split around the closure; no Kashmir Day, Independence Day or Youm-e-Takbeer).
        ->and($rows)->toHaveCount(11)
        ->and($result['created'])->toHaveCount(9)
        ->and(CalendarLeave::query()->whereNull('company_id')->where('name', 'Kashmir Solidarity Day')->exists())->toBeTrue()
        ->and(companyHolidays($this->companyB))->toBe(['B only|2026-03-23|2026-03-23']);
});

it('reads an HR CSV, marks tentative dates and refuses a bad file without writing anything', function () {
    $csv = holidayCsv("name,date_from,date_to,tentative\nCompany Foundation Day,2027-01-15,,no\nEid ul Fitr,10/03/2027,12/03/2027,yes\n");

    $holidays = $this->service->load($csv, 2027);
    $result = $this->service->import($this->companyA->id, $holidays);

    expect($result['created'])->toHaveCount(2)
        ->and(companyHolidays($this->companyA))->toBe([
            'Company Foundation Day|2027-01-15|2027-01-15',
            'Eid ul Fitr (tentative)|2027-03-10|2027-03-12',
        ]);

    $bad = holidayCsv("name,date_from,date_to\nNew Year,2026-01-01,\nTypo Day,2027-02-30,\n,2027-03-01,\nBackwards,2027-04-10,2027-04-01\n");

    expect(fn () => $this->service->load($bad, 2027))->toThrow(InvalidArgumentException::class, 'line 2: New Year: the dates are not in 2027; line 3: date_from "2027-02-30" is not a date');
    expect(fn () => $this->service->load(holidayCsv("title,day\nX,2027-01-01\n"), 2027))->toThrow(InvalidArgumentException::class, 'header row');
    expect(companyHolidays($this->companyA))->toHaveCount(2);
});

it('imports through hr:import-public-holidays per named company, idempotently, with a dry run', function () {
    $this->artisan('hr:import-public-holidays', ['year' => '2026', '--company' => [(string) $this->companyA->id], '--dry-run' => true])
        ->expectsOutputToContain('10 added (not saved), 1 skipped')
        ->assertSuccessful();
    expect(companyHolidays($this->companyA))->toBe([]);

    $this->artisan('hr:import-public-holidays', ['year' => '2026', '--company' => ['holiday import co a']])
        ->expectsOutputToContain('10 added, 1 skipped')
        ->assertSuccessful();
    $this->artisan('hr:import-public-holidays', ['year' => '2026', '--company' => [(string) $this->companyA->id]])
        ->expectsOutputToContain('0 added, 11 skipped')
        ->assertSuccessful();

    expect(companyHolidays($this->companyA))->toHaveCount(10)
        ->and(companyHolidays($this->companyB))->toBe([]);

    $this->artisan('hr:import-public-holidays', ['year' => '2026'])->assertExitCode(Command::INVALID);
    $this->artisan('hr:import-public-holidays', ['year' => '2031', '--company' => [(string) $this->companyA->id]])->assertExitCode(Command::INVALID);
});

it('offers the import on the Public Holidays list to HR only, for the active company', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $this->actingAs(holidayUser($this->companyA, 'view_any_time_off_public::holiday'));
    Livewire::test(ListPublicHolidays::class)->assertActionHidden('importPakistanHolidays');

    $this->actingAs(holidayUser($this->companyA, 'view_any_time_off_public::holiday', 'create_time_off_public::holiday'));
    Livewire::test(ListPublicHolidays::class)
        ->assertActionVisible('importPakistanHolidays')
        ->callAction('importPakistanHolidays', data: ['year' => 2026])
        ->assertHasNoActionErrors()
        ->assertNotified('Pakistan holidays 2026 imported for Holiday Import Co A');

    expect(companyHolidays($this->companyA))->toHaveCount(10)
        ->and(companyHolidays($this->companyB))->toBe([]);
});

it('counts an imported holiday as a holiday in the monthly attendance report of that company only', function () {
    $employee = fn (Company $company): Employee => Employee::query()->create([
        'company_id'   => $company->id,
        'name'         => 'Holiday Report '.$company->id,
        'is_active'    => true,
        'time_zone'    => 'Asia/Karachi',
        'joining_date' => '2026-01-01',
    ]);
    $inA = $employee($this->companyA);
    $inB = $employee($this->companyB);

    $this->service->import($this->companyA->id, $this->pakistan2026);

    $report = app(MonthlyAttendanceReport::class);
    $dayA = collect($report->forEmployee($inA, $this->companyA->id, '2026-08')['rows'])->keyBy('date');
    $dayB = collect($report->forEmployee($inB, $this->companyB->id, '2026-08')['rows'])->keyBy('date');

    expect($dayA['2026-08-14']['type'])->toBe('holiday')
        ->and($dayA['2026-08-14']['working'])->toBeFalse()
        ->and(implode(' ', (array) $dayA['2026-08-14']['remarks']))->toContain('Public holiday: Independence Day')
        ->and($dayA['2026-08-26']['type'])->toBe('holiday')
        ->and($dayA['2026-08-13']['type'])->toBe('working')
        ->and($report->forEmployee($inA, $this->companyA->id, '2026-08')['summary']['holidays'])->toBe(2)
        ->and($dayB['2026-08-14']['type'])->toBe('working')
        ->and($report->forEmployee($inB, $this->companyB->id, '2026-08')['summary']['holidays'])->toBe(0);
});

it('shows an imported holiday as Holiday on the Today page and in My Attendance, not as Missing', function () {
    config(['hr_attendance_geofence.enabled' => true]);
    [$user, $employee] = geoEmployee($this->companyA, null, ['name' => 'Holiday Self Service', 'time_zone' => 'Asia/Karachi', 'joining_date' => '2026-01-01']);
    $this->service->import($this->companyA->id, $this->pakistan2026);

    // Today page on Iqbal Day (Monday 9 Nov).
    $this->travelTo(CarbonImmutable::parse('2026-11-09 07:00:00', 'UTC'));
    $hr = holidayUser($this->companyA, HrPermissions::ViewAttendance, HrPermissions::ViewAllRecords);
    $today = app(TodayAttendance::class)->forCompany($hr, $this->companyA->id);

    expect($today['off_day'])->toBe(['type' => 'holiday', 'name' => 'Allama Iqbal Day'])
        ->and($today['rows'][$employee->id]['status'])->toBe('holiday')
        ->and($today['rows'][$employee->id]['status_label'])->toBe('Holiday: Allama Iqbal Day')
        ->and($today['counts']['absent'])->toBe(0);

    // My Attendance, August: the two holidays are Holiday, the working day before is still Missing.
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->actingAs($user);

    $page = Livewire::test(MyAttendance::class)
        ->set('calendarYear', 2026)
        ->set('calendarMonth', 8)
        ->assertSee('Public holiday: Independence Day');
    $days = collect($page->instance()->getMonthlyCalendarData()['days'])->where('type', 'day')->keyBy('date');

    expect($days['2026-08-14'])->toMatchArray(['status' => 'holiday', 'label' => 'Holiday', 'holidayName' => 'Independence Day'])
        ->and($days['2026-08-26'])->toMatchArray(['status' => 'holiday', 'label' => 'Holiday', 'holidayName' => 'Eid Milad un Nabi'])
        ->and($days['2026-08-13'])->toMatchArray(['status' => 'absent', 'label' => 'Missing'])
        ->and($days['2026-08-15'])->toMatchArray(['status' => 'weekend', 'holidayName' => null]);
});
