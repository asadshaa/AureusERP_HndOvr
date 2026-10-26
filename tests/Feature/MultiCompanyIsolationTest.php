<?php

/**
 * Multi-company isolation, Batch 1 (docs/MULTI_COMPANY_ACCESS_PLAN.md): a
 * user works inside one of their own companies at a time and sees only that
 * company's employees, users and HR configuration; only a group owner can
 * give anyone access to another company.
 */

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Filament\Resources\UserResource;
use Webkul\Security\Filament\Resources\UserResource\Pages\EditUser;
use Webkul\Security\Http\Middleware\EnsureActiveCompany;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar as WebkulPermissionRegistrar;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Database\Factories\LeaveTypeFactory;
use Webkul\TimeOff\Filament\Clusters\Configurations\Resources\LeaveTypeResource;
use Webkul\TimeOff\Filament\Clusters\Configurations\Resources\MandatoryDayResource\Pages\ListMandatoryDays;
use Webkul\TimeOff\Models\LeaveMandatoryDay;
use Webkul\TimeOff\Models\LeaveType;

/** A user whose active company is $company, who also belongs to $alsoIn. */
function companyMember(Company $company, array $alsoIn = [], string ...$permissions): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $user->allowedCompanies()->sync(collect($alsoIn)->pluck('id')->all());

    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(WebkulPermissionRegistrar::class)->forgetCachedPermissions();
    CurrentCompany::flush();

    return $user->refresh();
}

function companyEmployee(Company $company): Employee
{
    return Employee::query()->create([
        'company_id' => $company->id, 'name' => 'Employee '.Str::random(6), 'is_active' => true, 'employment_status' => 'active',
    ]);
}

function companyLeaveType(?Company $company): LeaveType
{
    return LeaveTypeFactory::new()->create(['company_id' => $company?->id, 'creator_id' => null, 'is_active' => true]);
}

beforeEach(function (): void {
    $this->shipLine = Company::factory()->create(['name' => 'ShipLine '.Str::random(4), 'is_active' => true]);
    $this->openPort = Company::factory()->create(['name' => 'OpenPort '.Str::random(4), 'is_active' => true]);
});

it('counts only active companies the user belongs to, and switches only among them', function () {
    $archived = Company::factory()->create(['is_active' => true]);
    $user = companyMember($this->shipLine, [$this->openPort, $archived]);
    $archived->delete();
    CurrentCompany::flush();

    expect(CurrentCompany::membershipIds($user))->toEqualCanonicalizing([$this->shipLine->id, $this->openPort->id])
        ->and(CurrentCompany::id($user))->toBe($this->shipLine->id);

    CurrentCompany::switchTo($this->openPort->id, $user);
    expect($user->refresh()->default_company_id)->toBe($this->openPort->id);

    expect(fn () => CurrentCompany::switchTo($archived->id, $user))->toThrow(RuntimeException::class);
    expect(fn () => CurrentCompany::switchTo(Company::factory()->create()->id, $user))->toThrow(RuntimeException::class);
});

it('moves a user off an archived company and refuses users with no company', function () {
    $user = companyMember($this->shipLine, [$this->openPort]);
    $this->shipLine->delete();
    CurrentCompany::flush();

    expect(CurrentCompany::ensureValid($user))->toBeTrue()
        ->and($user->refresh()->default_company_id)->toBe($this->openPort->id);

    $this->openPort->delete();
    CurrentCompany::flush();
    Auth::login($user);

    expect(CurrentCompany::ensureValid($user))->toBeFalse()
        ->and(fn () => (new EnsureActiveCompany)->handle(Request::create('/admin'), fn () => response('ok')))
        ->toThrow(HttpException::class);
});

it('switches company through the topbar endpoint only to the user\'s own companies', function () {
    $user = companyMember($this->shipLine, [$this->openPort]);
    $stranger = Company::factory()->create(['is_active' => true]);

    $this->actingAs($user)->post(route('security.switch-company'), ['company_id' => $stranger->id])->assertForbidden();
    expect($user->refresh()->default_company_id)->toBe($this->shipLine->id);

    $this->actingAs($user)->post(route('security.switch-company'), ['company_id' => $this->openPort->id])->assertRedirect();
    expect($user->refresh()->default_company_id)->toBe($this->openPort->id);
});

it('lists only the active company\'s employees', function () {
    $hr = companyMember($this->shipLine, [$this->openPort], 'hr_view_all_records');
    $ours = companyEmployee($this->shipLine);
    $theirs = companyEmployee($this->openPort);
    Auth::login($hr);

    expect(EmployeeResource::getEloquentQuery()->pluck('id')->all())->toContain($ours->id)->not->toContain($theirs->id);

    CurrentCompany::switchTo($this->openPort->id, $hr);
    Auth::login($hr->refresh());

    expect(EmployeeResource::getEloquentQuery()->pluck('id')->all())->toContain($theirs->id)->not->toContain($ours->id);
});

it('lists only the active company\'s users', function () {
    $admin = companyMember($this->shipLine);
    $colleague = companyMember($this->shipLine);
    $outsider = companyMember($this->openPort);
    $sharedUser = companyMember($this->openPort, [$this->shipLine]);
    Auth::login($admin);

    expect(UserResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($admin->id, $colleague->id, $sharedUser->id)
        ->not->toContain($outsider->id);
});

it('shows the active company\'s and shared HR definitions, and only a group owner changes shared ones', function () {
    $ours = companyLeaveType($this->shipLine);
    $shared = companyLeaveType(null);
    $theirs = companyLeaveType($this->openPort);

    Auth::login(companyMember($this->shipLine));

    expect(LeaveTypeResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($ours->id, $shared->id)
        ->not->toContain($theirs->id);

    $reflection = new ReflectionMethod(LeaveTypeResource::class, 'companyAllowsChange');
    expect($reflection->invoke(null, $ours))->toBeTrue()
        ->and($reflection->invoke(null, $shared))->toBeFalse()
        ->and($reflection->invoke(null, $theirs))->toBeFalse();

    Auth::login(companyMember($this->shipLine, [], CurrentCompany::MANAGE_MEMBERSHIPS));
    expect($reflection->invoke(null, $shared))->toBeTrue();
});

it('lets only a group owner put a user in a company the actor does not belong to', function () {
    Filament::setCurrentPanel('admin');
    $target = companyMember($this->shipLine);
    $target->assignRole(Role::query()->firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']));
    $userAdmin = ['view_any_security_user', 'view_security_user', 'update_security_user'];

    expect(CurrentCompany::canAssignCompany($this->openPort->id, companyMember($this->shipLine)))->toBeFalse()
        ->and(CurrentCompany::canAssignCompany($this->shipLine->id, companyMember($this->shipLine)))->toBeTrue();

    Auth::login(companyMember($this->shipLine, [], ...$userAdmin));
    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['default_company_id' => $this->openPort->id])
        ->call('save')
        ->assertHasFormErrors(['default_company_id']);
    expect($target->refresh()->default_company_id)->toBe($this->shipLine->id);

    Auth::login(companyMember($this->shipLine, [], CurrentCompany::MANAGE_MEMBERSHIPS, ...$userAdmin));
    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm(['default_company_id' => $this->openPort->id])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($target->refresh()->default_company_id)->toBe($this->openPort->id);
});

it('sets memberships, the active company and group-owner access from the command', function () {
    $user = companyMember($this->shipLine);

    $this->artisan('erp:company-access', ['email' => $user->email, '--companies' => [$this->openPort->id], '--dry-run' => true])->assertSuccessful();
    expect($user->refresh()->default_company_id)->toBe($this->shipLine->id);

    $this->artisan('erp:company-access', [
        'email'         => $user->email,
        '--companies'   => [$this->shipLine->name.','.$this->openPort->id],
        '--default'     => (string) $this->openPort->id,
        '--group-owner' => true,
    ])->assertSuccessful();

    $user->refresh();
    CurrentCompany::flush();

    expect($user->default_company_id)->toBe($this->openPort->id)
        ->and(CurrentCompany::membershipIds($user))->toEqualCanonicalizing([$this->shipLine->id, $this->openPort->id])
        ->and(CurrentCompany::isGroupOwner($user))->toBeTrue();

    $this->artisan('erp:company-access', ['email' => $user->email, '--companies' => ['No Such Company']])->assertFailed();
});

it('lists the newest records first when a list has no sort of its own', function () {
    $company = $this->shipLine;
    Auth::login(companyMember($company, [], 'view_any_time_off_mandatory::day'));
    Filament::setCurrentPanel('admin');

    $day = ['company_id' => $company->id, 'color' => 1, 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString()];
    $older = LeaveMandatoryDay::query()->create([...$day, 'name' => 'Older day']);
    $newer = LeaveMandatoryDay::query()->create([...$day, 'name' => 'Newer day']);

    Livewire::test(ListMandatoryDays::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});
