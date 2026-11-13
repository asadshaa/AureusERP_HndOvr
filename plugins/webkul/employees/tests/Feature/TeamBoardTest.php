<?php

/**
 * Employees -> Team board: who may open it, the company switcher for people who
 * manage more than one company, and the drag-and-drop move (which must keep every
 * rule of ReportingLineService: company, loops, own line).
 */

use Database\Seeders\HrRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\TeamBoard;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\ReportingChange;
use Webkul\Employee\Services\Reporting\ReportingLineService;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function tbPerson(Company $company, string $name, ?Employee $manager = null, ?string $role = null): Employee
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role !== null) {
        $user->assignRole(Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail());
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Employee::query()->create([
        'company_id'        => $company->id,
        'user_id'           => $user->id,
        'name'              => $name,
        'work_email'        => $user->email,
        'employee_number'   => 'TB-'.$company->id.'-'.$user->id,
        'employment_status' => 'active',
        'is_active'         => true,
        'parent_id'         => $manager?->id,
    ]);
}

function tbActAs(Employee $person): User
{
    $user = $person->user->fresh();
    Auth::login($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

beforeEach(function (): void {
    app(HrRoleSeeder::class)->run();
    ReportingLineService::flushMemo();
    $this->company = Company::factory()->create(['name' => 'Board Co', 'is_active' => true]);
    $this->hr = tbPerson($this->company, 'Hira HR', null, 'hr_manager');
    $this->boss = tbPerson($this->company, 'Bilal Boss');
    $this->alt = tbPerson($this->company, 'Alia Alternate');
    $this->staff = tbPerson($this->company, 'Sana Staff', $this->boss);
});

it('opens only for people who may manage reporting lines', function (): void {
    tbActAs($this->hr);
    expect(TeamBoard::canAccess())->toBeTrue();

    tbActAs($this->staff);
    expect(TeamBoard::canAccess())->toBeFalse();
});

it('shows the team as cards and moves a person under a new manager', function (): void {
    tbActAs($this->hr);

    Livewire::test(TeamBoard::class)
        ->assertSee('Bilal Boss')
        ->assertSee('Sana Staff')
        ->call('movePerson', $this->staff->id, $this->alt->id);

    expect($this->staff->fresh()->parent_id)->toBe($this->alt->id)
        ->and(ReportingChange::query()->where('employee_id', $this->staff->id)->where('status', ReportingChange::Applied)->exists())->toBeTrue();
});

it('moves a person to the top of the company', function (): void {
    tbActAs($this->hr);

    Livewire::test(TeamBoard::class)->call('movePerson', $this->staff->id, null);

    expect($this->staff->fresh()->parent_id)->toBeNull();
});

it('refuses a move that would make a loop and leaves the line alone', function (): void {
    tbActAs($this->hr);

    Livewire::test(TeamBoard::class)->call('movePerson', $this->boss->id, $this->staff->id);

    expect($this->boss->fresh()->parent_id)->toBeNull();
});

it('lets a person who manages two companies switch between them, and refuses a company they do not manage', function (): void {
    $other = Company::factory()->create(['name' => 'Second Co', 'is_active' => true]);
    $third = Company::factory()->create(['name' => 'Third Co', 'is_active' => true]);
    $otherBoss = tbPerson($other, 'Omar Owner');
    $otherStaff = tbPerson($other, 'Olga Staff');
    $thirdStaff = tbPerson($third, 'Tariq Third');

    $this->hr->user->allowedCompanies()->syncWithoutDetaching([$other->id]);
    tbActAs($this->hr);

    Livewire::test(TeamBoard::class)
        ->assertSee('Second Co')
        ->assertDontSee('Third Co')
        ->call('selectCompany', $other->id)
        ->assertSee('Omar Owner')
        ->assertDontSee('Sana Staff')
        ->call('movePerson', $otherStaff->id, $otherBoss->id);

    expect($otherStaff->fresh()->parent_id)->toBe($otherBoss->id);

    Livewire::test(TeamBoard::class)
        ->call('selectCompany', $third->id)
        ->assertSet('companyId', $this->company->id);

    expect($thirdStaff->fresh()->parent_id)->toBeNull();
});

it('never moves someone from another company, even by id', function (): void {
    $other = Company::factory()->create(['name' => 'Second Co', 'is_active' => true]);
    $outsider = tbPerson($other, 'Olga Outsider');

    tbActAs($this->hr);

    Livewire::test(TeamBoard::class)->call('movePerson', $outsider->id, $this->boss->id);

    expect($outsider->fresh()->parent_id)->toBeNull();
});

it('sends a change to her own line for approval instead of applying it', function (): void {
    tbActAs($this->hr);

    Livewire::test(TeamBoard::class)->call('movePerson', $this->hr->id, $this->boss->id);

    expect($this->hr->fresh()->parent_id)->toBeNull()
        ->and(ReportingChange::query()->where('employee_id', $this->hr->id)->where('status', ReportingChange::Pending)->exists())->toBeTrue();
});

it('does not let an ordinary employee move anyone', function (): void {
    tbActAs($this->staff);

    Livewire::test(TeamBoard::class)->assertForbidden();
});
