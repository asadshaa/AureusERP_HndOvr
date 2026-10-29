<?php

namespace Tests\Feature;

use App\Filament\Widgets\EmployeeDashboardOverviewWidget;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Filament\Pages\Profile;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

class EmployeeDashboardOverviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_employee_dashboard_widget_can_view_only_for_users_with_employee(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $userWithoutEmployee = User::factory()->create(['default_company_id' => $company->id]);
        $userWithEmployee = User::factory()->create(['default_company_id' => $company->id]);

        Employee::query()->create([
            'company_id' => $company->id,
            'user_id'    => $userWithEmployee->id,
            'name'       => 'Test Employee',
        ]);

        $this->actingAs($userWithoutEmployee);
        $this->assertFalse(EmployeeDashboardOverviewWidget::canView());

        $this->actingAs($userWithEmployee);
        $this->assertTrue(EmployeeDashboardOverviewWidget::canView());
    }

    public function test_employee_dashboard_widget_renders_profile_and_leave_balances(): void
    {
        $this->travelTo('2026-10-28 10:00:00');

        // Company::created provisions Annual / Sick / Casual Leave with 12 / 8 / 5 day policies.
        $company = Company::factory()->create(['is_active' => true]);
        $managerUser = User::factory()->create(['default_company_id' => $company->id]);
        $manager = Employee::query()->create([
            'company_id' => $company->id,
            'user_id'    => $managerUser->id,
            'name'       => 'Manager Smith',
        ]);

        $department = Department::query()->create([
            'company_id' => $company->id,
            'name'       => 'Fleet Logistics',
        ]);

        // The hire-time allocation gives Annual 12, Sick 8, Casual 5 for 2026.
        $user = User::factory()->create(['default_company_id' => $company->id, 'name' => 'Alex Rivera']);
        $employee = Employee::query()->create([
            'company_id'      => $company->id,
            'user_id'         => $user->id,
            'parent_id'       => $manager->id,
            'department_id'   => $department->id,
            'name'            => 'Alex Rivera',
            'employee_number' => 'EMP-7788',
            'job_title'       => 'Logistics Specialist',
            'work_email'      => 'alex@company.com',
            'work_phone'      => '+1 555-0199',
        ]);

        $type = fn (string $name): LeaveType => LeaveType::query()->where('company_id', $company->id)->where('name', $name)->firstOrFail();

        // HR tops up Annual Leave by 3 days: 15.
        LeaveAllocation::query()->create([
            'holiday_status_id'   => $type('Annual Leave')->id,
            'employee_id'         => $employee->id,
            'employee_company_id' => $company->id,
            'state'               => State::VALIDATE_TWO->value,
            'date_from'           => '2026-01-01',
            'date_to'             => '2026-12-31',
            'number_of_days'      => 3,
        ]);

        $leave = fn (string $typeName, string $date, float $days, State $state): Leave => Leave::query()->create([
            'company_id'          => $company->id,
            'employee_company_id' => $company->id,
            'employee_id'         => $employee->id,
            'user_id'             => $user->id,
            'holiday_status_id'   => $type($typeName)->id,
            'request_date_from'   => $date,
            'request_date_to'     => $date,
            'date_from'           => $date,
            'date_to'             => $date,
            'number_of_days'      => $days,
            'state'               => $state->value,
        ]);

        $leave('Annual Leave', '2026-09-14', 3, State::VALIDATE_TWO);
        $leave('Sick Leave', '2026-11-02', 1, State::CONFIRM);
        $leave('Casual Leave', '2026-11-03', 2, State::CANCEL);

        $cards = collect(EmployeeDashboardOverviewWidget::calculateCanonicalLeaves($employee)['cards'])->keyBy('key');

        $this->assertSame(['allocated' => 15.0, 'taken' => 3.0, 'pending' => 0.0, 'left' => 12.0], array_intersect_key($cards['annual'], array_flip(['allocated', 'taken', 'pending', 'left'])));
        $this->assertSame(['allocated' => 8.0, 'taken' => 0.0, 'pending' => 1.0, 'left' => 7.0], array_intersect_key($cards['sick'], array_flip(['allocated', 'taken', 'pending', 'left'])));
        // The cancelled Casual Leave does not count.
        $this->assertSame(['allocated' => 5.0, 'taken' => 0.0, 'pending' => 0.0, 'left' => 5.0], array_intersect_key($cards['casual'], array_flip(['allocated', 'taken', 'pending', 'left'])));

        $this->actingAs($user);

        Livewire::test(EmployeeDashboardOverviewWidget::class)
            ->assertSee('Alex Rivera')
            ->assertSee('EMP-7788')
            ->assertSee('Logistics Specialist')
            ->assertSee('Fleet Logistics')
            ->assertSee('Manager Smith')
            ->assertSee('Annual Leave')
            ->assertSee('Sick Leave')
            ->assertSee('28 Days Total Entitlement')
            ->assertSee('24.0') // 28 - 3 taken - 1 pending
            ->assertSee('12.0') // Annual: 15 - 3
            ->assertSee('7.0')  // Sick: 8 - 1 pending
            ->assertSee('Cancelled');
    }

    public function test_profile_page_updates_reflect_on_erp_employee(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $uniqueEmail = 'orig_'.uniqid().'@erp.com';
        $updatedEmail = 'upd_'.uniqid().'@erp.com';
        $user = User::factory()->create(['default_company_id' => $company->id, 'name' => 'Original Name', 'email' => $uniqueEmail]);

        $employee = Employee::query()->create([
            'company_id'      => $company->id,
            'user_id'         => $user->id,
            'name'            => 'Original Name',
            'work_email'      => $uniqueEmail,
            'mobile_phone'    => '111-222',
            'emergency_phone' => '333-444',
        ]);

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm([
                'name'             => 'Updated Employee Name',
                'email'            => $updatedEmail,
                'mobile_phone'     => '999-888-777',
                'emergency_phone'  => '555-444-333',
            ], 'editProfileForm')
            ->call('updateProfile');

        $this->assertEquals('Updated Employee Name', $employee->fresh()->name);
        $this->assertEquals($updatedEmail, $employee->fresh()->work_email);
        $this->assertEquals('999-888-777', $employee->fresh()->mobile_phone);
        $this->assertEquals('555-444-333', $employee->fresh()->emergency_phone);
    }

    public function test_employee_dashboard_widget_defaults_to_canonical_25_leaves_allocation(): void
    {
        $this->travelTo('2026-10-28 10:00:00');

        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['default_company_id' => $company->id, 'name' => 'Sara Connor']);
        $employee = Employee::query()->create([
            'company_id' => $company->id,
            'user_id'    => $user->id,
            'name'       => 'Sara Connor',
        ]);

        $annualType = LeaveType::query()->where('company_id', $company->id)->where('name', 'Annual Leave')->firstOrFail();

        Leave::query()->create([
            'company_id'          => $company->id,
            'employee_company_id' => $company->id,
            'employee_id'         => $employee->id,
            'user_id'             => $user->id,
            'holiday_status_id'   => $annualType->id,
            'request_date_from'   => '2026-09-14',
            'request_date_to'     => '2026-09-15',
            'date_from'           => '2026-09-14',
            'date_to'             => '2026-09-15',
            'number_of_days'      => 2,
            'state'               => State::VALIDATE_TWO->value,
        ]);

        // Allocated (hire-time 12 / 8 / 5) or not yet (the policy entitlement): the same 25 days.
        foreach ([false, true] as $withoutAllocations) {
            if ($withoutAllocations) {
                LeaveAllocation::query()->where('employee_id', $employee->id)->delete();
            }

            $summary = EmployeeDashboardOverviewWidget::calculateCanonicalLeaves($employee);
            $left = collect($summary['cards'])->mapWithKeys(fn (array $card): array => [$card['key'] => $card['left']])->all();

            $this->assertSame(25.0, $summary['total_allocated']);
            $this->assertSame(23.0, $summary['total_left']);
            $this->assertSame(['annual' => 10.0, 'casual' => 5.0, 'sick' => 8.0], $left);
        }

        $this->actingAs($user);

        Livewire::test(EmployeeDashboardOverviewWidget::class)
            ->assertSee('Total Balance')
            ->assertSee('25 Days Total Entitlement')
            ->assertSee('23.0') // 25 total - 2 used = 23 left
            ->assertSee('Annual Leave')
            ->assertSee('10.0') // 12 allocated - 2 used = 10 left
            ->assertSee('Casual Leave')
            ->assertSee('5.0')  // 5 allocated - 0 used = 5 left
            ->assertSee('Sick Leave')
            ->assertSee('8.0');  // 8 allocated - 0 used = 8 left
    }
}
