<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\AttendanceRecordsRelationManager;
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

    public function test_employee_monthly_attendance_summary_calculates_metrics_and_renders_view(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $employee = Employee::create([
            'company_id' => $company->id,
            'name'       => 'Summary Employee',
        ]);

        // Weekday Present: 2026-10-01 (Thursday)
        AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-01',
            'status'          => 'present',
            'worked_hours'    => 8.0,
            'late_minutes'    => 15,
            'overtime_hours'  => 1.5,
        ]);

        // Weekday Half Day: 2026-10-02 (Friday)
        AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-02',
            'status'          => 'half_day',
            'worked_hours'    => 4.0,
            'late_minutes'    => 0,
            'overtime_hours'  => 0,
        ]);

        // Weekend: 2026-10-03 (Saturday - MUST BE EXCLUDED)
        AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-03',
            'status'          => 'present',
            'worked_hours'    => 5.0,
            'late_minutes'    => 30,
            'overtime_hours'  => 2.0,
        ]);

        // Weekday Absent: 2026-10-05 (Monday)
        AttendanceRecord::create([
            'company_id'      => $company->id,
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-05',
            'status'          => 'absent',
            'worked_hours'    => 0,
            'late_minutes'    => 0,
            'overtime_hours'  => 0,
        ]);

        $summary = AttendanceRecordsRelationManager::getMonthlySummary($employee, '2026-10');

        $this->assertEquals('October 2026', $summary['month']);
        $this->assertEquals(1, $summary['present']);
        $this->assertEquals(1, $summary['half_day']);
        $this->assertEquals(1, $summary['absent']);
        $this->assertEquals(15, $summary['late_minutes']); // excludes Saturday's 30
        $this->assertEquals(12.0, $summary['worked_hours']); // 8.0 + 4.0, excludes Saturday's 5.0
        $this->assertEquals(1.5, $summary['overtime_hours']); // 1.5, excludes Saturday's 2.0
        $this->assertEquals(22, $summary['working_days']); // 22 weekdays in October 2026

        // Verify the component renders without errors
        $html = view('employees::filament.components.employee-monthly-attendance-summary', [
            'summary'  => $summary,
            'employee' => $employee,
        ])->render();

        $this->assertStringContainsString('October 2026 Attendance Overview', $html);
        $this->assertStringContainsString('Summary Employee', $html);
        $this->assertStringContainsString('22 working days', $html);
    }
}
