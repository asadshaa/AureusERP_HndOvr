<?php

namespace Tests\Feature;

use App\Services\Dashboard\HomeDashboardContext;
use App\Services\Dashboard\RoleActionCenterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Webkul\Security\Models\User;

class RoleActionCenterDashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_hr_manager_sees_tailored_pending_actions_on_dashboard(): void
    {
        $zainab = User::where('email', 'zainab.malik@truckitin.com')->first();
        $this->actingAs($zainab);

        $response = $this->get('/admin');
        $response->assertStatus(200);
        $response->assertSee(app(HomeDashboardContext::class)->greeting().', ');
        $response->assertSee('Pending actions');
        $response->assertSee('HR Manager');

        $service = app(RoleActionCenterService::class);
        $actions = $service->getPendingActionsFor($zainab, 1);

        foreach ($actions['items'] as $item) {
            $this->assertNotEmpty($item['url']);
            $this->assertStringStartsWith('http', $item['url']);
            $response->assertSee($item['title']);
        }
    }

    public function test_admin_sees_company_wide_pending_actions_on_dashboard(): void
    {
        $raza = User::where('email', 'raza.afzal@truckitin.com')->first();
        $this->actingAs($raza);

        $response = $this->get('/admin');
        $response->assertStatus(200);
        $response->assertSee(app(HomeDashboardContext::class)->greeting().', ');
        $response->assertSee('Pending actions');
        $response->assertSee('Administrator');

        $service = app(RoleActionCenterService::class);
        $actions = $service->getPendingActionsFor($raza, 1);

        foreach ($actions['items'] as $item) {
            $this->assertNotEmpty($item['url']);
            $this->assertStringStartsWith('http', $item['url']);
            $response->assertSee($item['title']);
        }
    }

    public function test_regular_employee_sees_self_service_dashboard(): void
    {
        $employeeUser = User::where('email', 'bilal.ahmed@truckitin.com')->first()
            ?? User::where('email', 'ali.raza@truckitin.com')->first()
            ?? User::factory()->create();

        $this->actingAs($employeeUser);

        $response = $this->get('/admin');
        $response->assertStatus(200);
        $response->assertSee(app(HomeDashboardContext::class)->greeting().', ');
        $response->assertSee('Pending actions');
    }
}
