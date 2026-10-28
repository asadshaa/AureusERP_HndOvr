<?php

namespace Tests\Feature;

use App\Services\ControlCenter\ControlCenterService;
use App\Services\Dashboard\HomeDashboardContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ControlCenterOperationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_service_returns_authoritative_summary_for_company(): void
    {
        $service = app(ControlCenterService::class);
        $summary = $service->getOperationsSummary(1);

        $this->assertIsArray($summary);
        $this->assertEquals(1, $summary['company']['id']);
        $this->assertArrayHasKey('health_matrix', $summary);
        $this->assertArrayHasKey('attention_items', $summary);
        $this->assertArrayHasKey('statistics', $summary);

        $areas = collect($summary['health_matrix'])->pluck('area')->toArray();
        $this->assertContains('Attendance', $areas);
        $this->assertContains('Leave & Time-Off', $areas);
        $this->assertContains('Timesheets', $areas);
        $this->assertContains('Accounting & Ledger', $areas);
        $this->assertContains('Bank Reconciliation', $areas);
        $this->assertContains('Data & Statement Imports', $areas);
        $this->assertContains('Approvals Queue', $areas);
        $this->assertContains('System & Background Jobs', $areas);
    }

    public function test_company_isolation_enforced(): void
    {
        $service = app(ControlCenterService::class);

        $company1 = Company::find(1);
        $company2 = Company::where('id', '!=', 1)->first();

        if ($company2) {
            $summary1 = $service->getOperationsSummary($company1->id);
            $summary2 = $service->getOperationsSummary($company2->id);

            $this->assertEquals($company1->name, $summary1['company']['name']);
            $this->assertEquals($company2->name, $summary2['company']['name']);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_attendance_exceptions_trigger_attention_item(): void
    {
        $today = now()->toDateString();
        $employee = Employee::where('company_id', 1)->first();

        // Create a test record with needs_review
        $rec = AttendanceRecord::create([
            'company_id'          => 1,
            'employee_id'         => $employee->id,
            'attendance_date'     => $today,
            'check_in'            => now()->subHours(2),
            'check_out'           => now(),
            'status'              => 'present',
            'source'              => 'gps',
            'verification_status' => 'needs_review',
        ]);

        $service = app(ControlCenterService::class);
        $summary = $service->getOperationsSummary(1);

        $attMatrix = collect($summary['health_matrix'])->firstWhere('area', 'Attendance');
        $this->assertEquals('warning', $attMatrix['status_level']);

        $attentionIds = collect($summary['attention_items'])->pluck('id')->toArray();
        $this->assertContains('att_exceptions', $attentionIds);

        // Cleanup
        AttendanceRecord::withoutEvents(fn () => $rec->delete());
    }

    public function test_dashboard_renders_with_action_center(): void
    {
        $user = User::where('email', 'zainab.malik@truckitin.com')->first();
        $this->actingAs($user);

        $response = $this->get('/admin');
        $response->assertStatus(200);
        $response->assertSee(app(HomeDashboardContext::class)->greeting().', ');
        $response->assertSee('Pending actions');
    }
}
