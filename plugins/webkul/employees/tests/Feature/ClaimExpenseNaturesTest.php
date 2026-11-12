<?php

/**
 * Each claim category has its own "nature of expense" choices. HR edits them
 * as a list of tags (stored as the same comma-separated string as before),
 * and companies other than Truck It In stop carrying Truck It In's vendor
 * names: the data migration swaps untouched copies for generic lists.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Database\Seeders\ClaimsWorkflowSeeder;
use Webkul\Employee\Filament\Resources\EmployeeRequestTypeResource\Pages\ManageEmployeeRequestTypes;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

const CEN_TII_PEOPLE = 'Payroll, G-Suite, Shell, EOBI, Earned Wage Access, Health Insurance, Munshiana, Income Tax, Zong, BYOD, Medical Bills, Employee Engagement, Team Event';

function cenType(Company $company, string $category, array $configuration, bool $financial = true): EmployeeRequestType
{
    return EmployeeRequestType::query()->create([
        'company_id'            => $company->id,
        'code'                  => 'claim_'.$category,
        'name'                  => ucfirst($category),
        'category'              => $category,
        'approval_request_type' => 'claim_'.$category,
        'is_financial'          => $financial,
        'is_active'             => true,
        'configuration'         => $configuration,
    ]);
}

function cenRunMigration(): void
{
    (require base_path('plugins/webkul/employees/database/migrations/2026_11_12_200001_use_generic_expense_natures_for_other_companies.php'))->up();
}

function cenHrUser(Company $company): User
{
    $role = Role::query()->firstOrCreate(['name' => 'cen_hr_manager', 'guard_name' => 'web']);
    foreach (HrPermissions::all() as $name) {
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->assignRole($role);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('replaces an untouched Truck It In copy with the generic list', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $people = cenType($company, 'people', ['expense_natures' => CEN_TII_PEOPLE, 'max_salary_percent' => '50']);
    $tech = cenType($company, 'tech', ['expense_natures' => 'Tech tools, Laptop repairs']);

    cenRunMigration();

    expect($people->fresh()->getExpenseNatures())->toBe(['Payroll', 'Insurance', 'Medical bills', 'Employee engagement', 'Training', 'Other'])
        ->and($people->fresh()->configuration['max_salary_percent'])->toBe('50')
        ->and($tech->fresh()->getExpenseNatures())->toBe(['Software and tools', 'Equipment repairs', 'Other']);
});

it('leaves a list HR edited alone', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $edited = cenType($company, 'people', ['expense_natures' => CEN_TII_PEOPLE.', Bonus']);
    $own = cenType($company, 'tech', ['expense_natures' => 'Cloud hosting']);
    $empty = cenType($company, 'others', []);

    cenRunMigration();

    expect($edited->fresh()->configuration['expense_natures'])->toBe(CEN_TII_PEOPLE.', Bonus')
        ->and($own->fresh()->getExpenseNatures())->toBe(['Cloud hosting'])
        ->and($empty->fresh()->getExpenseNatures())->toBe([]);
});

it('never touches company 1 and is idempotent', function () {
    $before = DB::table('employees_request_types')->where('company_id', 1)->orderBy('id')->get(['id', 'configuration', 'updated_at'])->all();
    $company = Company::factory()->create(['is_active' => true]);
    $type = cenType($company, 'people', ['expense_natures' => CEN_TII_PEOPLE]);

    cenRunMigration();
    $afterFirst = $type->fresh()->configuration;
    cenRunMigration();

    expect($type->fresh()->configuration)->toBe($afterFirst)
        ->and(DB::table('employees_request_types')->where('company_id', 1)->orderBy('id')->get(['id', 'configuration', 'updated_at'])->all())->toEqual($before);
});

it('gives a new non-Truck It In company generic lists from the seeder', function () {
    foreach (ClaimsWorkflowSeeder::GENERIC_NATURES as $category => $list) {
        expect($list)->not->toContain('Aspire')->not->toContain('Zong')->not->toContain('PSEB');
    }

    expect(array_keys(ClaimsWorkflowSeeder::GENERIC_NATURES))->toHaveCount(9);
});

it('edits the expense natures as tags and stores the same comma-separated string', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $type = cenType($company, 'people', ['expense_natures' => 'Payroll, Training', 'max_salary_percent' => '40']);
    $user = cenHrUser($company);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($user);

    $page = Livewire::test(ManageEmployeeRequestTypes::class)
        ->mountTableAction('edit', $type)
        ->assertSchemaStateSet(['expense_natures_list' => ['Payroll', 'Training']]);

    $page->setActionData(['expense_natures_list' => ['Payroll', 'Training', 'Bonus']])
        ->callMountedTableAction()
        ->assertHasNoFormErrors();

    $type->refresh();

    expect($type->configuration['expense_natures'])->toBe('Payroll, Training, Bonus')
        ->and($type->configuration['max_salary_percent'])->toBe('40')
        ->and($type->getExpenseNatures())->toBe(['Payroll', 'Training', 'Bonus']);

    Livewire::test(ManageEmployeeRequestTypes::class)
        ->mountTableAction('edit', $type)
        ->setActionData(['expense_natures_list' => []])
        ->callMountedTableAction()
        ->assertHasNoFormErrors();

    expect($type->fresh()->getExpenseNatures())->toBe([])
        ->and($type->fresh()->configuration['max_salary_percent'])->toBe('40');
});

it('keeps the stored list of a type that is not financial when HR saves it', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $type = cenType($company, 'salary_advance', ['expense_natures' => 'Rent', 'max_salary_percent' => '50'], financial: false);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(cenHrUser($company));

    Livewire::test(ManageEmployeeRequestTypes::class)
        ->mountTableAction('edit', $type)
        ->callMountedTableAction()
        ->assertHasNoFormErrors();

    expect($type->fresh()->configuration)->toEqual(['max_salary_percent' => '50', 'expense_natures' => 'Rent']);
});
