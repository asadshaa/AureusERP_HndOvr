<?php

namespace Database\Seeders;

use App\Models\DemoRecord;
use App\Services\Demo\DemoPurger;
use App\Services\Demo\DemoRecorder;
use App\Services\Demo\DemoSandbox;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoCatalog;
use Database\Seeders\Demo\DemoPdf;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeCodeSequence;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\DocumentExpiryReminderService;
use Webkul\Employee\Services\EmployeeDocumentService;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Recruitment\Enums\InterviewMode;
use Webkul\Recruitment\Enums\InterviewRecommendation;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\Interview;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Models\Stage;
use Webkul\Recruitment\Models\UTMSource;
use Webkul\Recruitment\Services\CandidateApplicationService;
use Webkul\Recruitment\Services\CvAnalysis\CvAnalyser;
use Webkul\Recruitment\Services\InterviewService;
use Webkul\Recruitment\Services\JobOpeningPublicationService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\TimeOff\Services\LeaveBalanceService;
use Webkul\TimeOff\Services\LeaveCancellationService;

/**
 * Lived-in HR demo data for ShipLine and OpenPort (php artisan demo:seed):
 * about 23 staff each with reporting lines, ~10 weeks of office attendance
 * (10:00-18:00 Karachi, intervals included), leave in every state, loans,
 * salary advances and HR letters, employee documents (some expiring) and a
 * recruitment pipeline with interviews this week and next.
 *
 * Everything that has a workflow goes through the application's own
 * services (LeaveApprovalService, LeaveCancellationService,
 * EmployeeRequestService, EmployeeDocumentService, the recruitment
 * services), with the clock set to a plausible past moment for each step so
 * the approval trails read naturally. Attendance is written in bulk, day
 * summaries computed from the intervals exactly as AttendanceIntervalService
 * does. Every created row is recorded in demo_records (DemoRecorder), so a
 * re-run creates nothing twice and demo:purge removes only demo rows.
 *
 * Truck It In is never touched: it is a live company (real staff, real
 * attendance reports and digests, a live careers website).
 */
class DemoActivitySeeder extends Seeder
{
    private const TIMEZONE = 'Asia/Karachi';

    private const OFFICE_START = 600;

    private const OFFICE_END = 1080;

    /** @var array<int, string> */
    public array $companyNames = ['ShipLine', 'OpenPort'];

    public bool $freshActivity = false;

    /** @var array<string, array<string, int|string>> */
    public array $summary = [];

    /** @var array<int, string> */
    public array $warnings = [];

    private Carbon $realNow;

    private Carbon $today;

    private Carbon $windowStart;

    private Company $company;

    /** @var array<string, mixed> */
    private array $setup = [];

    /** @var array<string, array{employee: Employee, user: User, spec: array<string, mixed>, existing: bool}> */
    private array $people = [];

    /** @var array<string, LeaveType> */
    private array $leaveTypes = [];

    /** @var array<int, string> */
    private array $credentials = [];

    /** @var array<int, string> */
    private array $usedCnics = [];

    private int $reminders = 0;

    /** @var array<int, Carbon> */
    private array $upcomingSlots = [];

    public function __construct(
        protected DemoRecorder $recorder,
        protected DemoSandbox $sandbox,
    ) {}

    public function run(): void
    {
        $this->realNow = Carbon::now();
        $this->today = Carbon::now(self::TIMEZONE)->startOfDay();
        $this->windowStart = $this->today->copy()->subWeeks(10)->startOfWeek();

        $this->sandbox->isolate();
        $this->ensureBaseStaff();
        $this->recorder->start();

        try {
            foreach ($this->companyNames as $name) {
                $this->seedCompany($name);
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            Auth::guard('web')->forgetUser();
            $this->recorder->stop();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /** The seven base staff per company come from DemoCompanyStaffSeeder; run it when they are missing. */
    private function ensureBaseStaff(): void
    {
        foreach ($this->companyNames as $name) {
            $setup = DemoCatalog::COMPANIES[$name] ?? null;
            $first = $setup ? array_key_first($setup['existing']) : null;

            if ($first && ! User::query()->where('email', "{$first}@{$setup['domain']}")->exists()) {
                $this->command?->info('Base demo staff missing: running DemoCompanyStaffSeeder first.');
                $seeder = app(DemoCompanyStaffSeeder::class);
                if ($this->command) {
                    $seeder->setCommand($this->command);
                }
                $seeder->run();

                return;
            }
        }
    }

    private function seedCompany(string $name): void
    {
        $setup = DemoCatalog::COMPANIES[$name] ?? null;
        $company = Company::query()->where('name', $name)->first();

        if (! $setup || ! $company) {
            $this->warn("{$name}: company not found, skipped.");

            return;
        }

        $this->company = $company;
        $this->setup = $setup;
        $this->people = [];
        $this->usedCnics = [];
        $this->reminders = 0;
        $this->recorder->resetCreated();

        if ($this->freshActivity) {
            $result = app(DemoPurger::class)->purge([$company->id], activityOnly: true);
            $this->command?->info("{$name}: previous demo activity removed ({$result['deleted']} rows, {$result['files']} files).");
        }

        $this->recorder->context($company->id, DemoRecord::PHASE_STAFF);
        $this->credentials = [];
        $this->recorder->transaction(fn () => $this->seedStaff());
        $this->writeCredentials();
        $this->loadPeople();

        $this->recorder->context($company->id, DemoRecord::PHASE_ACTIVITY);
        $this->leaveTypes = $this->companyLeaveTypes();

        $this->removeLegacyAttendance();
        $this->seedLeaves();
        $this->recorder->transaction(fn () => $this->seedAttendance());
        $this->seedDocuments();
        $this->seedEmployeeRequests();
        $this->seedRecruitment();
        $this->sendDocumentReminders();
        $this->recorder->flush();
        $this->settleNotifications();

        $this->summary[$name] = ['created' => $this->recorder->created(), 'totals' => $this->totals() + ['document_reminders_sent' => $this->reminders]];
        $this->recorder->context(null, DemoRecord::PHASE_STAFF);
    }

    // ---------------------------------------------------------------------
    // Staff
    // ---------------------------------------------------------------------

    private function seedStaff(): void
    {
        $domain = $this->setup['domain'];
        $hr = $this->baseUser(array_key_first($this->setup['existing']));
        Auth::guard('web')->setUser($hr);

        $departments = Department::query()->where('company_id', $this->company->id)->get()->keyBy('name');
        $operations = $departments->get('Operations');

        foreach (DemoCatalog::NEW_DEPARTMENTS as $deptName) {
            if (! $departments->has($deptName)) {
                $departments->put($deptName, Department::query()->create([
                    'company_id' => $this->company->id,
                    'name'       => $deptName,
                    'parent_id'  => $operations?->id,
                    'creator_id' => $hr->id,
                ]));
            }
        }

        $calendarId = Employee::query()->where('company_id', $this->company->id)->whereNotNull('calendar_id')->value('calendar_id')
            ?? Employee::query()->where('company_id', 1)->whereNotNull('calendar_id')->value('calendar_id');
        // The code sequence moves past the new employees' codes; purge puts it back.
        $sequence = EmployeeCodeSequence::query()->where('company_id', $this->company->id)->first();
        $nextNumber = $sequence?->next_number;

        $employees = Employee::query()->where('company_id', $this->company->id)->with('user')->get()
            ->filter(fn (Employee $employee): bool => $employee->user !== null)
            ->keyBy(fn (Employee $employee): string => Str::before((string) $employee->user->email, '@'));

        foreach ($this->setup['staff'] as $key => $spec) {
            $email = "{$key}@{$domain}";
            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                $password = Str::password(14, symbols: false);
                $user = User::query()->create([
                    'name'               => $spec['name'],
                    'email'              => $email,
                    'password'           => $password,
                    'default_company_id' => $this->company->id,
                    'is_active'          => true,
                ]);
                // Set apart: creating a user copies its fields to a new contact, which has no such column.
                $user->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();
                $user->syncRoles(Role::query()->whereIn('name', $spec['roles'])->get());
                $this->credentials[] = "{$this->company->name}\t{$spec['title']}\t{$email}\t{$password}";
            }

            if ($employees->has($key)) {
                continue;
            }

            $parent = $spec['parent'] ? $employees->get($spec['parent']) : null;
            $employees->put($key, Employee::query()->create([
                'company_id'        => $this->company->id,
                'user_id'           => $user->id,
                'name'              => $spec['name'],
                'job_title'         => $spec['title'],
                'work_email'        => $email,
                'department_id'     => $departments->get($spec['dept'])?->id,
                'parent_id'         => $parent?->id,
                'calendar_id'       => $calendarId,
                'employee_type'     => 'employee',
                'is_active'         => true,
                'time_zone'         => self::TIMEZONE,
                'creator_id'        => $hr->id,
                ...$this->employmentDates($spec['joined'], (bool) array_intersect($spec['roles'], ['manager'])),
                ...$this->personalDetails($key, $spec, $spec['name']),
            ]));
        }

        if ($sequence && (int) $sequence->refresh()->next_number !== (int) $nextNumber) {
            $this->recorder->rememberFill($sequence, ['next_number' => $nextNumber], ['next_number' => $sequence->next_number]);
        }

        $this->fillBaseStaff($employees);
        $this->assignDepartmentManagers($departments, $employees);
    }

    /**
     * The base staff had only a name, title and login: fill their empty
     * fields (joining date, CNIC, salary...) and put them in the reporting
     * lines. Each value written is remembered so demo:purge can restore it.
     *
     * @param  Collection<string, Employee>  $employees
     */
    private function fillBaseStaff(Collection $employees): void
    {
        // The base seeder put everyone but the HR manager under its operations manager.
        $opsManagerId = $employees->get($this->setup['base_manager'])?->id;

        foreach ($this->setup['existing'] as $key => $spec) {
            $employee = $employees->get($key);

            if (! $employee) {
                $this->warn("{$this->company->name}: base employee {$key} not found, skipped.");

                continue;
            }

            $wanted = [
                ...$this->employmentDates($spec['joined'], false),
                ...$this->personalDetails($key, $spec, (string) $employee->name),
            ];
            unset($wanted['marital'], $wanted['children'], $wanted['spouse_complete_name'], $wanted['employment_status']);

            $changes = [];
            foreach ($wanted as $column => $value) {
                $current = $employee->getRawOriginal($column);
                if (($current === null || $current === '') && $value !== null) {
                    $changes[$column] = $value;
                }
            }

            $parent = $employees->get($spec['parent']);
            if ($parent && (int) $employee->parent_id !== (int) $parent->id
                && ($employee->parent_id === null || (int) $employee->parent_id === (int) $opsManagerId)) {
                $changes['parent_id'] = $parent->id;
            }

            if ($changes === []) {
                continue;
            }

            $original = array_map(fn (string $column) => $employee->getRawOriginal($column), array_combine(array_keys($changes), array_keys($changes)));
            $original['leave_manager_id'] = $employee->getRawOriginal('leave_manager_id');

            $employee->fill($changes)->save();
            $employee->refresh();

            $applied = [];
            foreach (array_keys($original) as $column) {
                $applied[$column] = $employee->getRawOriginal($column);
            }

            $this->recorder->rememberFill($employee, $original, $applied);
        }
    }

    /**
     * @param  Collection<string, Department>  $departments
     * @param  Collection<string, Employee>  $employees
     */
    private function assignDepartmentManagers(Collection $departments, Collection $employees): void
    {
        foreach ($this->setup['department_managers'] as $deptName => $key) {
            $department = $departments->get($deptName);
            $manager = $employees->get($key);

            if (! $department || ! $manager || (int) $department->manager_id === (int) $manager->id) {
                continue;
            }

            $original = ['manager_id' => $department->manager_id];
            $department->forceFill(['manager_id' => $manager->id])->save();
            $this->recorder->rememberFill($department, $original, ['manager_id' => $manager->id]);
        }
    }

    /** @return array<string, mixed> */
    private function employmentDates(string $joined, bool $manager): array
    {
        $joining = Carbon::parse($joined);
        $probationEnd = $joining->copy()->addMonths($manager ? 6 : 3)->subDay();
        $confirmed = $probationEnd->lessThan($this->today);

        return [
            'joining_date'       => $joining->toDateString(),
            'probation_end_date' => $probationEnd->toDateString(),
            'confirmation_date'  => $confirmed ? $probationEnd->copy()->addDay()->toDateString() : null,
            'employment_status'  => $confirmed ? 'active' : 'probation',
        ];
    }

    /**
     * Pakistani personal details, the same every run for the same person.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function personalDetails(string $key, array $spec, string $name): array
    {
        $r = $this->rng("person:{$this->company->id}:{$key}");
        $city = DemoCatalog::CITIES[$spec['city']] ?? DemoCatalog::CITIES['karachi'];
        $female = $spec['gender'] === 'female';
        $surname = Str::afterLast($name, ' ');
        $married = ($spec['marital'] ?? 'single') === 'married';
        [$street, $zip] = $this->pick($r, $city['areas']);
        $father = $this->pick($r, DemoCatalog::MALE_RELATIVES).' '.$surname;
        $spouse = $married
            ? ($female ? $this->pick($r, ['Usman Tariq', 'Salman Haider', 'Asif Nawaz', 'Rehan Qamar', 'Ahmed Raza', 'Kashan Ali'])
                : $this->pick($r, ['Sadia', 'Saba', 'Nazia', 'Huma', 'Sobia', 'Mehreen', 'Fatima', 'Amna']).' '.$surname)
            : null;

        return [
            'cnic'                   => $this->cnic($r, $city['prefixes'], $female),
            'mobile_phone'           => $this->mobile($r),
            'work_phone'             => '+92 21 '.$r->getInt(3200, 3599).' '.$r->getInt(1000, 9999),
            'gender'                 => $spec['gender'],
            'birthday'               => $spec['born'],
            'place_of_birth'         => $city['city'],
            'marital'                => $married ? 'married' : 'single',
            'spouse_complete_name'   => $spouse,
            'children'               => $married ? $r->getInt(0, 3) : 0,
            'private_street1'        => $street,
            'private_city'           => $city['city'],
            'private_zip'            => $zip,
            'private_country_id'     => $this->pakistanId(),
            'country_id'             => $this->pakistanId(),
            'emergency_contact'      => $married ? $spouse : $father,
            'emergency_relationship' => $married ? 'Spouse' : 'Father',
            'emergency_phone'        => $this->mobile($r),
            'blood_group'            => $this->pick($r, DemoCatalog::BLOOD_GROUPS),
            'base_salary'            => $spec['salary'],
            'salary_currency_id'     => $this->company->currency_id,
            'salary_grade'           => $spec['grade'],
            'study_field'            => $spec['study'][0],
            'study_school'           => $spec['study'][1],
            'ntn'                    => $spec['salary'] >= 150000 ? sprintf('%07d-%d', $r->getInt(1000000, 9999999), $r->getInt(0, 9)) : null,
        ];
    }

    /** A CNIC of the right shape (12345-1234567-1, last digit odd for men, even for women), unique in the company. */
    private function cnic(Randomizer $r, array $prefixes, bool $female): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $last = $r->getInt(0, 4) * 2 + ($female ? 0 : 1);
            $cnic = $this->pick($r, $prefixes).'-'.$r->getInt(1000000, 9999999).'-'.$last;

            if (! in_array($cnic, $this->usedCnics, true)
                && ! Employee::withTrashed()->where('company_id', $this->company->id)->where('cnic', $cnic)->exists()) {
                $this->usedCnics[] = $cnic;

                return $cnic;
            }
        }

        throw new RuntimeException('Could not find a free demo CNIC.');
    }

    private function mobile(Randomizer $r): string
    {
        return '+92 3'.$this->pick($r, ['00', '01', '03', '05', '06', '08', '11', '12', '14', '15', '21', '23', '33', '34', '36', '45', '47']).' '.$r->getInt(1000000, 9999999);
    }

    private function pakistanId(): ?int
    {
        static $id = false;

        if ($id === false) {
            $id = DB::table('countries')->where('code', 'PK')->value('id');
        }

        return $id !== null ? (int) $id : null;
    }

    private function loadPeople(): void
    {
        $domain = $this->setup['domain'];
        $specs = $this->setup['existing'] + $this->setup['staff'];
        $users = User::query()->whereIn('email', array_map(fn (string $key): string => "{$key}@{$domain}", array_keys($specs)))->get()->keyBy('email');

        foreach ($specs as $key => $spec) {
            $user = $users->get("{$key}@{$domain}");
            $employee = $user ? Employee::query()->where('company_id', $this->company->id)->where('user_id', $user->id)->first() : null;

            if ($user && $employee) {
                $this->people[$key] = ['employee' => $employee, 'user' => $user, 'spec' => $spec, 'existing' => isset($this->setup['existing'][$key])];
            }
        }
    }

    private function isManager(string $key): bool
    {
        return $key === $this->setup['base_manager'] || in_array('manager', (array) ($this->setup['staff'][$key]['roles'] ?? []), true);
    }

    private function baseUser(string $key): User
    {
        return User::query()->where('email', "{$key}@{$this->setup['domain']}")->firstOrFail();
    }

    private function hrManagerKey(): string
    {
        return (string) array_key_first($this->setup['existing']);
    }

    private function writeCredentials(): void
    {
        if ($this->credentials === []) {
            return;
        }

        $file = storage_path('app/private/demo-accounts.txt');
        @mkdir(dirname($file), 0755, true);
        $lines = is_file($file) ? $this->credentials : ["Company\tRole\tEmail\tPassword", ...$this->credentials];
        file_put_contents($file, implode(PHP_EOL, $lines).PHP_EOL, FILE_APPEND);
        $this->command?->info(count($this->credentials).' demo login(s) created for '.$this->company->name.'; passwords in storage/app/private/demo-accounts.txt');
        $this->credentials = [];
    }

    // ---------------------------------------------------------------------
    // Leave
    // ---------------------------------------------------------------------

    /** @return array<string, LeaveType> */
    private function companyLeaveTypes(): array
    {
        $types = LeaveType::query()->where('company_id', $this->company->id)->where('is_active', true)->get()->keyBy('name');

        return array_filter([
            'annual' => $types->get('Annual Leave'),
            'sick'   => $types->get('Sick Leave'),
            'casual' => $types->get('Casual Leave'),
            'half'   => $types->get('Half day'),
            'short'  => $types->get('Short leave'),
        ]);
    }

    private function seedLeaves(): void
    {
        if (count($this->leaveTypes) < 5) {
            $this->warn("{$this->company->name}: leave types missing, leave skipped.");

            return;
        }

        $index = 0;
        foreach ($this->people as $key => $person) {
            foreach ($this->planLeaves($key, $person, $index) as $item) {
                $demoKey = "leave:{$this->company->id}:{$key}:{$item['slot']}";

                if ($this->recorder->has($demoKey)) {
                    continue;
                }

                try {
                    $this->runLeave($person, $item, $demoKey);
                } catch (Throwable $e) {
                    $this->warn("{$this->company->name}: leave {$demoKey} skipped: ".Str::limit($e->getMessage(), 180));
                }
            }

            $index++;
        }
    }

    /**
     * @param  array{employee: Employee, user: User, spec: array<string, mixed>, existing: bool}  $person
     * @return array<int, array<string, mixed>>
     */
    private function planLeaves(string $key, array $person, int $index): array
    {
        $employee = $person['employee'];
        $joined = $this->localDate($employee->joining_date ?? '2024-01-01');
        $isHrManager = $key === $this->hrManagerKey();
        $year = (int) $this->today->year;

        // The plan never depends on the demo leave already created, so a re-run plans the same slots (and skips them).
        $demoLeaveIds = DemoRecord::query()->where('record_table', 'time_off_leaves')->pluck('record_id')->map(fn ($id): int => (int) $id)->all();
        $occupied = $this->occupiedDates($employee, $demoLeaveIds);
        $balance = [];
        foreach (['annual', 'sick', 'casual'] as $type) {
            $balance[$type] = $this->baseBalance($employee, $this->leaveTypes[$type], $year, $demoLeaveIds);
        }

        $pastFrom = $joined->copy()->addDays(10)->max($this->windowStart);
        $pastTo = $this->today->copy()->subDays(2);
        $hasPast = $pastFrom->lessThan($pastTo);
        $slotRng = fn (string $slot): Randomizer => $this->rng("leave-plan:{$this->company->id}:{$key}:{$slot}");
        $plan = [];

        $add = function (string $slot, string $type, Carbon $from, int $days, string $outcome, array $extra = []) use (&$plan, &$occupied, &$balance): bool {
            $range = $this->businessRange($from, $type === 'half' || $type === 'short' ? 1 : $days);

            if ($range === [] || array_intersect($range, array_keys($occupied)) !== []) {
                return false;
            }

            $cost = in_array($type, ['half', 'short'], true) ? 0 : count($range);
            if (isset($balance[$type]) && $balance[$type] < $cost + 0.5) {
                return false;
            }
            if (isset($balance[$type]) && ! in_array($outcome, ['refused_lm', 'refused_hr', 'withdrawn', 'cancelled'], true)) {
                $balance[$type] -= $cost;
            }

            foreach ($range as $date) {
                $occupied[$date] = true;
            }

            $plan[] = ['slot' => $slot, 'type' => $type, 'from' => $range[0], 'to' => end($range), 'outcome' => $outcome] + $extra;

            return true;
        };

        $between = function (string $slot, string $type, int $days, string $outcome, Carbon $from, Carbon $to, Randomizer $r, array $extra = []) use ($add): void {
            for ($attempt = 0; $attempt < 25; $attempt++) {
                $start = $this->randomWeekday($r, $from, $to);
                if ($start && $add($slot, $type, $start, $days, $outcome, $extra)) {
                    return;
                }
            }
        };

        $ahead = fn (int $days): Carbon => $this->today->copy()->addDays($days);

        // Today's absentees: an approved leave that covers today.
        if ($index % 11 === 3 && ! $isHrManager && $this->today->isWeekday()) {
            $add('today', $index % 2 ? 'sick' : 'casual', $this->today->copy(), 1, 'approved');
        }

        // Earlier in the year (no attendance then): one approved annual leave.
        $r = $slotRng('early');
        if (! $isHrManager && $joined->lessThan(Carbon::create($year, 5, 1, 0, 0, 0, self::TIMEZONE)) && $this->chance($r, 0.65)) {
            $between('early', 'annual', $r->getInt(2, 4), 'approved', $joined->copy()->addDays(30)->max(Carbon::create($year, 2, 2, 0, 0, 0, self::TIMEZONE)), Carbon::create($year, 7, 31, 0, 0, 0, self::TIMEZONE), $r);
        }

        if (! $isHrManager && $hasPast) {
            foreach ([
                'sick'   => [0.55, 'sick', [1, 2], []],
                'casual' => [0.45, 'casual', [1, 1], []],
                'annual' => [0.3, 'annual', [2, 3], []],
                'half'   => [0.4, 'half', [1, 1], ['period' => null]],
                'short'  => [0.35, 'short', [1, 1], ['hours' => null]],
            ] as $slot => [$probability, $type, [$min, $max], $extra]) {
                $r = $slotRng($slot);
                if (! $this->chance($r, $probability)) {
                    continue;
                }
                if (array_key_exists('period', $extra)) {
                    $extra['period'] = $this->chance($r, 0.5) ? 'morning' : 'afternoon';
                }
                if (array_key_exists('hours', $extra)) {
                    $extra['hours'] = $this->pick($r, [[10.0, 12.0], [16.0, 18.0], [13.0, 15.0], [10.0, 11.5], [15.5, 18.0]]);
                }
                $between($slot, $type, $r->getInt($min, $max), 'approved', $pastFrom, $pastTo, $r, $extra);
            }
        }

        $r = $slotRng('refused');
        if ($index % 6 === 1 || $this->chance($r, 0.08)) {
            $refusedBy = $isHrManager || $index % 4 !== 1 ? 'refused_lm' : 'refused_hr';
            $this->chance($r, 0.5) && $hasPast
                ? $between('refused', $this->chance($r, 0.5) ? 'casual' : 'annual', $r->getInt(1, 3), $refusedBy, $pastFrom, $pastTo, $r)
                : $between('refused', 'annual', $r->getInt(2, 3), $refusedBy, $ahead(6), $ahead(30), $r);
        }

        $r = $slotRng('upcoming');
        if ($index % 2 === 0 || $this->chance($r, 0.3)) {
            $outcome = $isHrManager ? 'pending_lm' : match ($index % 3) {
                0       => 'pending_lm',
                1       => 'pending_hr',
                default => 'approved',
            };
            $between('upcoming', 'annual', $r->getInt(2, 5), $outcome, $ahead(5), $ahead(40), $r);
        }

        $r = $slotRng('cancel');
        if (! $isHrManager && ($index % 8 === 5 || $index % 8 === 7)) {
            $annual = $index % 8 === 5;
            $between('cancel', $annual ? 'annual' : 'casual', $annual ? $r->getInt(2, 3) : 1, $index % 16 < 8 ? 'cancelled' : 'cancel_requested', $ahead(7), $ahead(30), $r);
        }

        $r = $slotRng('withdrawn');
        if ($index % 9 === 2) {
            $between('withdrawn', 'casual', 1, 'withdrawn', $ahead(3), $ahead(20), $r);
        }

        return $plan;
    }

    /**
     * Remaining days of a type without the demo leave: the approved
     * allocations minus the employee's other live leave.
     *
     * @param  array<int, int>  $demoLeaveIds
     */
    private function baseBalance(Employee $employee, LeaveType $type, int $year, array $demoLeaveIds): float
    {
        $usage = app(LeaveBalanceService::class)->usage($employee, $type, $year);
        $demoDays = (float) Leave::query()
            ->whereIn('id', $demoLeaveIds)
            ->where('employee_id', $employee->id)
            ->where('holiday_status_id', $type->id)
            ->whereIn('state', [State::VALIDATE_TWO->value, ...LeaveBalanceService::PENDING_STATES])
            ->whereRaw('YEAR(COALESCE(request_date_from, date_from)) = ?', [$year])
            ->sum('number_of_days');

        return round($usage['remaining'] + $demoDays, 2);
    }

    /**
     * @param  array{employee: Employee, user: User, spec: array<string, mixed>, existing: bool}  $person
     * @param  array<string, mixed>  $item
     */
    private function runLeave(array $person, array $item, string $demoKey): void
    {
        $employee = $person['employee'];
        $user = $person['user'];
        $r = $this->rng($demoKey);
        $from = Carbon::parse($item['from'], self::TIMEZONE);
        $to = Carbon::parse($item['to'], self::TIMEZONE);
        $type = $this->leaveTypes[$item['type']];
        $notes = [
            'annual' => ['Family wedding in Hyderabad', 'Visiting parents in Multan', 'Umrah', 'Family trip to the northern areas', "Sister's wedding", 'Shifting to a new house', 'Travelling to Lahore for a family event'],
            'sick'   => ['Fever and flu', 'Viral fever, rest advised by the doctor', 'Stomach infection', 'Migraine', 'Dental surgery', 'Dengue test and rest'],
            'casual' => ['Bank work', "Child's parent-teacher meeting", 'NADRA office for CNIC renewal', 'Car registration at the Excise office', 'Passport office appointment', 'Personal errand'],
            'half'   => ["Doctor's appointment", 'University convocation', "Son's school admission", 'Property registration', 'Bank visit'],
            'short'  => ['Parent-teacher meeting', 'Picking my mother up from hospital', 'Bank appointment', 'Visa biometrics appointment'],
        ];

        // When it was asked for: sick leave the same morning, anything else some days before.
        $submit = match ($item['type']) {
            'sick'          => $this->local($from, sprintf('08:%02d', $r->getInt(40, 59))),
            'short', 'half' => $this->workMoment($from->copy()->subDays($r->getInt(1, 4)), $r),
            default         => $this->workMoment($from->copy()->subDays($r->getInt(4, 16)), $r),
        };
        // Leave far ahead was asked for in the last days, not weeks from now.
        if ($item['type'] !== 'sick' && $submit->greaterThan($this->realNow->copy()->subDay())) {
            $submit = $this->workMoment($this->today->copy()->subDays($r->getInt(1, 9)), $r);
        }
        $submit = $submit->max($this->localDate($employee->joining_date ?? '2020-01-01')->setTime(10, 0));
        $lmAt = $this->workMoment($submit->copy()->addHours($r->getInt(2, 20)), $r)->max($submit->copy()->addMinutes(20));
        $hrAt = $this->workMoment($lmAt->copy()->addHours($r->getInt(1, 18)), $r)->max($lmAt->copy()->addMinutes(15));
        if ($item['type'] === 'sick' && $from->isSameDay($this->today)) {
            $lmAt = $submit->copy()->addMinutes($r->getInt(25, 50));
            $hrAt = $lmAt->copy()->addMinutes($r->getInt(20, 45));
        }
        $askAt = $this->workMoment($hrAt->copy()->addDays($r->getInt(1, 3)), $r)->max($hrAt->copy()->addMinutes(30));
        ['submit' => $submit, 'lm' => $lmAt, 'hr' => $hrAt, 'ask' => $askAt, 'cancel' => $cancelAt] = $this->fitBeforeNow([
            'submit' => $submit,
            'lm'     => $lmAt,
            'hr'     => $hrAt,
            'ask'    => $askAt,
            'cancel' => $this->workMoment($askAt->copy()->addDay(), $r),
        ]);

        $half = $item['type'] === 'half';
        $short = $item['type'] === 'short';
        [$hourFrom, $hourTo] = $item['hours'] ?? [null, null];
        $hours = $short ? $hourTo - $hourFrom : null;
        $businessDays = count($this->businessRange($from, null, $to));
        $calendarDays = (int) $from->diffInDays($to) + 1;
        $dayHours = max(1, (int) config('attendance.monthly_report.daily_required_minutes', 480)) / 60;

        if ($short) {
            $days = round($hours / $dayHours, 4);
            $display = rtrim(rtrim(number_format($hours, 2), '0'), '.').' hours';
        } elseif ($half) {
            $days = 0.5;
            $display = '0.5 day';
        } else {
            $days = $businessDays;
            $display = $businessDays.' working day'.($businessDays !== 1 ? 's' : '')
                .($calendarDays > $businessDays ? ' (+ '.($calendarDays - $businessDays).' weekend day'.($calendarDays - $businessDays !== 1 ? 's' : '').')' : '');
        }

        $leave = $this->at($submit, $user, fn (): Leave => $this->recorder->transaction(function () use ($employee, $user, $type, $from, $to, $half, $short, $hourFrom, $hourTo, $days, $hours, $display, $item, $notes, $r): Leave {
            $leave = Leave::query()->create([
                'user_id'                  => $user->id,
                'employee_id'              => $employee->id,
                'company_id'               => $employee->company_id,
                'employee_company_id'      => $employee->company_id,
                'department_id'            => $employee->department_id,
                'calendar_id'              => $employee->calendar_id,
                'holiday_status_id'        => $type->id,
                'request_date_from'        => $from->toDateString(),
                'request_date_to'          => $to->toDateString(),
                'date_from'                => $from->toDateString(),
                'date_to'                  => $to->toDateString(),
                'request_unit_half'        => $half,
                'request_date_from_period' => $half ? $item['period'] : null,
                'request_unit_hours'       => $short,
                'request_hour_from'        => $short ? $hourFrom : null,
                'request_hour_to'          => $short ? $hourTo : null,
                'number_of_days'           => $days,
                'number_of_hours'          => $short ? $hours : 8,
                'duration_display'         => $display,
                'private_name'             => $this->pick($r, $notes[$item['type']]),
                'state'                    => State::CONFIRM->value,
                'creator_id'               => $user->id,
            ]);

            app(LeaveApprovalService::class)->submit($leave, $user);

            return $leave;
        }));

        $this->recorder->mark($demoKey, $leave);

        $lmReasons = ['Peak dispatch week; please move it to next week.', 'Two team members are already off on these dates.', 'Month-end close; please apply after the 5th.', 'Vessel arrivals scheduled that week; cannot spare you.'];
        $hrReasons = ['Overlaps with the mandatory fire-safety training day.', 'Please keep annual leave for the December shutdown; apply for fewer days.'];

        match ($item['outcome']) {
            'approved', 'cancelled', 'cancel_requested' => $this->decideLeave($leave, $lmAt, true) && $this->decideLeave($leave, $hrAt, true),
            'pending_hr'                                => $this->decideLeave($leave, $lmAt, true),
            'refused_lm'                                => $this->decideLeave($leave, $lmAt, false, $this->pick($r, $lmReasons)),
            'refused_hr'                                => $this->decideLeave($leave, $lmAt, true) && $this->decideLeave($leave, $hrAt, false, $this->pick($r, $hrReasons)),
            'withdrawn'                                 => $this->at($lmAt, $user, fn () => $this->recorder->transaction(fn () => app(LeaveCancellationService::class)->withdraw($leave->fresh(), $user, $this->pick($r, ['Plans changed', 'No longer needed'])))),
            default                                     => null,
        };

        if (in_array($item['outcome'], ['cancelled', 'cancel_requested'], true) && $leave->fresh()->state === State::VALIDATE_TWO) {
            $this->at($askAt, $user, fn () => $this->recorder->transaction(fn () => app(LeaveCancellationService::class)->requestCancellation($leave->fresh(), $user, $this->pick($r, ['Family plans changed', 'The trip was postponed', 'Wedding date moved']))));

            if ($item['outcome'] === 'cancelled') {
                $hr = $this->people[$this->hrManagerKey()]['user'];
                $this->at($cancelAt, $hr, fn () => $this->recorder->transaction(fn () => app(LeaveCancellationService::class)->cancel($leave->fresh(), $hr, 'Cancelled at the employee\'s request; days returned to the balance.')));
            }
        }
    }

    /** One decision on the leave's current approval step, by its demo approver; false when none can decide. */
    private function decideLeave(Leave $leave, Carbon $when, bool $approve, ?string $reason = null): bool
    {
        $leave = $leave->fresh();
        $approval = $leave?->approvalRequest;

        if (! $approval || $approval->status !== 'pending') {
            return false;
        }

        $actor = $this->approverFor($approval, (int) $leave->employee?->user_id);

        if (! $actor) {
            return false;
        }

        $this->at($when, $actor, fn () => $this->recorder->transaction(fn () => $approve
            ? app(LeaveApprovalService::class)->approve($leave, $actor)
            : app(LeaveApprovalService::class)->reject($leave, $actor, (string) $reason)));

        return true;
    }

    /**
     * @param  array<int, int>  $exceptIds
     * @return array<string, bool> dates (Y-m-d) already covered by a live leave of the employee
     */
    private function occupiedDates(Employee $employee, array $exceptIds = []): array
    {
        $dates = [];

        Leave::query()
            ->where('employee_id', $employee->id)
            ->whereNotIn('id', $exceptIds)
            ->whereNotIn('state', [State::REFUSE->value, State::CANCEL->value])
            ->get(['request_date_from', 'request_date_to', 'date_from', 'date_to'])
            ->each(function (Leave $leave) use (&$dates): void {
                $from = Carbon::parse($leave->request_date_from ?? $leave->date_from);
                $to = Carbon::parse($leave->request_date_to ?? $leave->date_to ?? $from);
                for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day->addDay()) {
                    $dates[$day->toDateString()] = true;
                }
            });

        return $dates;
    }

    // ---------------------------------------------------------------------
    // Attendance
    // ---------------------------------------------------------------------

    /**
     * The base seeder's two weeks of 09:00-17:30 attendance ("Demo data")
     * predate the 10:00-18:00 calendar: they are replaced by this seeder's.
     * Days this seeder wrote that were still open (checked in, not yet out)
     * are written again as full days.
     */
    private function removeLegacyAttendance(): void
    {
        $employeeIds = collect($this->people)->map(fn (array $person): int => (int) $person['employee']->id)->values()->all();

        $legacy = AttendanceRecord::query()
            ->where('company_id', $this->company->id)
            ->whereIn('employee_id', $employeeIds)
            ->where('notes', 'Demo data')
            ->where('source', 'import')
            ->pluck('id')
            ->all();

        $tracked = DemoRecord::query()->where('record_table', 'employees_attendance_records')->where('company_id', $this->company->id);
        $forgotten = DemoRecord::query()->where('company_id', $this->company->id)->where('demo_key', 'like', 'attendance-forgotten:%')->pluck('record_id');
        $reopen = AttendanceRecord::query()
            ->whereIn('id', (clone $tracked)->whereNull('demo_key')->pluck('record_id'))
            ->whereNotIn('id', $forgotten)
            ->whereNull('check_out')
            ->whereDate('attendance_date', '<', $this->today->toDateString())
            ->pluck('id')
            ->all();

        $ids = array_values(array_unique([...$legacy, ...$reopen]));

        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids): void {
            $intervalIds = AttendanceInterval::query()->whereIn('attendance_record_id', $ids)->pluck('id')->all();
            AttendanceInterval::query()->whereIn('id', $intervalIds)->delete();
            AttendanceRecord::query()->whereIn('id', $ids)->delete();
            DemoRecord::query()->where('record_table', 'employees_attendance_intervals')->whereIn('record_id', array_map('strval', $intervalIds))->delete();
            DemoRecord::query()->where('record_table', 'employees_attendance_records')->whereIn('record_id', array_map('strval', $ids))->delete();
        });

        if ($legacy !== []) {
            $this->command?->info("{$this->company->name}: replaced ".count($legacy).' base-seeder attendance rows (09:00-17:30) with 10:00-18:00 ones.');
        }
    }

    private function seedAttendance(): void
    {
        $nowLocal = $this->realNow->copy()->setTimezone(self::TIMEZONE);
        $yesterday = $this->previousWeekday($this->today);
        $employeeIds = collect($this->people)->map(fn (array $person): int => (int) $person['employee']->id)->values()->all();

        $leaves = Leave::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('state', State::VALIDATE_TWO->value)
            ->whereRaw('DATE(COALESCE(request_date_to, date_to)) >= ?', [$this->windowStart->toDateString()])
            ->get()
            ->groupBy('employee_id');

        $index = 0;
        foreach ($this->people as $key => $person) {
            $employee = $person['employee'];
            $profile = $this->attendanceProfile($key, $person['spec']);
            $joined = $this->localDate($employee->joining_date ?? $this->windowStart);
            $from = $joined->max($this->windowStart);
            $existing = AttendanceRecord::query()->where('employee_id', $employee->id)
                ->whereDate('attendance_date', '>=', $from->toDateString())
                ->pluck('attendance_date')
                ->map(fn ($date): string => Carbon::parse($date)->toDateString())
                ->flip()
                ->all();
            $forgets = $index % 9 === 4;
            $days = [];

            for ($day = $from->copy(); $day->lessThanOrEqualTo($this->today); $day->addDay()) {
                $date = $day->toDateString();

                if ($day->isWeekend() || isset($existing[$date])) {
                    continue;
                }

                $dayLeaves = ($leaves->get($employee->id) ?? collect())->filter(fn (Leave $leave): bool => Carbon::parse($leave->request_date_from)->toDateString() <= $date
                    && Carbon::parse($leave->request_date_to ?? $leave->request_date_from)->toDateString() >= $date);

                if ($dayLeaves->contains(fn (Leave $leave): bool => ! $leave->request_unit_half && ! $leave->request_unit_hours)) {
                    continue;
                }

                $plan = $this->dayPlan(
                    $day->copy(),
                    $profile,
                    $this->rng("attendance:{$this->company->id}:{$key}:{$date}"),
                    $dayLeaves->first(fn (Leave $leave): bool => (bool) $leave->request_unit_half),
                    $dayLeaves->first(fn (Leave $leave): bool => (bool) $leave->request_unit_hours),
                    $day->isSameDay($this->today) ? $nowLocal : null,
                    $forgets && $day->isSameDay($yesterday),
                );

                if ($plan) {
                    $days[$date] = $plan + ['forgotten' => $forgets && $day->isSameDay($yesterday)];
                }
            }

            $this->insertAttendance($employee, $days);
            $index++;
        }
    }

    /**
     * @param  array<string, array{intervals: array<int, array{0: Carbon, 1: ?Carbon}>, status: string, source: string, notes: ?string, overtime: float}>  $days
     */
    private function insertAttendance(Employee $employee, array $days): void
    {
        if ($days === []) {
            return;
        }

        $utc = fn (?Carbon $moment): ?string => $moment?->copy()->utc()->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($days as $date => $plan) {
            $intervals = $plan['intervals'];
            $first = $intervals[0][0];
            $last = end($intervals);
            $open = $last[1] === null;
            $workedMinutes = array_sum(array_map(fn (array $pair): int => $pair[1] ? (int) $pair[0]->diffInMinutes($pair[1]) : 0, $intervals));
            $scheduledStart = $this->local($date, '10:00');
            $scheduledEnd = $this->local($date, '18:00');

            $rows[] = [
                'company_id'              => $employee->company_id,
                'employee_id'             => $employee->id,
                'attendance_date'         => $date,
                'scheduled_start'         => $utc($scheduledStart),
                'scheduled_end'           => $utc($scheduledEnd),
                'check_in'                => $utc($first),
                'check_out'               => $open ? null : $utc($last[1]),
                'worked_hours'            => round($workedMinutes / 60, 4),
                'overtime_hours'          => $plan['overtime'],
                'late_minutes'            => max(0, (int) $scheduledStart->diffInMinutes($first, false)),
                'early_departure_minutes' => $open ? 0 : max(0, (int) $last[1]->diffInMinutes($scheduledEnd, false)),
                'status'                  => $plan['status'],
                'source'                  => $plan['source'],
                'verification_status'     => 'verified',
                'notes'                   => $plan['notes'],
                'created_at'              => $utc($first),
                'updated_at'              => $utc($open ? $last[0] : $last[1]),
            ];
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            AttendanceRecord::query()->insert($chunk);
        }

        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereIn('attendance_date', array_keys($days))
            ->get(['id', 'attendance_date', 'source']);
        $this->recorder->track('employees_attendance_records', $records->pluck('id')->all());

        // A forgotten check-out is kept as it is on later runs (an unfinished "today" is redone).
        foreach ($records as $record) {
            if ($days[Carbon::parse($record->attendance_date)->toDateString()]['forgotten'] ?? false) {
                $this->recorder->mark('attendance-forgotten:'.$record->id, $record);
            }
        }

        $intervalRows = [];
        foreach ($records as $record) {
            $date = Carbon::parse($record->attendance_date)->toDateString();

            foreach ($days[$date]['intervals'] as [$in, $out]) {
                $intervalRows[] = [
                    'company_id'           => $employee->company_id,
                    'employee_id'          => $employee->id,
                    'attendance_record_id' => $record->id,
                    'check_in'             => $utc($in),
                    'check_out'            => $utc($out),
                    'source'               => $record->source,
                    'verification_status'  => 'verified',
                    'created_at'           => $utc($in),
                    'updated_at'           => $utc($out ?? $in),
                ];
            }
        }

        foreach (array_chunk($intervalRows, 300) as $chunk) {
            AttendanceInterval::query()->insert($chunk);
        }

        $this->recorder->track('employees_attendance_intervals', AttendanceInterval::query()->whereIn('attendance_record_id', $records->pluck('id'))->pluck('id')->all());
    }

    /** @return array{late: float, absent: float, split: float, overtime: float, early: float} */
    private function attendanceProfile(string $key, array $spec): array
    {
        $r = $this->rng("attendance-profile:{$this->company->id}:{$key}");
        $field = in_array($spec['dept'] ?? '', ['Fleet', 'Dispatch', 'Warehouse', 'Operations'], true);
        $punctuality = $r->getInt(1, 10);

        return [
            'late'     => $punctuality <= 6 ? 0.05 : ($punctuality <= 9 ? 0.16 : 0.33),
            'absent'   => $r->getInt(0, 3) / 100,
            'split'    => $field || ($spec['dept'] ?? '') === 'Sales' ? 0.13 : 0.06,
            'overtime' => $field ? 0.16 : 0.05,
            'early'    => 0.04,
        ];
    }

    /**
     * One working day: punches in minutes from midnight (Karachi), shaped by
     * the person's habits, an approved half day or short leave, and (today)
     * the current time.
     *
     * @param  array{late: float, absent: float, split: float, overtime: float, early: float}  $profile
     * @return array{intervals: array<int, array{0: Carbon, 1: ?Carbon}>, status: string, source: string, notes: ?string, overtime: float}|null
     */
    private function dayPlan(Carbon $day, array $profile, Randomizer $r, ?Leave $half, ?Leave $short, ?Carbon $now, bool $forgetCheckOut): ?array
    {
        if (! $half && ! $short && $this->chance($r, $profile['absent'])) {
            return null;
        }

        $arrive = $this->chance($r, $profile['late']) ? self::OFFICE_START + $r->getInt(5, 40) : self::OFFICE_START - $r->getInt(0, 18);
        $roll = $r->getFloat(0, 1);
        $leave = match (true) {
            $roll < $profile['overtime']                     => self::OFFICE_END + $r->getInt(60, 170),
            $roll < $profile['overtime'] + $profile['early'] => self::OFFICE_END - $r->getInt(15, 50),
            default                                          => self::OFFICE_END + $r->getInt(0, 35),
        };
        $pairs = [[$arrive, $leave]];

        if ($half) {
            $morningOff = ($half->request_date_from_period?->value ?? $half->request_date_from_period) === 'morning';
            $pairs = $morningOff
                ? [[14 * 60 + $r->getInt(-12, 12), self::OFFICE_END + $r->getInt(0, 25)]]
                : [[$arrive, 14 * 60 + $r->getInt(-5, 15)]];
        } elseif ($short) {
            $from = (int) round((float) $short->request_hour_from * 60);
            $to = (int) round((float) $short->request_hour_to * 60);
            $pairs = match (true) {
                $from <= self::OFFICE_START => [[$to + $r->getInt(0, 12), $leave]],
                $to >= self::OFFICE_END     => [[$arrive, $from - $r->getInt(0, 6)]],
                default                     => [[$arrive, $from - $r->getInt(0, 5)], [$to + $r->getInt(0, 10), $leave]],
            };
        } elseif ($this->chance($r, $profile['split'])) {
            $out = $r->getInt(12 * 60 + 30, 15 * 60);
            $back = $out + $r->getInt(30, 80);
            if ($back < $leave - 60) {
                $pairs = [[$arrive, $out], [$back, $leave]];
            }
        }

        $manual = $this->chance($r, 0.05);
        $closedEnd = end($pairs)[1];

        if ($now) {
            $minute = $now->hour * 60 + $now->minute;
            $kept = [];
            foreach ($pairs as [$in, $out]) {
                if ($in >= $minute - 1) {
                    break;
                }
                $kept[] = [$in, $out < $minute ? $out : null];
                if ($out >= $minute) {
                    break;
                }
            }
            $pairs = $kept;
        } elseif ($forgetCheckOut) {
            $pairs[array_key_last($pairs)][1] = null;
        }

        if ($pairs === []) {
            return null;
        }

        $intervals = array_map(fn (array $pair): array => [
            $day->copy()->startOfDay()->addMinutes($pair[0]),
            $pair[1] === null ? null : $day->copy()->startOfDay()->addMinutes($pair[1]),
        ], $pairs);

        $open = end($pairs)[1] === null;
        $worked = array_sum(array_map(fn (array $pair): int => $pair[1] === null ? 0 : $pair[1] - $pair[0], $pairs));
        // As GeofencedAttendanceService sets it on check-out.
        $status = match (true) {
            $half !== null                                                                     => 'half_day',
            $open                                                                              => 'present',
            $worked >= 60 * (float) config('hr_attendance_geofence.full_day_min_hours', 7.5)   => 'present',
            $worked >= 60 * (float) config('hr_attendance_geofence.half_day_min_hours', 4.0)   => 'half_day',
            default                                                                            => 'present',
        };

        return [
            'intervals' => $intervals,
            'status'    => $status,
            'source'    => $manual ? 'manual' : 'biometric',
            'notes'     => $manual ? 'Biometric device was offline; entered by HR from the attendance register.' : null,
            'overtime'  => ! $open && $closedEnd >= self::OFFICE_END + 60 ? round(($closedEnd - self::OFFICE_END) / 60, 2) : 0.0,
        ];
    }

    // ---------------------------------------------------------------------
    // Documents
    // ---------------------------------------------------------------------

    private function seedDocuments(): void
    {
        $types = DocumentType::query()->where('company_id', $this->company->id)->where('is_active', true)->get()->keyBy('code');
        $hr = $this->people[$this->hrManagerKey()]['user'];
        $prefix = Str::before((string) $this->people[$this->hrManagerKey()]['employee']->employee_number, '-') ?: 'HR';
        $index = 0;

        foreach ($this->people as $key => $person) {
            foreach ($this->documentPlan($key, $person, $index, $prefix) as $code => $doc) {
                $type = $types->get($code);
                $demoKey = "document:{$this->company->id}:{$key}:{$code}";

                if (! $type || $this->recorder->has($demoKey)
                    || EmployeeDocument::query()->where('employee_id', $person['employee']->id)->where('document_type_id', $type->id)->exists()) {
                    continue;
                }

                $uploader = $doc['self'] ? $person['user'] : $hr;
                $incoming = EmployeeDocument::INCOMING_DIRECTORY.'/'.Str::uuid().'.pdf';
                Storage::disk(EmployeeDocument::DISK)->put($incoming, DemoPdf::make($doc['title'], $doc['lines']));

                try {
                    $document = $this->at($doc['uploaded'], $uploader, fn (): EmployeeDocument => $this->recorder->transaction(fn (): EmployeeDocument => app(EmployeeDocumentService::class)->upload($person['employee'], $uploader, [
                        'document_type_id' => $type->id,
                        'title'            => $doc['title'],
                        'number'           => $doc['number'],
                        'issued_on'        => $doc['issued'],
                        'expires_on'       => $doc['expires'],
                        'notes'            => $doc['notes'] ?? null,
                        'original_name'    => Str::slug($doc['title']).'.pdf',
                    ], $incoming)));

                    $this->recorder->trackFile(EmployeeDocument::DISK, $document->file_path);
                    $this->recorder->mark($demoKey, $document);
                } catch (Throwable $e) {
                    Storage::disk(EmployeeDocument::DISK)->delete($incoming);
                    $this->warn("{$this->company->name}: document {$demoKey} skipped: ".Str::limit($e->getMessage(), 180));
                }
            }

            $index++;
        }
    }

    /**
     * @param  array{employee: Employee, user: User, spec: array<string, mixed>, existing: bool}  $person
     * @return array<string, array<string, mixed>>
     */
    private function documentPlan(string $key, array $person, int $index, string $prefix): array
    {
        $employee = $person['employee'];
        $r = $this->rng("documents:{$this->company->id}:{$key}");
        $joined = $this->localDate($employee->joining_date ?? '2024-01-01');
        $year = $joined->year;
        $number = sprintf('%03d', $index + 11);
        $lower = $joined->copy()->addDays(2)->max($this->today->copy()->subDays(200));
        $upper = $this->today->copy()->subDays(3)->max($lower->copy()->addDay());
        $uploaded = fn (): Carbon => $this->workMoment($this->randomWeekday($r, $lower, $upper) ?? $lower, $r);
        $docs = [];

        if ($index % 7 !== 5) {
            $expires = match ($index) {
                2       => $this->today->copy()->addDays(9),
                8       => $this->today->copy()->addDays(23),
                13      => $this->today->copy()->subDays(12),
                20      => $this->today->copy()->subDays(41),
                default => Carbon::create($r->getInt(2027, 2035), $r->getInt(1, 12), $r->getInt(1, 28)),
            };
            $docs['cnic'] = [
                'title'    => 'CNIC - '.$employee->name,
                'number'   => $employee->cnic,
                'issued'   => $expires->copy()->subYears(10)->toDateString(),
                'expires'  => $expires->toDateString(),
                'self'     => false,
                'uploaded' => $uploaded(),
                'lines'    => ['Computerized National Identity Card (copy)', 'Name: '.$employee->name, 'Identity number: '.$employee->cnic, 'Date of expiry: '.$expires->format('d.m.Y'), 'Placeholder scan for the demo.'],
            ];
        }

        if ($index % 9 !== 7) {
            $docs['employment_contract'] = [
                'title'    => 'Employment contract - '.$employee->name,
                'number'   => "{$prefix}/HR/CON/{$year}/{$number}",
                'issued'   => $joined->toDateString(),
                'expires'  => null,
                'self'     => false,
                'uploaded' => $uploaded(),
                'lines'    => [$this->company->name.' - Contract of employment', 'Employee: '.$employee->name, 'Designation: '.$employee->job_title, 'Date of joining: '.$joined->format('d M Y'), 'Probation: '.($employee->probation_end_date ? 'until '.Carbon::parse($employee->probation_end_date)->format('d M Y') : 'n/a'), 'Placeholder document for the demo.'],
            ];
        }

        if ($index % 6 !== 4) {
            $docs['offer_letter'] = [
                'title'    => 'Offer letter - '.$employee->name,
                'number'   => "{$prefix}/HR/OL/{$year}/{$number}",
                'issued'   => $joined->copy()->subDays(14)->toDateString(),
                'expires'  => null,
                'self'     => false,
                'uploaded' => $uploaded(),
                'lines'    => [$this->company->name.' - Offer of employment', 'Dear '.$employee->name.',', 'We are pleased to offer you the position of '.$employee->job_title.'.', 'Gross monthly salary: PKR '.number_format((float) $employee->base_salary), 'Placeholder document for the demo.'],
            ];
        }

        if (in_array($person['spec']['dept'] ?? '', ['Fleet', 'Dispatch', 'Warehouse'], true)) {
            $expires = $index % 5 === 1 ? $this->today->copy()->addDays(15) : Carbon::create($r->getInt(2027, 2031), $r->getInt(1, 12), $r->getInt(1, 28));
            $docs['driving_licence'] = [
                'title'    => 'Driving licence - '.$employee->name,
                'number'   => 'LTV-'.$r->getInt(100000, 999999),
                'issued'   => $expires->copy()->subYears(5)->toDateString(),
                'expires'  => $expires->toDateString(),
                'self'     => false,
                'uploaded' => $uploaded(),
                'lines'    => ['Driving licence (LTV) - Sindh', 'Name: '.$employee->name, 'Valid until: '.$expires->format('d M Y'), 'Placeholder scan for the demo.'],
            ];
        }

        if ($index % 3 === 0) {
            $self = $index % 6 === 0 && ! $person['existing'];
            $docs['educational_certificate'] = [
                'title'    => ($employee->study_field ?: 'Degree').' - '.$employee->name,
                'number'   => null,
                'issued'   => Carbon::parse((string) ($person['spec']['born'] ?? '1995-01-01'))->addYears(22)->setMonth(8)->setDay(15)->toDateString(),
                'expires'  => null,
                'self'     => $self,
                'uploaded' => $self ? $this->workMoment($this->today->copy()->subDays($r->getInt(1, 6)), $r) : $uploaded(),
                'notes'    => $self ? 'Uploaded by the employee; waiting for HR to verify.' : null,
                'lines'    => ['Degree certificate (copy)', 'Awarded to: '.$employee->name, ($employee->study_field ?: 'Degree').', '.($employee->study_school ?: 'University'), 'Placeholder scan for the demo.'],
            ];
        }

        return $docs;
    }

    private function sendDocumentReminders(): void
    {
        try {
            $result = app(DocumentExpiryReminderService::class)->send($this->company->id);
            $this->recorder->flush();
            $this->reminders = (int) $result['documents'];
        } catch (Throwable $e) {
            $this->warn("{$this->company->name}: document reminders skipped: ".Str::limit($e->getMessage(), 180));
        }
    }

    // ---------------------------------------------------------------------
    // Employee requests (loan, salary advance, HR letter)
    // ---------------------------------------------------------------------

    private function seedEmployeeRequests(): void
    {
        $types = EmployeeRequestType::query()->where('company_id', $this->company->id)->where('is_active', true)->get()->keyBy('code');
        // Staff first (longest-serving first), then their managers; never the GM or the HR manager.
        $staff = collect($this->people)
            ->reject(fn (array $person, string $key): bool => $key === $this->hrManagerKey() || ($person['spec']['parent'] ?? null) === null)
            ->sortBy(fn (array $person, string $key): string => ($this->isManager($key) ? '1' : '0').Carbon::parse($person['employee']->joining_date)->toDateString())
            ->keys()
            ->values();

        foreach ($this->requestPlan() as $slot => $item) {
            $key = $staff->get($item['who'] % max(1, $staff->count()));
            $type = $types->get($item['code']);
            $demoKey = "request:{$this->company->id}:{$slot}";

            if (! $key || ! $type || $this->recorder->has($demoKey)) {
                continue;
            }

            if ($type->is_financial) {
                $this->warn("{$this->company->name}: request type {$type->code} posts to accounting; skipped.");

                continue;
            }

            try {
                $this->runRequest($this->people[$key], $type, $item, $demoKey);
            } catch (Throwable $e) {
                $this->warn("{$this->company->name}: request {$demoKey} skipped: ".Str::limit($e->getMessage(), 180));
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function requestPlan(): array
    {
        $loan = EmployeeRequestKinds::LoanCode;
        $advance = EmployeeRequestKinds::SalaryAdvanceCode;
        $letter = EmployeeRequestKinds::HrLetterCode;

        return [
            'loan-approved'       => ['code' => $loan, 'who' => 0, 'amount' => 150000, 'instalments' => 10, 'days_ago' => 24, 'approvals' => 9, 'text' => "Sister's wedding expenses"],
            'loan-pending-lm'     => ['code' => $loan, 'who' => 1, 'amount' => 80000, 'instalments' => 6, 'days_ago' => 2, 'approvals' => 0, 'text' => 'Motorcycle for the daily commute to the port'],
            'loan-pending-hr'     => ['code' => $loan, 'who' => 2, 'amount' => 250000, 'instalments' => 12, 'days_ago' => 5, 'approvals' => 1, 'text' => 'Home repairs after monsoon rain damage'],
            'loan-pending-fin'    => ['code' => $loan, 'who' => 3, 'amount' => 300000, 'instalments' => 12, 'days_ago' => 8, 'approvals' => 2, 'text' => "Children's school admission and annual fees"],
            'loan-rejected'       => ['code' => $loan, 'who' => 4, 'amount' => 500000, 'instalments' => 24, 'days_ago' => 15, 'approvals' => 1, 'reject' => "Above the loan policy limit of three months' salary; please apply for a smaller amount.", 'text' => 'Plot booking instalment'],
            'advance-approved'    => ['code' => $advance, 'who' => 5, 'percent' => 40, 'days_ago' => 12, 'approvals' => 9, 'text' => 'Rent advance for a new house'],
            'advance-pending-lm'  => ['code' => $advance, 'who' => 6, 'percent' => 30, 'days_ago' => 1, 'approvals' => 0, 'text' => "My father's hospital bill"],
            'advance-rejected'    => ['code' => $advance, 'who' => 7, 'percent' => 50, 'days_ago' => 9, 'approvals' => 0, 'reject' => 'You already took an advance this quarter; please apply again in January.', 'text' => 'Wedding in the family'],
            'advance-pending-hr'  => ['code' => $advance, 'who' => 8, 'percent' => 25, 'days_ago' => 3, 'approvals' => 1, 'text' => 'Utility bills and school fees'],
            'letter-salary'       => ['code' => $letter, 'who' => 9, 'letter' => EmployeeRequestKinds::SalaryCertificate, 'days_ago' => 14, 'approvals' => 9, 'issue' => true, 'addressed_to' => 'The Branch Manager, Meezan Bank Ltd, Clifton Branch, Karachi', 'text' => 'Car financing application'],
            'letter-visa'         => ['code' => $letter, 'who' => 10, 'letter' => 'employment_verification', 'days_ago' => 2, 'approvals' => 0, 'addressed_to' => 'Visa Section, Embassy of the United Arab Emirates, Islamabad', 'text' => 'Family visit visa for Dubai'],
            'letter-noc'          => ['code' => $letter, 'who' => 11, 'letter' => 'noc', 'days_ago' => 20, 'approvals' => 9, 'issue' => true, 'addressed_to' => 'Admissions Office, Institute of Business Administration, Karachi', 'text' => 'Evening MBA admission'],
            'letter-bank'         => ['code' => $letter, 'who' => 12, 'letter' => 'bank_letter', 'days_ago' => 6, 'approvals' => 0, 'reject' => 'Salary accounts are opened through our payroll bank; please use the payroll account form instead.', 'addressed_to' => 'HBL, Shahrah-e-Faisal Branch, Karachi', 'text' => 'Opening a salary account'],
            'letter-employment'   => ['code' => $letter, 'who' => 13, 'letter' => 'employment_verification', 'days_ago' => 4, 'approvals' => 9, 'addressed_to' => 'To whom it may concern', 'text' => 'House rent agreement'],
        ];
    }

    /**
     * @param  array{employee: Employee, user: User, spec: array<string, mixed>, existing: bool}  $person
     * @param  array<string, mixed>  $item
     */
    private function runRequest(array $person, EmployeeRequestType $type, array $item, string $demoKey): void
    {
        $employee = $person['employee'];
        $user = $person['user'];
        $r = $this->rng($demoKey);
        $submit = $this->workMoment($this->today->copy()->subDays($item['days_ago']), $r);
        $month = fn (Carbon $moment, int $ahead): string => $moment->copy()->startOfMonth()->addMonthsNoOverflow($ahead)->format('Y-m');

        [$amount, $payload] = match ($type->category) {
            EmployeeRequestKinds::LoanCategory => [$item['amount'], [
                'purpose'     => $item['text'],
                'instalments' => $item['instalments'],
                'start_month' => $month($submit, 1),
            ]],
            EmployeeRequestKinds::SalaryAdvanceCategory => [floor((float) $employee->base_salary * $item['percent'] / 100 / 1000) * 1000, [
                'reason'       => $item['text'],
                'deduct_month' => $month($submit, $submit->day > 15 ? 1 : 0),
            ]],
            default => [null, [
                'letter_type'    => $item['letter'],
                'addressed_to'   => $item['addressed_to'],
                'letter_purpose' => $item['text'],
                'needed_by'      => $submit->copy()->addDays($r->getInt(7, 14))->toDateString(),
            ]],
        };

        $request = $this->at($submit, $user, fn (): EmployeeRequest => $this->recorder->transaction(function () use ($employee, $user, $type, $amount, $payload, $item): EmployeeRequest {
            $request = EmployeeRequest::query()->create([
                'company_id'      => $employee->company_id,
                'employee_id'     => $employee->id,
                'request_type_id' => $type->id,
                'requested_by'    => $user->id,
                'description'     => $item['text'],
                'amount'          => $amount,
                'currency_id'     => $this->company->currency_id,
                'payload'         => $payload,
                'status'          => 'draft',
            ]);

            app(EmployeeRequestService::class)->submit($request, $user);

            return $request;
        }));

        $this->recorder->mark($demoKey, $request);

        $moments = [$submit];
        for ($step = 1; $step <= 5; $step++) {
            $moments[] = $this->workMoment($moments[$step - 1]->copy()->addHours($r->getInt(3, 26)), $r)->max($moments[$step - 1]->copy()->addMinutes(30));
        }
        $moments = array_values($this->fitBeforeNow($moments));
        $next = 1;

        for ($step = 0; $step < $item['approvals']; $step++) {
            if (! $this->decideRequest($request, $moments[$next++], true)) {
                break;
            }
        }

        if (isset($item['reject'])) {
            $this->decideRequest($request, $moments[$next++], false, $item['reject']);
        }

        if (($item['issue'] ?? false) && $request->fresh()->status === 'approved') {
            $hr = $this->people[$this->hrManagerKey()]['user'];
            $issued = $this->at($moments[min($next, 5)], $hr, fn (): EmployeeRequest => $this->recorder->transaction(
                fn (): EmployeeRequest => app(EmployeeRequestService::class)->generateLetterPdf($request->fresh(), $hr, $item['letter'] === EmployeeRequestKinds::SalaryCertificate),
            ));

            if ($path = data_get($issued->payload, 'letter_path')) {
                $this->recorder->trackFile('local', (string) $path);
            }
        }
    }

    private function decideRequest(EmployeeRequest $request, Carbon $when, bool $approve, ?string $reason = null): bool
    {
        $request = $request->fresh();
        $approval = $request?->approvalRequest;

        if (! $approval || $approval->status !== 'pending') {
            return false;
        }

        $actor = $this->approverFor($approval, (int) $request->employee?->user_id);

        if (! $actor) {
            return false;
        }

        $this->at($when, $actor, fn () => $this->recorder->transaction(fn () => $approve
            ? app(EmployeeRequestService::class)->approve($request, $actor)
            : app(EmployeeRequestService::class)->reject($request, $actor, (string) $reason)));

        return true;
    }

    /**
     * The demo user who decides the request's current step: its resolved
     * approver (line manager, HR, the finance role...), never the person the
     * request is about and never a real user. HR managers come first.
     */
    private function approverFor(ApprovalRequest $approval, ?int $subjectUserId): ?User
    {
        $engine = app(ApprovalEngine::class);
        $approval = $approval->fresh();

        return $engine->resolveAuthorizedApprovers($approval)
            ->filter(fn (User $user): bool => DemoSandbox::isDemoEmail($user->email) && (int) $user->id !== (int) $subjectUserId)
            ->sortBy(fn (User $user): int => ($user->hasRole('hr_manager') ? 0 : 1_000_000) + (int) $user->id)
            ->first(fn (User $user): bool => $engine->canAct($approval->fresh(), $user));
    }

    // ---------------------------------------------------------------------
    // Recruitment
    // ---------------------------------------------------------------------

    private function seedRecruitment(): void
    {
        $hr = $this->people[$this->hrManagerKey()]['user'];
        $jobs = [];

        foreach ($this->setup['jobs'] as $spec) {
            try {
                $jobs[$spec['key']] = $this->runJob($spec, $hr);
            } catch (Throwable $e) {
                $this->warn("{$this->company->name}: job {$spec['key']} skipped: ".Str::limit($e->getMessage(), 180));
            }
        }

        $this->upcomingSlots = $this->interviewSlots();

        foreach ($this->setup['candidates'] as $spec) {
            $job = $jobs[$spec['job']] ?? null;

            if (! $job) {
                continue;
            }

            try {
                $this->runCandidate($spec, $job);
            } catch (Throwable $e) {
                $this->warn("{$this->company->name}: candidate {$spec['name']} skipped: ".Str::limit($e->getMessage(), 180));
            }
        }
    }

    /** @param  array<string, mixed>  $spec */
    private function runJob(array $spec, User $hr): JobPosition
    {
        $demoKey = "job:{$this->company->id}:{$spec['key']}";
        $existing = $this->recorder->find($demoKey);

        if ($existing && ($job = JobPosition::query()->find($existing->record_id))) {
            return $job;
        }

        $r = $this->rng($demoKey);
        $published = $this->workMoment($this->today->copy()->subDays($spec['published_days_ago']), $r);
        $created = $this->workMoment($published->copy()->subDays(3), $r);
        $department = Department::query()->where('company_id', $this->company->id)->where('name', $spec['dept'])->first();
        $manager = $this->people[$spec['manager']]['employee'] ?? null;
        $recruiter = $this->people[$spec['recruiter']]['user'] ?? $hr;
        $employmentType = DB::table('employees_employment_types')->where('name', 'Full-Time')->value('id');

        $job = $this->at($created, $hr, fn (): JobPosition => $this->recorder->transaction(fn (): JobPosition => JobPosition::query()->create([
            'company_id'           => $this->company->id,
            'name'                 => $spec['name'],
            'public_title'         => $spec['public_title'],
            'department_id'        => $department?->id,
            'employment_type_id'   => $employmentType,
            'recruiter_id'         => $recruiter->id,
            'manager_id'           => $manager?->id,
            'no_of_recruitment'    => $spec['positions'],
            'location_type'        => $spec['location'],
            'address_id'           => $this->company->partner_id,
            'salary_min'           => $spec['salary'][0],
            'salary_max'           => $spec['salary'][1],
            'salary_currency_id'   => $this->company->currency_id,
            'salary_period'        => 'MONTH',
            'salary_visible'       => $spec['salary_visible'],
            'min_experience_years' => $spec['experience'],
            'screening_keywords'   => $spec['keywords'],
            'valid_through'        => $published->copy()->addDays(45)->toDateString(),
            'description'          => '<p>'.e($spec['summary']).'</p><h3>What you will do</h3><ul>'.collect($spec['duties'])->map(fn (string $line): string => '<li>'.e($line).'</li>')->implode('').'</ul><p>Location: '.e($this->setup['office']).'.</p>',
            'requirements'         => '<ul>'.collect($spec['needs'])->map(fn (string $line): string => '<li>'.e($line).'</li>')->implode('').'</ul>',
            'is_active'            => true,
            'posting_status'       => 'draft',
            'creator_id'           => $hr->id,
        ])));

        $this->recorder->mark($demoKey, $job);

        if ($spec['status'] === 'draft') {
            return $job;
        }

        $submitted = $created->copy()->addHours(2);
        $approval = $this->at($submitted, $hr, fn (): ApprovalRequest => $this->recorder->transaction(fn (): ApprovalRequest => app(JobOpeningPublicationService::class)->submitForApproval($job, $hr)));

        if ($spec['status'] !== 'published') {
            return $job->refresh();
        }

        $approver = $this->approverFor($approval, $hr->id);
        if (! $approver) {
            return $job->refresh();
        }

        $this->at($this->workMoment($submitted->copy()->addHours(20), $r), $approver, fn () => $this->recorder->transaction(fn () => app(ApprovalEngine::class)->approve($approval->fresh(), $approver)));
        $this->at($published, $hr, fn () => $this->recorder->transaction(fn () => app(JobOpeningPublicationService::class)->publish($job->refresh(), $hr)));

        return $job->refresh();
    }

    /** @param  array<string, mixed>  $spec */
    private function runCandidate(array $spec, JobPosition $job): void
    {
        $demoKey = "applicant:{$this->company->id}:".Str::slug($spec['name']);

        if ($this->recorder->has($demoKey)) {
            return;
        }

        $r = $this->rng($demoKey);
        $recruiter = User::query()->find($job->recruiter_id) ?? $this->people[$this->hrManagerKey()]['user'];
        $hr = $this->people[$this->hrManagerKey()]['user'];
        $hiringManager = $job->manager_id ? Employee::query()->find($job->manager_id)?->user : null;
        $applied = $this->workMoment($this->today->copy()->subDays($spec['applied_days_ago']), $r);
        $email = Str::slug($spec['name'], '.').'@example.com';
        $phone = $this->mobile($r);
        $cvPath = 'recruitment/cv/demo-'.$this->company->id.'-'.Str::slug($spec['name']).'.pdf';

        Storage::disk('local')->put($cvPath, DemoPdf::make($spec['name'].' - Curriculum Vitae', $this->cvLines($spec, $job, $email, $phone, $r)));
        $this->recorder->trackFile('local', $cvPath);

        $applicant = $this->at($applied, $recruiter, function () use ($spec, $job, $recruiter, $email, $phone, $cvPath, $applied): Applicant {
            return $this->recorder->transaction(function () use ($spec, $job, $recruiter, $email, $phone, $cvPath, $applied): Applicant {
                $candidate = Candidate::query()->create([
                    'company_id'        => $this->company->id,
                    'name'              => $spec['name'],
                    'email_from'        => $email,
                    'phone'             => $phone,
                    'degree_id'         => DB::table('recruitments_degrees')->where('name', 'Bachelor')->value('id'),
                    'availability_date' => $applied->copy()->addDays(30)->toDateString(),
                    'resume_path'       => $cvPath,
                    'is_active'         => true,
                    'creator_id'        => $recruiter->id,
                ]);

                $applicant = app(CandidateApplicationService::class)->applyToJob($candidate, $job, $recruiter)['applicant'];
                $applicant->update([
                    'source_id'       => UTMSource::query()->where('name', $spec['source'])->value('id'),
                    'priority'        => $spec['priority'],
                    'salary_expected' => $spec['expected'],
                ]);
                app(CvAnalyser::class)->analyse($applicant);

                return $applicant;
            });
        });

        $this->recorder->mark($demoKey, $applicant);

        $stages = Stage::query()->pluck('id', 'name');
        $moveTo = function (string $stage, Carbon $when) use ($applicant, $recruiter, $stages): void {
            if ($stageId = $stages->get($stage)) {
                $this->at($when, $recruiter, fn () => $this->recorder->transaction(fn () => $applicant->refresh()->update(['stage_id' => $stageId])));
            }
        };
        $interviewers = collect([$recruiter, $hiringManager])->filter()->unique('id')->values();

        switch ($spec['flow']) {
            case 'qualified':
                $moveTo('Initial Qualification', $this->workMoment($applied->copy()->addDays(2), $r));
                break;

            case 'phone_screen_today':
                $moveTo('Initial Qualification', $this->workMoment($applied->copy()->addDays(1), $r));
                $this->scheduleInterview($applicant, $recruiter, collect([$recruiter]), $this->phoneScreenSlot(), InterviewMode::PHONE, $phone, 20, null);
                break;

            case 'first_interview_upcoming':
                $moveTo('First Interview', $this->workMoment($applied->copy()->addDays(2), $r));
                $this->scheduleInterview($applicant, $recruiter, $interviewers, $this->nextInterviewSlot(), InterviewMode::VIDEO, $this->meetLink($r), 45, null);
                break;

            case 'second_interview_upcoming':
                $moveTo('First Interview', $this->workMoment($applied->copy()->addDays(2), $r));
                $this->scheduleInterview($applicant, $recruiter, $interviewers, $this->local($this->nextWeekday($applied->copy()->addDays(4)), '11:00'), InterviewMode::IN_PERSON, $this->setup['office'], 60, [4, 'yes']);
                $moveTo('Second Interview', $this->workMoment($applied->copy()->addDays(6), $r)->min($this->realNow->copy()->subHours(3)));
                $this->scheduleInterview($applicant, $recruiter, $interviewers->concat([$hr])->unique('id')->values(), $this->nextInterviewSlot(), InterviewMode::IN_PERSON, $this->setup['office'], 60, null);
                break;

            case 'offer':
                $moveTo('First Interview', $this->workMoment($applied->copy()->addDays(2), $r));
                $this->scheduleInterview($applicant, $recruiter, $interviewers, $this->local($this->nextWeekday($applied->copy()->addDays(4)), '11:30'), InterviewMode::IN_PERSON, $this->setup['office'], 60, [5, 'strong_yes']);
                $moveTo('Second Interview', $this->workMoment($applied->copy()->addDays(6), $r));
                $this->scheduleInterview($applicant, $recruiter, $interviewers->concat([$hr])->unique('id')->values(), $this->local($this->nextWeekday($applied->copy()->addDays(9)), '15:00'), InterviewMode::IN_PERSON, $this->setup['office'], 60, [4, 'strong_yes']);
                $offerAt = $this->workMoment($applied->copy()->addDays(12), $r);
                $this->at($offerAt, $recruiter, fn () => $this->recorder->transaction(fn () => $applicant->refresh()->update([
                    'stage_id'        => $stages->get('Contract Proposal'),
                    'offer_status'    => 'sent',
                    'offer_date'      => $offerAt->toDateString(),
                    'salary_proposed' => round(min((float) $job->salary_max, (float) $spec['expected']) / 1000) * 1000,
                ])));
                break;

            case 'refused':
                $reasonId = RefuseReason::query()->where('name', $spec['refuse'])->value('id') ?? RefuseReason::query()->value('id');
                $this->at($this->workMoment($applied->copy()->addDays(3), $r), $recruiter, fn () => $this->recorder->transaction(fn () => $applicant->refresh()->setAsRefused((int) $reasonId)));
                break;
        }
    }

    /**
     * Schedules an interview through InterviewService; one in the past is
     * scheduled at the time it was planned, then completed with feedback.
     * The candidate is never emailed.
     *
     * @param  Collection<int, User>  $interviewers
     * @param  array{0: int, 1: string}|null  $feedback  rating and recommendation for a past interview
     */
    private function scheduleInterview(Applicant $applicant, User $organizer, Collection $interviewers, Carbon $start, InterviewMode $mode, ?string $location, int $minutes, ?array $feedback): void
    {
        $past = $start->lessThan($this->realNow->copy()->addMinutes(30));
        $plannedAt = $past ? $this->workMoment($start->copy()->subDays(2), $this->rng('plan'.$applicant->id.$start->timestamp)) : $this->realNow->copy()->subMinutes(30);

        if ($past && ! $feedback) {
            return;
        }

        $interview = $this->at($plannedAt, $organizer, fn (): Interview => $this->recorder->transaction(fn (): Interview => app(InterviewService::class)->schedule($applicant->refresh(), [
            'starts_at'        => $start->toIso8601String(),
            'duration_minutes' => $minutes,
            'mode'             => $mode->value,
            'location'         => $location,
            'interviewer_ids'  => $interviewers->pluck('id')->all(),
            'notes'            => $mode === InterviewMode::PHONE ? 'Short screening call: availability, salary expectations, notice period.' : null,
            'notify_candidate' => false,
        ], $organizer)->interview));

        if (! $past || ! $feedback) {
            return;
        }

        [$rating, $recommendation] = $feedback;
        $this->at($start->copy()->addMinutes($minutes + 10), $organizer, fn () => $this->recorder->transaction(fn () => app(InterviewService::class)->markCompleted($interview->refresh(), $organizer)));

        $comments = [
            'strong_yes' => 'Strong on the fundamentals and clear about the role. Recommend moving ahead.',
            'yes'        => 'Good practical experience; a few gaps we can coach. Happy to proceed.',
        ];

        foreach ($interviewers as $position => $interviewer) {
            $this->at($start->copy()->addMinutes($minutes + 30 + $position * 25), $interviewer, fn () => $this->recorder->transaction(fn () => app(InterviewService::class)->submitFeedback($interview->refresh(), $interviewer, [
                'rating'         => max(1, min(5, $rating - ($position % 2))),
                'recommendation' => $position % 2 && $recommendation === InterviewRecommendation::STRONG_YES->value ? InterviewRecommendation::YES->value : $recommendation,
                'feedback'       => $comments[$recommendation] ?? null,
            ])));
        }
    }

    /** @return array<int, Carbon> upcoming interview times this week and next (Karachi) */
    private function interviewSlots(): array
    {
        $tomorrow = $this->nextWeekday($this->today->copy()->addDay());
        $nextMonday = $this->today->copy()->next(Carbon::MONDAY);

        return [
            $this->local($tomorrow, '12:00'),
            $this->local($nextMonday, '11:30'),
            $this->local($nextMonday->copy()->addDays(1), '15:00'),
            $this->local($nextMonday->copy()->addDays(2), '11:00'),
            $this->local($nextMonday->copy()->addDays(3), '14:30'),
            $this->local($tomorrow, '15:30'),
        ];
    }

    /** The next free upcoming interview time of the company (each candidate gets their own). */
    private function nextInterviewSlot(): Carbon
    {
        $slot = array_shift($this->upcomingSlots) ?? $this->local($this->nextWeekday($this->today->copy()->addDays(8)), '12:00');

        return $slot->copy();
    }

    /** A phone screen later today, or the next working day when the office day is nearly over. */
    private function phoneScreenSlot(): Carbon
    {
        $later = $this->local($this->today, '16:00');

        return $this->today->isWeekday() && $this->realNow->copy()->addMinutes(45)->lessThan($later)
            ? $later
            : $this->local($this->nextWeekday($this->today->copy()->addDay()), '12:30');
    }

    private function meetLink(Randomizer $r): string
    {
        $letters = fn (int $length): string => implode('', array_map(fn (): string => chr($r->getInt(97, 122)), range(1, $length)));

        return 'https://meet.google.com/'.$letters(3).'-'.$letters(4).'-'.$letters(3);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, string>
     */
    private function cvLines(array $spec, JobPosition $job, string $email, string $phone, Randomizer $r): array
    {
        $years = (int) $spec['years'];
        $employers = ['Al-Karam Logistics', 'Indus Freight Services', 'Sea Hawk Shipping Agency', 'Pak Cargo Movers', 'Bin Qasim Container Services', 'Crescent Transport Co.'];
        $lines = [
            'Karachi, Pakistan | '.$phone.' | '.$email,
            '',
            'PROFILE',
            ($years > 0 ? "{$years} years of experience. " : 'Fresh graduate. ').$spec['background'],
            '',
            'EXPERIENCE',
        ];

        if ($years > 0) {
            $end = (int) $this->today->year;
            $start = $end - $years;
            $lines[] = "{$start} - Present: ".$job->name.' / Operations, '.$this->pick($r, $employers).', Karachi';
        } else {
            $lines[] = 'Internship (8 weeks), '.$this->pick($r, $employers).', Karachi';
        }

        return [
            ...$lines,
            '',
            'EDUCATION',
            $this->pick($r, ['BBA, University of Karachi', 'B.Com, Government College of Commerce', 'BBA, Iqra University', 'BS Supply Chain, NED University']),
            '',
            'LANGUAGES',
            'Urdu (native), English (fluent)',
        ];
    }

    // ---------------------------------------------------------------------
    // Notifications and totals
    // ---------------------------------------------------------------------

    /** Demo notifications older than three days were read a few hours after they arrived. */
    private function settleNotifications(): void
    {
        $ids = DemoRecord::query()->where('company_id', $this->company->id)->where('record_table', 'notifications')->pluck('record_id');

        foreach ($ids->chunk(500) as $chunk) {
            DatabaseNotification::query()
                ->whereIn('id', $chunk->all())
                ->whereNull('read_at')
                ->where('created_at', '<', $this->realNow->copy()->subDays(3))
                ->update(['read_at' => DB::raw('DATE_ADD(created_at, INTERVAL 4 HOUR)')]);
        }
    }

    /** @return array<string, int|string> */
    private function totals(): array
    {
        $companyId = $this->company->id;
        $leaveStates = Leave::query()->where('company_id', $companyId)->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
        $requestStates = EmployeeRequest::query()->where('company_id', $companyId)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total_employees'    => Employee::query()->where('company_id', $companyId)->count(),
            'total_attendance'   => AttendanceRecord::query()->where('company_id', $companyId)->count(),
            'total_intervals'    => AttendanceInterval::query()->where('company_id', $companyId)->count(),
            'total_leaves'       => $leaveStates->map(fn ($count, $state): string => "{$state} {$count}")->implode(', '),
            'total_requests'     => $requestStates->map(fn ($count, $status): string => "{$status} {$count}")->implode(', '),
            'total_documents'    => EmployeeDocument::query()->where('company_id', $companyId)->count(),
            'total_jobs'         => JobPosition::query()->where('company_id', $companyId)->count(),
            'total_applicants'   => Applicant::query()->where('company_id', $companyId)->count(),
            'total_interviews'   => Interview::query()->where('company_id', $companyId)->count(),
        ];
    }

    // ---------------------------------------------------------------------
    // Time, randomness
    // ---------------------------------------------------------------------

    /**
     * Runs the callback as $actor with the clock at $moment (never later
     * than now), so timestamps, approval trails and notifications read as
     * if it happened then.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function at(Carbon $moment, ?User $actor, callable $callback): mixed
    {
        $moment = $moment->copy()->utc()->min($this->realNow->copy()->subMinutes(2));
        $previous = Auth::guard('web')->user();

        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow(CarbonImmutable::instance($moment));

        if ($actor) {
            Auth::guard('web')->setUser($actor);
        }

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();

            $previous ? Auth::guard('web')->setUser($previous) : Auth::guard('web')->forgetUser();
        }
    }

    /**
     * The moments of one workflow, in order; when the last one would be in
     * the future they are squeezed (order kept) to end just before now.
     *
     * @template K of array-key
     *
     * @param  array<K, Carbon>  $moments
     * @return array<K, Carbon>
     */
    private function fitBeforeNow(array $moments): array
    {
        $latest = $this->realNow->copy()->subMinutes(10);
        $first = reset($moments);
        $last = collect($moments)->max();

        if ($last->lessThanOrEqualTo($latest)) {
            return $moments;
        }

        $first = $first->copy()->min($latest->copy()->subHours(2));
        $span = max(1, (int) $first->diffInSeconds($last));
        $room = max(1, (int) $first->diffInSeconds($latest));

        return array_map(
            fn (Carbon $moment): Carbon => $first->copy()->addSeconds((int) round(max(0, $first->diffInSeconds($moment, false)) * $room / $span)),
            $moments,
        );
    }

    /** A date column value as that calendar day in Karachi (midnight). */
    private function localDate(mixed $value): Carbon
    {
        return Carbon::parse(Carbon::parse($value)->toDateString(), self::TIMEZONE)->startOfDay();
    }

    private function local(Carbon|string $date, string $time): Carbon
    {
        return Carbon::parse(($date instanceof Carbon ? $date->toDateString() : $date).' '.$time, self::TIMEZONE);
    }

    /** A moment in office hours on that day (the Friday before for a weekend). */
    private function workMoment(Carbon $day, Randomizer $r): Carbon
    {
        $day = $day->copy()->setTimezone(self::TIMEZONE);

        while ($day->isWeekend()) {
            $day->subDay();
        }

        return $this->local($day, sprintf('%02d:%02d', $r->getInt(10, 16), $r->getInt(0, 59)));
    }

    private function nextWeekday(Carbon $day): Carbon
    {
        $day = $day->copy();

        while ($day->isWeekend()) {
            $day->addDay();
        }

        return $day;
    }

    private function previousWeekday(Carbon $day): Carbon
    {
        $day = $day->copy()->subDay();

        while ($day->isWeekend()) {
            $day->subDay();
        }

        return $day;
    }

    private function randomWeekday(Randomizer $r, Carbon $from, Carbon $to): ?Carbon
    {
        $span = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false);

        if ($span < 0) {
            return null;
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $day = $from->copy()->startOfDay()->addDays($r->getInt(0, $span));
            if ($day->isWeekday()) {
                return $day;
            }
        }

        return null;
    }

    /**
     * Working days (Y-m-d) of a leave: $days of them from $from, or every
     * weekday from $from to $to.
     *
     * @return array<int, string>
     */
    private function businessRange(Carbon $from, ?int $days, ?Carbon $to = null): array
    {
        $range = [];
        $day = $from->copy()->startOfDay();

        if ($day->isWeekend()) {
            return [];
        }

        while ($days !== null ? count($range) < $days : $day->lessThanOrEqualTo($to)) {
            if ($day->isWeekday()) {
                $range[] = $day->toDateString();
            }
            $day->addDay();

            if (count($range) > 40 || $day->diffInDays($from) > 60) {
                break;
            }
        }

        return $range;
    }

    private function rng(string $seed): Randomizer
    {
        return new Randomizer(new Mt19937(crc32($seed)));
    }

    private function chance(Randomizer $r, float $probability): bool
    {
        return $r->getFloat(0, 1) < $probability;
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(Randomizer $r, array $items): mixed
    {
        return $items[$r->getInt(0, count($items) - 1)];
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
        $this->command?->warn($message);
    }
}
