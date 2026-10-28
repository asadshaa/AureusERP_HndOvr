<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Demo staff for ShipLine and OpenPort (Truck It In already has real data):
 * departments, an HR manager, an HR officer who also recruits, an operations
 * manager and four employees each, with logins, a reporting line and two
 * weeks of attendance. The CEO (Truck It In's) is made a member of both.
 * Idempotent: people that already exist (by email) are left alone.
 *
 *   php artisan db:seed --class=DemoCompanyStaffSeeder
 *
 * New passwords are written to storage/app/private/demo-accounts.txt (never shown).
 */
class DemoCompanyStaffSeeder extends Seeder
{
    /** @var array<string, array{domain: string, staff: array<int, array{name: string, title: string, dept: string, roles: array<int, string>, global?: bool, manager?: bool}>}> */
    private const COMPANIES = [
        'ShipLine' => [
            'domain' => 'shipline.demo',
            'staff'  => [
                ['name' => 'Hina Qureshi', 'title' => 'HR Manager', 'dept' => 'Human Resources', 'roles' => ['hr_manager', 'recruiter'], 'global' => true],
                ['name' => 'Saad Javed', 'title' => 'HR Officer', 'dept' => 'Human Resources', 'roles' => ['hr_officer', 'recruiter'], 'global' => true],
                ['name' => 'Kamran Shah', 'title' => 'Operations Manager', 'dept' => 'Operations', 'roles' => ['manager', 'hiring_manager'], 'manager' => true],
                ['name' => 'Bilal Haider', 'title' => 'Shipping Coordinator', 'dept' => 'Operations', 'roles' => ['employee']],
                ['name' => 'Sana Iqbal', 'title' => 'Customs Clerk', 'dept' => 'Operations', 'roles' => ['employee']],
                ['name' => 'Fahad Mirza', 'title' => 'Accountant', 'dept' => 'Finance', 'roles' => ['employee']],
                ['name' => 'Rabia Anwar', 'title' => 'Sales Executive', 'dept' => 'Sales', 'roles' => ['employee']],
            ],
        ],
        'OpenPort' => [
            'domain' => 'openport.demo',
            'staff'  => [
                ['name' => 'Mariam Siddiqui', 'title' => 'HR Manager', 'dept' => 'Human Resources', 'roles' => ['hr_manager', 'recruiter'], 'global' => true],
                ['name' => 'Omer Farooq', 'title' => 'HR Officer', 'dept' => 'Human Resources', 'roles' => ['hr_officer', 'recruiter'], 'global' => true],
                ['name' => 'Nadia Hussain', 'title' => 'Port Operations Manager', 'dept' => 'Operations', 'roles' => ['manager', 'hiring_manager'], 'manager' => true],
                ['name' => 'Usama Rauf', 'title' => 'Terminal Supervisor', 'dept' => 'Operations', 'roles' => ['employee']],
                ['name' => 'Ayesha Tariq', 'title' => 'Yard Planner', 'dept' => 'Operations', 'roles' => ['employee']],
                ['name' => 'Junaid Akhtar', 'title' => 'Billing Officer', 'dept' => 'Finance', 'roles' => ['employee']],
                ['name' => 'Komal Shahid', 'title' => 'Customer Service Executive', 'dept' => 'Sales', 'roles' => ['employee']],
            ],
        ],
    ];

    public function run(): void
    {
        app(HrRoleSeeder::class)->run();

        $ceo = User::query()->find(1);
        $calendarId = DB::table('employees_employees')->where('company_id', 1)->whereNotNull('calendar_id')->value('calendar_id');
        $credentials = [];

        foreach (self::COMPANIES as $companyName => $setup) {
            $company = Company::query()->where('name', $companyName)->first();

            if (! $company) {
                $this->command?->warn("Company {$companyName} not found; skipped.");

                continue;
            }

            if ($ceo) {
                $ceo->allowedCompanies()->syncWithoutDetaching([$company->id]);
            }

            $departments = [];
            $managerEmployee = null;

            foreach (array_unique(array_column($setup['staff'], 'dept')) as $deptName) {
                $departments[$deptName] = Department::query()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $deptName],
                    ['creator_id' => $ceo?->id],
                );
            }

            foreach ($setup['staff'] as $person) {
                $email = Str::lower(Str::before($person['name'], ' ')).'@'.$setup['domain'];
                $user = User::query()->where('email', $email)->first();

                if (! $user) {
                    $password = Str::password(14, symbols: false);
                    $user = User::query()->create([
                        'name'                => $person['name'],
                        'email'               => $email,
                        'password'            => $password,
                        'default_company_id'  => $company->id,
                        'is_active'           => true,
                    ]);
                    // Set apart: creating a user copies its fields to a new contact, which has no such column.
                    $user->forceFill(['resource_permission' => ($person['global'] ?? false) ? PermissionType::GLOBAL : PermissionType::INDIVIDUAL])->saveQuietly();
                    $credentials[] = "{$companyName}\t{$person['title']}\t{$email}\t{$password}";
                }

                $user->syncRoles(Role::query()->whereIn('name', $person['roles'])->get());

                $employee = Employee::query()->firstOrCreate(
                    ['company_id' => $company->id, 'user_id' => $user->id],
                    [
                        'name'              => $person['name'],
                        'job_title'         => $person['title'],
                        'work_email'        => $email,
                        'department_id'     => $departments[$person['dept']]->id,
                        'calendar_id'       => $calendarId,
                        'employee_type'     => 'employee',
                        'employment_status' => 'active',
                        'is_active'         => true,
                        'time_zone'         => 'Asia/Karachi',
                        'creator_id'        => $ceo?->id,
                    ],
                );

                if ($person['manager'] ?? false) {
                    $managerEmployee = $employee;
                }
            }

            $this->linkReportingLines($company, $departments, $managerEmployee);
            $this->seedAttendance($company);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($credentials !== []) {
            $file = storage_path('app/private/demo-accounts.txt');
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file, implode(PHP_EOL, ["Company\tRole\tEmail\tPassword", ...$credentials]).PHP_EOL, FILE_APPEND);
            $this->command?->info(count($credentials).' demo login(s) created; passwords in storage/app/private/demo-accounts.txt');
        }
    }

    /** @param  array<string, Department>  $departments */
    private function linkReportingLines(Company $company, array $departments, ?Employee $manager): void
    {
        if (! $manager) {
            return;
        }

        foreach ($departments as $department) {
            if (! $department->manager_id && $department->name !== 'Human Resources') {
                $department->forceFill(['manager_id' => $manager->id])->save();
            }
        }

        Employee::query()
            ->where('company_id', $company->id)
            ->whereKeyNot($manager->id)
            ->whereNull('parent_id')
            ->whereNotIn('job_title', ['HR Manager'])
            ->update(['parent_id' => $manager->id]);
    }

    /** Two weeks of weekday attendance (09:00–17:30 Karachi): mostly on time, some late, one absence. */
    private function seedAttendance(Company $company): void
    {
        $employees = Employee::query()->where('company_id', $company->id)->orderBy('id')->get();
        $days = collect(range(1, 14))
            ->map(fn (int $daysAgo): Carbon => today('Asia/Karachi')->subDays($daysAgo))
            ->reject(fn (Carbon $day): bool => $day->isWeekend());

        foreach ($employees as $index => $employee) {
            foreach ($days as $dayIndex => $day) {
                if (AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', $day)->exists()) {
                    continue;
                }

                $absent = ($index + $dayIndex) % 13 === 5;
                $lateMinutes = ($index * 7 + $dayIndex * 3) % 11 === 0 ? 15 + ($dayIndex % 20) : 0;
                $start = $day->copy()->setTime(9, 0);
                $end = $day->copy()->setTime(17, 30);
                $checkIn = $start->copy()->addMinutes($lateMinutes - 5 + ($dayIndex % 4));
                $checkOut = $end->copy()->addMinutes(($index + $dayIndex) % 25);

                AttendanceRecord::query()->create([
                    'company_id'              => $company->id,
                    'employee_id'             => $employee->id,
                    'attendance_date'         => $day->toDateString(),
                    'scheduled_start'         => $start->copy()->utc(),
                    'scheduled_end'           => $end->copy()->utc(),
                    'check_in'                => $absent ? null : $checkIn->copy()->utc(),
                    'check_out'               => $absent ? null : $checkOut->copy()->utc(),
                    'worked_hours'            => $absent ? 0 : round($checkIn->diffInMinutes($checkOut) / 60, 4),
                    'overtime_hours'          => 0,
                    'late_minutes'            => $absent ? 0 : max(0, $lateMinutes - 5),
                    'early_departure_minutes' => 0,
                    'status'                  => $absent ? 'absent' : 'present',
                    'source'                  => 'import',
                    'verification_status'     => 'verified',
                    'notes'                   => 'Demo data',
                ]);
            }
        }
    }
}
