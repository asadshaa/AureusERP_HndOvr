<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Webkul\Employee\Enums\PunchPairingMode;
use Webkul\Employee\Filament\Pages\DeviceAttendanceImport;
use Webkul\Employee\Models\AttendanceImportBatch;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\DeviceAttendanceImportService;
use Webkul\Employee\Services\Attendance\DeviceImportExcel;
use Webkul\Employee\Services\Attendance\DevicePunchFileParser;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function () {
    // October 2026, Karachi = UTC+5.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 06:00:00', 'UTC'));
    config(['attendance.monthly_report.timezone' => 'Asia/Karachi', 'testing.dvi_locked_month' => null]);
    Storage::fake('local');
    $this->service = app(DeviceAttendanceImportService::class);
});

function dviCompany(string $name = 'Device Import Co'): Company
{
    return Company::factory()->create(['name' => $name.' '.Str::random(4), 'is_active' => true]);
}

function dviEmployee(Company $company, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id'        => $company->id,
        'name'              => 'Device Person '.Str::random(4),
        'is_active'         => true,
        'employment_status' => 'active',
        'time_zone'         => 'Asia/Karachi',
        'joining_date'      => '2026-01-01',
    ]);
}

function dviHr(Company $company, string ...$extra): User
{
    return geoHrUser($company, HrPermissions::ManageAttendance, ...$extra);
}

/** @param  array<int, array<int, string>>  $rows */
function dviCsv(array $rows, array $header = ['Employee Code / Biometric ID', 'Date Time', 'Direction']): string
{
    return implode("\n", array_map(fn (array $row): string => implode(',', $row), [$header, ...$rows]))."\n";
}

function dviStore(string $content, string $name = 'punches.csv'): string
{
    $path = 'employees/attendance-imports/'.Str::random(8).'-'.$name;
    Storage::disk('local')->put($path, $content);

    return $path;
}

function dviPreview(User $user, Company $company, string $content, string $name = 'punches.csv'): AttendanceImportBatch
{
    return app(DeviceAttendanceImportService::class)->preview($user, $company->id, dviStore($content, $name), $name);
}

function dviImport(User $user, Company $company, string $content): AttendanceImportBatch
{
    return app(DeviceAttendanceImportService::class)->import($user, dviPreview($user, $company, $content));
}

/** @return Collection<int, string> row number => status value */
function dviStatuses(AttendanceImportBatch $batch): Collection
{
    return $batch->rows()->orderBy('row_number')->get()->mapWithKeys(fn ($row): array => [$row->row_number => $row->status->value]);
}

function dviRecord(Employee $employee, string $date): ?AttendanceRecord
{
    return AttendanceRecord::query()->where('employee_id', $employee->id)->where('attendance_date', $date)->first();
}

/** @return array<int, array{0: string, 1: ?string}> active intervals as UTC "H:i" pairs */
function dviIntervals(AttendanceRecord $record): array
{
    return AttendanceInterval::query()->where('attendance_record_id', $record->id)->active()->orderBy('check_in')->get()
        ->map(fn (AttendanceInterval $interval): array => [$interval->check_in->format('H:i'), $interval->check_out?->format('H:i')])
        ->all();
}

/** Stands in for the month lock the attendance models enforce. */
function dviLockMonth(string $month): void
{
    config(['testing.dvi_locked_month' => $month]);

    $guard = function (?string $date): void {
        $locked = config('testing.dvi_locked_month');
        if ($locked && $date && str_starts_with($date, $locked)) {
            throw new RuntimeException("Attendance for {$locked} is locked.");
        }
    };

    AttendanceRecord::saving(fn (AttendanceRecord $record) => $guard($record->attendance_date?->toDateString()));
    AttendanceRecord::deleting(fn (AttendanceRecord $record) => $guard($record->attendance_date?->toDateString()));
    AttendanceInterval::saving(fn (AttendanceInterval $interval) => $guard($interval->attendanceRecord?->attendance_date?->toDateString()));
}

// ----------------------------------------------------------------------
// Parsing
// ----------------------------------------------------------------------

it('reads the template: device ids or codes, several date layouts and an optional direction', function () {
    $path = Storage::disk('local')->path(dviStore(dviCsv([
        ['1001', '2026-10-01 09:02', 'IN'],
        ['TII-0001', '01/10/2026 18:05:10', 'out'],
        ['0012', '1-Oct-2026 9:02 PM', ''],
        ['1001', 'not a date', 'IN'],
    ])));

    $file = app(DevicePunchFileParser::class)->parse($path, 'punches.csv');

    expect($file->format)->toBe('template')
        ->and($file->dateOrder)->toBe('dmy')
        ->and($file->rows)->toHaveCount(4);

    [$first, $second, $third, $bad] = $file->rows;
    expect([$first->identifier, $first->localDateTime, $first->direction])->toBe(['1001', '2026-10-01 09:02:00', 'in'])
        ->and([$second->identifier, $second->localDateTime, $second->direction])->toBe(['TII-0001', '2026-10-01 18:05:10', 'out'])
        ->and([$third->identifier, $third->localDateTime, $third->direction])->toBe(['0012', '2026-10-01 21:02:00', null])
        ->and($bad->localDateTime)->toBeNull()
        ->and($bad->error)->toContain('Could not read')
        ->and($first->rowNumber)->toBe(2);
});

it('reads a ZKTeco export with title rows, AC-No. / No. / Name / Time / State and US dates', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['Attendance Logs'],
        [null],
        ['AC-No.', 'No.', 'Name', 'Time', 'State'],
        ['7', 'E-7', 'Ali', '10/13/2026 9:01:22 AM', 'C/In'],
        ['7', 'E-7', 'Ali', '10/13/2026 6:10:05 PM', 'C/Out'],
        ['8', 'E-8', 'Sara', '10/1/2026 9:00:00 AM', 'Out Back'],
    ]);
    $local = Storage::disk('local')->path(dviStore('', 'zk.xlsx'));
    (new Xlsx($spreadsheet))->save($local);

    $file = app(DevicePunchFileParser::class)->parse($local, 'zk.xlsx');

    expect($file->format)->toBe('zkteco')
        ->and($file->dateOrder)->toBe('mdy')
        ->and($file->columns)->toMatchArray(['identifier' => 'AC-No.', 'code' => 'No.', 'name' => 'Name', 'time' => 'Time', 'direction' => 'State'])
        ->and($file->rows)->toHaveCount(3);

    [$in, $out, $other] = $file->rows;
    expect([$in->rowNumber, $in->identifier, $in->employeeCode, $in->employeeName, $in->localDateTime, $in->direction])
        ->toBe([4, '7', 'E-7', 'Ali', '2026-10-13 09:01:22', 'in'])
        ->and([$out->localDateTime, $out->direction])->toBe(['2026-10-13 18:10:05', 'out'])
        ->and([$other->localDateTime, $other->direction])->toBe(['2026-10-01 09:00:00', 'in']);
});

it('reads separate Date and Time columns with Excel serial values and I/O check types', function () {
    $day = ExcelDate::PHPToExcel(CarbonImmutable::parse('2026-10-02 00:00:00', 'UTC'));
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        ['User ID', 'Name', 'Date', 'Time', 'Check Type'],
        [12, 'Bilal', $day, 0.375, 'I'],
        [12, 'Bilal', $day, 0.75, 'O'],
    ]);
    $local = Storage::disk('local')->path(dviStore('', 'zk-serial.xlsx'));
    (new Xlsx($spreadsheet))->save($local);

    $rows = app(DevicePunchFileParser::class)->parse($local, 'zk-serial.xlsx')->rows;

    expect([$rows[0]->identifier, $rows[0]->localDateTime, $rows[0]->direction])->toBe(['12', '2026-10-02 09:00:00', 'in'])
        ->and([$rows[1]->localDateTime, $rows[1]->direction])->toBe(['2026-10-02 18:00:00', 'out']);
});

it('reads a headerless ZKTeco attlog file', function () {
    $path = Storage::disk('local')->path(dviStore("     5\t2026-10-03 08:59:59\t1\t0\t1\t0\n     5\t2026-10-03 17:30:00\t1\t1\t1\t0\n", 'attlog.txt'));

    $file = app(DevicePunchFileParser::class)->parse($path, 'attlog.txt');

    expect($file->format)->toBe('zkteco_attlog')
        ->and(array_map(fn ($row): array => [$row->identifier, $row->localDateTime, $row->direction], $file->rows))
        ->toBe([['5', '2026-10-03 08:59:59', 'in'], ['5', '2026-10-03 17:30:00', 'out']]);
});

it('refuses a file without employee and date columns', function () {
    $path = Storage::disk('local')->path(dviStore("Foo,Bar\nx,y\n"));

    expect(fn () => app(DevicePunchFileParser::class)->parse($path, 'punches.csv'))
        ->toThrow(InvalidArgumentException::class, 'No employee and date');
});

// ----------------------------------------------------------------------
// Preview
// ----------------------------------------------------------------------

it('previews each row with its status and per-employee day counts, writing no attendance', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $ali = dviEmployee($company, ['name' => 'Ali', 'employee_number' => 'DVI-0001', 'biometric_id' => '101']);
    $sara = dviEmployee($company, ['name' => 'Sara', 'employee_number' => 'DVI-0002', 'joining_date' => '2026-10-10']);
    $omar = dviEmployee($company, ['name' => 'Omar', 'employee_number' => 'DVI-0003']);
    $manual = AttendanceRecord::query()->create([
        'company_id' => $company->id, 'employee_id' => $omar->id, 'attendance_date' => '2026-10-02',
        'check_in'   => '2026-10-02 04:00:00', 'status' => 'present', 'source' => 'manual',
    ]);

    $batch = dviPreview($hr, $company, dviCsv([
        ['0101', '2026-10-01 09:00', 'IN'],      // 2 ok (device id without the leading zero)
        ['101', '2026-10-01 18:00', 'OUT'],      // 3 ok
        ['DVI-0001', '2026-10-01 18:00', 'OUT'], // 4 duplicate: same punch as row 3
        ['999', '2026-10-01 09:00', 'IN'],       // 5 unknown employee
        ['DVI-0002', '2026-10-05 09:00', 'IN'],  // 6 before the joining date
        ['DVI-0002', '2026-13-45 09:00', 'IN'],  // 7 bad date
        ['DVI-0003', '2026-10-02 18:00', 'OUT'], // 8 day already has a manual entry
        ['101', '2026-10-01 18:00:40', 'OUT'],   // 9 double tap within a minute of row 3
        ['101', '2026-10-30 09:00', 'IN'],       // 10 in the future
    ]));

    expect($batch->status)->toBe(AttendanceImportBatch::STATUS_PREVIEW)
        ->and(dviStatuses($batch)->all())->toBe([
            2 => 'ok', 3 => 'ok', 4 => 'duplicate', 5 => 'unknown_employee', 6 => 'outside_employment',
            7 => 'bad_date', 8 => 'manual_day', 9 => 'duplicate', 10 => 'bad_date',
        ])
        ->and($batch->total_rows)->toBe(9)
        ->and($batch->duplicate_rows)->toBe(2)
        ->and($batch->problem_rows)->toBe(5)
        ->and($batch->flagged_days)->toBe(1);

    $rows = $batch->rows()->get()->keyBy('row_number');
    expect($rows[2]->employee_id)->toBe($ali->id)
        ->and($rows[2]->punched_at->toDateTimeString())->toBe('2026-10-01 04:00:00')
        ->and($rows[4]->message)->toContain('row 3')
        ->and($rows[6]->message)->toContain('joining date')
        ->and($rows[8]->message)->toContain('manual entries');

    $perEmployee = collect($batch->summary['employees'])->keyBy('name');
    expect($perEmployee['Ali'])->toMatchArray(['rows' => 2, 'days' => 1, 'new_days' => 1, 'review_days' => 0])
        ->and($perEmployee['Omar'])->toMatchArray(['days' => 0, 'flagged_days' => 1]);

    expect(AttendanceRecord::query()->where('employee_id', $ali->id)->exists())->toBeFalse()
        ->and($manual->fresh()->check_in->toDateTimeString())->toBe('2026-10-02 04:00:00');
});

it('matches an exact CNIC only for users who may see sensitive data', function () {
    $company = dviCompany();
    $employee = dviEmployee($company, ['cnic' => '35202-1234567-1']);
    $csv = dviCsv([['3520212345671', '2026-10-01 09:00', 'IN']]);

    expect(dviStatuses(dviPreview(dviHr($company), $company, $csv))->first())->toBe('unknown_employee');

    $batch = dviPreview(dviHr($company, HrPermissions::ViewSensitiveEmployeeData), $company, $csv);
    expect(dviStatuses($batch)->first())->toBe('ok')
        ->and($batch->rows()->first()->employee_id)->toBe($employee->id);
});

// ----------------------------------------------------------------------
// Import and pairing
// ----------------------------------------------------------------------

it('imports first in / last out by default through the interval service', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);

    $batch = dviImport($hr, $company, dviCsv([
        ['7', '2026-10-01 09:00', 'IN'], ['7', '2026-10-01 13:00', 'OUT'],
        ['7', '2026-10-01 14:00', 'IN'], ['7', '2026-10-01 18:00', 'OUT'],
    ]));

    $record = dviRecord($employee, '2026-10-01');
    expect($batch->status)->toBe(AttendanceImportBatch::STATUS_IMPORTED)
        ->and($batch->imported_rows)->toBe(4)
        ->and($batch->days_written)->toBe(1)
        ->and($batch->imported_by)->toBe($hr->id)
        ->and($record->source)->toBe('biometric')
        ->and($record->source_reference)->toBe('device_import:'.$batch->id)
        ->and($record->check_in->toDateTimeString())->toBe('2026-10-01 04:00:00')
        ->and($record->check_out->toDateTimeString())->toBe('2026-10-01 13:00:00')
        ->and((float) $record->worked_hours)->toBe(9.0)
        ->and($record->verification_status)->toBeNull()
        ->and(dviIntervals($record))->toBe([['04:00', '13:00']])
        ->and(dviStatuses($batch)->unique()->all())->toBe([2 => 'imported'])
        ->and($batch->rows()->pluck('attendance_record_id')->unique()->all())->toBe([$record->id]);
});

it('pairs every punch in order when the company chose that mode', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);
    $this->service->setPairingMode($hr, $company->id, PunchPairingMode::Sequential);

    $batch = dviImport($hr, $company, dviCsv([
        ['7', '2026-10-01 09:00', ''], ['7', '2026-10-01 13:00', ''],
        ['7', '2026-10-01 14:00', ''], ['7', '2026-10-01 18:00', ''],
    ]));

    $record = dviRecord($employee, '2026-10-01');
    expect($batch->pairing_mode)->toBe('paired')
        ->and(dviIntervals($record))->toBe([['04:00', '08:00'], ['09:00', '13:00']])
        ->and((float) $record->worked_hours)->toBe(8.0)
        ->and($record->check_out->toDateTimeString())->toBe('2026-10-01 13:00:00');
});

it('leaves the last check-out empty and flags the day for review on odd punches', function (string $mode, array $punches, array $intervals, float $hours) {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);
    $this->service->setPairingMode($hr, $company->id, PunchPairingMode::from($mode));

    $batch = dviImport($hr, $company, dviCsv(array_map(fn (string $time): array => ['7', '2026-10-01 '.$time, ''], $punches)));

    $record = dviRecord($employee, '2026-10-01');
    $flag = AttendanceVerification::query()->where('attendance_record_id', $record->id)->sole();
    expect(dviIntervals($record))->toBe($intervals)
        ->and($record->check_out)->toBeNull()
        ->and((float) $record->worked_hours)->toBe($hours)
        ->and($record->verification_status)->toBe('needs_review')
        ->and($batch->review_days)->toBe(1)
        ->and($flag->review_status)->toBe('pending')
        ->and($flag->flags)->toContain('missing_check_out', 'device_import');
})->with([
    'paired, three punches'       => ['paired', ['09:00', '13:00', '14:00'], [['04:00', '08:00'], ['09:00', null]], 4.0],
    'first in / last out, single' => ['first_last', ['09:00'], [['04:00', null]], 0.0],
]);

it('imports a punch only once and completes an open day from a later file', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);
    $morning = dviCsv([['7', '2026-10-01 09:00', 'IN']]);

    dviImport($hr, $company, $morning);
    $record = dviRecord($employee, '2026-10-01');
    expect($record->check_out)->toBeNull()->and($record->verification_status)->toBe('needs_review');

    // The same file again: every row is a duplicate and nothing changes.
    $again = dviImport($hr, $company, $morning);
    expect(dviStatuses($again)->all())->toBe([2 => 'duplicate'])
        ->and($again->rows()->first()->message)->toContain('Already imported')
        ->and(AttendanceInterval::query()->where('attendance_record_id', $record->id)->count())->toBe(1);

    // The full-day export: the morning punch is skipped, the evening one closes the day.
    $full = dviImport($hr, $company, dviCsv([['7', '2026-10-01 09:00', 'IN'], ['7', '2026-10-01 17:30', 'OUT']]));
    $record->refresh();

    expect(dviStatuses($full)->all())->toBe([2 => 'duplicate', 3 => 'imported'])
        ->and(AttendanceRecord::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(dviIntervals($record))->toBe([['04:00', '12:30']])
        ->and((float) $record->worked_hours)->toBe(8.5)
        ->and($record->verification_status)->toBeNull()
        ->and(AttendanceVerification::query()->where('attendance_record_id', $record->id)->sole()->review_status)->toBe('withdrawn');
});

it('never overwrites a day with manual entries or an imported day corrected since', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);
    $manual = AttendanceRecord::query()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'attendance_date' => '2026-10-02',
        'check_in'   => '2026-10-02 05:00:00', 'check_out' => '2026-10-02 12:00:00', 'status' => 'present', 'source' => 'manual',
    ]);
    $manualIntervals = dviIntervals($manual);

    $batch = dviImport($hr, $company, dviCsv([
        ['7', '2026-10-01 09:00', ''], ['7', '2026-10-01 18:00', ''],
        ['7', '2026-10-02 08:00', ''], ['7', '2026-10-02 19:00', ''],
    ]));

    expect(dviStatuses($batch)->all())->toBe([2 => 'imported', 3 => 'imported', 4 => 'manual_day', 5 => 'manual_day'])
        ->and($batch->flagged_days)->toBe(1)
        ->and($manual->fresh()->check_in->toDateTimeString())->toBe('2026-10-02 05:00:00')
        ->and(dviIntervals($manual->fresh()))->toBe($manualIntervals);

    // HR corrects the imported day; a later file must leave the correction alone.
    $imported = dviRecord($employee, '2026-10-01');
    $imported->update(['check_out' => '2026-10-01 12:30:00']);
    $later = dviImport($hr, $company, dviCsv([['7', '2026-10-01 20:00', '']]));

    expect(dviStatuses($later)->all())->toBe([2 => 'manual_day'])
        ->and($later->rows()->first()->message)->toContain('corrected after an earlier device import')
        ->and($imported->fresh()->check_out->toDateTimeString())->toBe('2026-10-01 12:30:00');
});

it('reports a locked month per row and imports the other days', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);
    dviLockMonth('2026-09');

    $batch = dviImport($hr, $company, dviCsv([
        ['7', '2026-09-30 09:00', ''], ['7', '2026-09-30 18:00', ''],
        ['7', '2026-10-01 09:00', ''], ['7', '2026-10-01 18:00', ''],
    ]));

    expect(dviStatuses($batch)->all())->toBe([2 => 'locked_month', 3 => 'locked_month', 4 => 'imported', 5 => 'imported'])
        ->and($batch->rows()->where('status', 'locked_month')->first()->message)->toContain('locked')
        ->and(dviRecord($employee, '2026-09-30'))->toBeNull()
        ->and(dviRecord($employee, '2026-10-01'))->not->toBeNull()
        ->and($batch->status)->toBe(AttendanceImportBatch::STATUS_IMPORTED);
});

// ----------------------------------------------------------------------
// Undo
// ----------------------------------------------------------------------

it('undoes a batch: removes its untouched days, keeps corrected ones, and the file can be imported again', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);
    $csv = dviCsv([
        ['7', '2026-10-01 09:00', ''], ['7', '2026-10-01 18:00', ''],
        ['7', '2026-10-02 09:00', ''], ['7', '2026-10-02 18:00', ''],
    ]);
    $batch = dviImport($hr, $company, $csv);
    $dayOne = dviRecord($employee, '2026-10-01');
    $dayTwo = dviRecord($employee, '2026-10-02');
    $dayTwo->update(['check_out' => '2026-10-02 14:00:00']);

    $summary = $this->service->undo($hr, $batch);

    expect($summary)->toMatchArray(['days_removed' => 1, 'days_rebuilt' => 0, 'days_kept' => 1])
        ->and(AttendanceRecord::query()->whereKey($dayOne->id)->exists())->toBeFalse()
        ->and(AttendanceInterval::query()->where('attendance_record_id', $dayOne->id)->exists())->toBeFalse()
        ->and($dayTwo->fresh()->check_out->toDateTimeString())->toBe('2026-10-02 14:00:00')
        ->and($batch->fresh()->status)->toBe(AttendanceImportBatch::STATUS_PARTIALLY_UNDONE)
        ->and($batch->fresh()->undone_by)->toBe($hr->id)
        ->and(dviStatuses($batch)->all())->toBe([2 => 'undone', 3 => 'undone', 4 => 'imported', 5 => 'imported']);

    // Day one's punches are free again; day two's are still live.
    expect(dviStatuses(dviPreview($hr, $company, $csv))->all())->toBe([2 => 'ok', 3 => 'ok', 4 => 'duplicate', 5 => 'duplicate']);
});

it('rebuilds a day from an earlier import when a later one is undone', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);
    $first = dviImport($hr, $company, dviCsv([['7', '2026-10-01 09:00', '']]));
    $second = dviImport($hr, $company, dviCsv([['7', '2026-10-01 18:00', '']]));
    $record = dviRecord($employee, '2026-10-01');
    expect(dviIntervals($record))->toBe([['04:00', '13:00']]);

    expect($this->service->undo($hr, $second))->toMatchArray(['days_removed' => 0, 'days_rebuilt' => 1]);

    $record->refresh();
    expect(dviIntervals($record))->toBe([['04:00', null]])
        ->and($record->check_out)->toBeNull()
        ->and($record->verification_status)->toBe('needs_review')
        ->and($record->source_reference)->toBe('device_import:'.$first->id);

    expect($this->service->undo($hr, $first))->toMatchArray(['days_removed' => 1])
        ->and(AttendanceRecord::query()->whereKey($record->id)->exists())->toBeFalse();
});

it('refuses the whole undo when a day is in a locked month', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);
    $batch = dviImport($hr, $company, dviCsv([
        ['7', '2026-09-30 09:00', ''], ['7', '2026-09-30 18:00', ''],
        ['7', '2026-10-01 09:00', ''], ['7', '2026-10-01 18:00', ''],
    ]));
    dviLockMonth('2026-09');

    expect(fn () => $this->service->undo($hr, $batch))->toThrow(RuntimeException::class, 'Undo refused');

    expect(dviRecord($employee, '2026-09-30'))->not->toBeNull()
        ->and(dviRecord($employee, '2026-10-01'))->not->toBeNull()
        ->and($batch->fresh()->status)->toBe(AttendanceImportBatch::STATUS_IMPORTED)
        ->and(dviStatuses($batch)->unique()->values()->all())->toBe(['imported']);
});

// ----------------------------------------------------------------------
// Company isolation and access
// ----------------------------------------------------------------------

it('builds a template the parser reads and a row-level Excel report', function () {
    $excel = app(DeviceImportExcel::class);
    $template = $excel->template();
    expect($template->getSheet(0)->rangeToArray('A1:C1')[0])->toBe(DeviceImportExcel::TEMPLATE_HEADERS);

    $template->getSheet(0)->fromArray([['77', '2026-10-01 09:00', 'IN'], ['404', '2026-10-01 18:00', 'OUT']], null, 'A2');
    $stored = dviStore('', 'filled.xlsx');
    (new Xlsx($template))->save(Storage::disk('local')->path($stored));

    $company = dviCompany();
    $hr = dviHr($company);
    dviEmployee($company, ['name' => 'Report Person', 'biometric_id' => '77']);
    $batch = app(DeviceAttendanceImportService::class)->preview($hr, $company->id, $stored, 'filled.xlsx');

    expect(dviStatuses($batch)->all())->toBe([2 => 'ok', 3 => 'unknown_employee']);

    $report = $excel->report($batch);
    $rows = $report->getSheetByName('Rows')->toArray();
    expect($rows[0][0])->toBe('Row')
        ->and($rows[1][6])->toBe('Report Person')
        ->and($rows[1][10])->toBe('OK')
        ->and($rows[2][10])->toBe('Unknown employee')
        ->and($rows[2][11])->toContain('404')
        ->and($report->getSheetByName('Employees')->getCell('A2')->getValue())->toBe('Report Person');
});

it('keeps a device ID unique per company only', function () {
    $companyA = dviCompany('Alpha');
    $companyB = dviCompany('Beta');
    dviEmployee($companyA, ['biometric_id' => '55']);

    expect(dviEmployee($companyB, ['biometric_id' => '55'])->biometric_id)->toBe('55')
        ->and(fn () => dviEmployee($companyA, ['biometric_id' => '55']))->toThrow(UniqueConstraintViolationException::class);
});

it('keeps companies apart: matching, preview, import and undo', function () {
    $companyA = dviCompany('Alpha');
    $companyB = dviCompany('Beta');
    $hrA = dviHr($companyA);
    $hrB = dviHr($companyB);
    $mine = dviEmployee($companyA, ['biometric_id' => '7']);
    $theirs = dviEmployee($companyB, ['biometric_id' => '8', 'employee_number' => 'BETA-0001']);

    $batch = dviPreview($hrA, $companyA, dviCsv([
        ['7', '2026-10-01 09:00', ''],
        ['8', '2026-10-01 09:00', ''],
        ['BETA-0001', '2026-10-01 09:05', ''],
    ]));

    expect(dviStatuses($batch)->all())->toBe([2 => 'ok', 3 => 'unknown_employee', 4 => 'unknown_employee'])
        ->and(fn () => $this->service->preview($hrB, $companyA->id, dviStore(dviCsv([['7', '2026-10-01 09:00', '']])), 'x.csv'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->import($hrB, $batch))->toThrow(AuthorizationException::class);

    $this->service->import($hrA, $batch);
    expect(fn () => $this->service->undo($hrB, $batch->fresh()))->toThrow(AuthorizationException::class)
        ->and(dviRecord($mine, '2026-10-01')->company_id)->toBe($companyA->id)
        ->and(AttendanceRecord::query()->where('employee_id', $theirs->id)->exists())->toBeFalse();
});

it('opens the Device import page for HR only and lists only the active company\'s imports', function () {
    $company = dviCompany();
    $other = dviCompany('Other');
    $hr = dviHr($company);
    $lineManager = geoGrant(geoEmployee($company, null)[0], HrPermissions::ManageAttendance);
    $mine = dviPreview($hr, $company, dviCsv([['1', '2026-10-01 09:00', '']]), 'mine.csv');
    $theirs = dviPreview(dviHr($other), $other, dviCsv([['1', '2026-10-01 09:00', '']]), 'theirs.csv');

    $this->actingAs($lineManager);
    expect(DeviceAttendanceImport::canAccess())->toBeFalse();

    $this->actingAs($hr);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    expect(DeviceAttendanceImport::canAccess())->toBeTrue();

    Livewire::test(DeviceAttendanceImport::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->call('openPreview', $theirs->id)
        ->assertSet('previewBatchId', null)
        ->call('openPreview', $mine->id)
        ->assertSet('previewBatchId', $mine->id)
        ->assertSee('mine.csv')
        ->callAction('template')
        ->assertFileDownloaded('device-attendance-template.xlsx');
});

it('previews and imports an uploaded file from the page', function () {
    $company = dviCompany();
    $hr = dviHr($company);
    $employee = dviEmployee($company, ['biometric_id' => '7']);
    $this->actingAs($hr);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $upload = UploadedFile::fake()->createWithContent('device.csv', dviCsv([['7', '2026-10-01 09:00', ''], ['7', '2026-10-01 18:00', '']]));

    $page = Livewire::test(DeviceAttendanceImport::class)
        ->fillForm(['file' => $upload, 'date_order' => 'auto'])
        ->call('preview')
        ->assertHasNoFormErrors();

    $batch = AttendanceImportBatch::query()->where('company_id', $company->id)->sole();
    $page->assertSet('previewBatchId', $batch->id)
        ->assertSee('device.csv')
        ->callAction('import')
        ->assertNotified()
        ->assertSet('previewBatchId', null);

    expect($batch->fresh()->status)->toBe(AttendanceImportBatch::STATUS_IMPORTED)
        ->and(dviRecord($employee, '2026-10-01')->source)->toBe('biometric');
});
