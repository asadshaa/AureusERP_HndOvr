<?php

/**
 * The HR how-to guide (Employees -> Help, /admin/hr-help) opens for every
 * signed-in user, and the main HR lists carry a "Help" header action that
 * links to their own section of it.
 */

use Database\Seeders\HrRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\HrHelp;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Filament\Actions\HelpAction;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource\Pages\ListTimeOff;

function hrHelpUser(Company $company, ?string $role = null): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role !== null) {
        $user->assignRole(Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    Employee::query()->create([
        'company_id'        => $company->id,
        'user_id'           => $user->id,
        'name'              => $role === null ? 'Plain Employee' : 'HR Manager',
        'work_email'        => $user->email,
        'employee_number'   => 'HELP-'.$company->id.'-'.$user->id,
        'employment_status' => 'active',
        'is_active'         => true,
    ]);

    return $user->fresh();
}

function hrHelpActAs(User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

beforeEach(function (): void {
    app(HrRoleSeeder::class)->run();
    $this->company = Company::factory()->create(['name' => 'Help Page Co', 'is_active' => true]);
});

it('shows the how-to guide to an HR manager', function (): void {
    hrHelpActAs(hrHelpUser($this->company, 'hr_manager'));

    expect(HrHelp::canAccess())->toBeTrue();

    Livewire::test(HrHelp::class)
        ->assertOk()
        ->assertSee('HR how-to guide')
        ->assertSee('Time Off management (leave requests)')
        ->assertSee('Annual 12, Sick 8, Casual 5')
        ->assertSee('id="leave-requests"', false);
});

it('shows the how-to guide to a plain employee without any HR role', function (): void {
    hrHelpActAs(hrHelpUser($this->company));

    expect(HrHelp::canAccess())->toBeTrue();

    Livewire::test(HrHelp::class)
        ->assertOk()
        ->assertSee('My Time Off (asking for leave)')
        ->assertSee('Submit for approval')
        ->assertSee('id="my-leave"', false)
        ->assertSee('id="check-in"', false);
});

it('opens the guide over HTTP for a signed-in employee', function (): void {
    $this->actingAs(hrHelpUser($this->company))
        ->get(HrHelp::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('HR how-to guide');
});

it('puts a Help action linking to the right section on the main HR lists', function (string $page, string $topic): void {
    hrHelpActAs(hrHelpUser($this->company, 'hr_manager'));

    Livewire::test($page)
        ->assertOk()
        ->assertActionVisible('help')
        ->assertActionHasUrl('help', HelpAction::url($topic));

    expect(HelpAction::url($topic))->toEndWith('/hr-help#'.$topic);
})->with([
    'employees'         => [ListEmployees::class, 'employees'],
    'leave requests'    => [ListTimeOff::class, 'leave-requests'],
    'employee requests' => [ManageEmployeeRequests::class, 'my-requests'],
]);
