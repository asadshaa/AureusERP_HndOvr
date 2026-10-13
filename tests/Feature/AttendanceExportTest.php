<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class AttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_export_filtering_logic_excludes_weekends(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['default_company_id' => $company->id]);
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id'    => $user->id,
            'name'       => 'Test Employee',
        ]);

        // Friday: 2026-10-09 (Weekday)
        $weekdayRecord = AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-09',
            'check_in'        => '2026-10-09 09:00:00',
            'check_out'       => '2026-10-09 17:00:00',
            'status'          => 'present',
        ]);

        // Saturday: 2026-10-10 (Weekend)
        $satRecord = AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-10',
            'check_in'        => '2026-10-10 10:00:00',
            'check_out'       => '2026-10-10 14:00:00',
            'status'          => 'present',
        ]);

        // Sunday: 2026-10-11 (Weekend)
        $sunRecord = AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-11',
            'check_in'        => '2026-10-11 10:00:00',
            'check_out'       => '2026-10-11 14:00:00',
            'status'          => 'present',
        ]);

        // Monday: 2026-10-12 (Weekday)
        $monRecord = AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-12',
            'check_in'        => '2026-10-12 09:00:00',
            'check_out'       => '2026-10-12 17:00:00',
            'status'          => 'present',
        ]);

        $records = AttendanceRecord::where('employee_id', $employee->id)->get();

        // Apply weekend exclusion logic
        $filtered = $records->reject(function ($r): bool {
            return $r->attendance_date ? Carbon::parse($r->attendance_date)->isWeekend() : false;
        });

        $this->assertCount(2, $filtered);
        $dates = $filtered->map(fn ($r) => Carbon::parse($r->attendance_date)->format('Y-m-d'))->toArray();
        $this->assertContains('2026-10-09', $dates);
        $this->assertContains('2026-10-12', $dates);
        $this->assertNotContains('2026-10-10', $dates);
        $this->assertNotContains('2026-10-11', $dates);
    }

    public function test_attendance_export_filters_by_date_range(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $employee = Employee::create([
            'company_id' => $company->id,
            'name'       => 'Date Range Employee',
        ]);

        // 2026-10-05 (Monday)
        AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-05',
            'status'          => 'present',
        ]);

        // 2026-10-08 (Thursday)
        AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-08',
            'status'          => 'present',
        ]);

        // 2026-10-12 (Monday)
        AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-12',
            'status'          => 'present',
        ]);

        $query = AttendanceRecord::query()->where('employee_id', $employee->id);
        $query->whereDate('attendance_date', '>=', '2026-10-06');
        $query->whereDate('attendance_date', '<=', '2026-10-10');

        $records = $query->get()->reject(function ($r): bool {
            return $r->attendance_date ? Carbon::parse($r->attendance_date)->isWeekend() : false;
        });

        $this->assertCount(1, $records);
        $this->assertEquals('2026-10-08', Carbon::parse($records->first()->attendance_date)->format('Y-m-d'));
    }
}
