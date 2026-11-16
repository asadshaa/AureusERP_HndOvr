<?php

/**
 * Expense heads-up: an employee tells HR an expense WILL be made on a date.
 * Line manager -> HR, nothing posted, then "Turn into claim" once the money is
 * spent, with the over-estimate comparison and the late reminder.
 */

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Database\Seeders\ExpenseHeadsUpWorkflowSeeder;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\ExpenseHeadsUpService;
use Webkul\Employee\Support\AdvanceKinds;
use Webkul\Employee\Support\ExpenseHeadsUp;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 06:00:00', 'UTC'));
});

function ehuUser(Company $company, array $permissions = [], ?string $role = null): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role !== null) {
        $roleModel = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $name) {
            $roleModel->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }
        $user->assignRole($roleModel);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @return array{company: Company, employee: Employee, employee_user: User, manager_user: User, hr: User, claim_type: EmployeeRequestType, heads_up_type: EmployeeRequestType} */
function ehuTeam(): array
{
    $company = Company::factory()->create(['is_active' => true]);
    app(ExpenseHeadsUpWorkflowSeeder::class)->provisionForCompany($company);

    $managerUser = ehuUser($company);
    $manager = Employee::query()->create(['company_id' => $company->id, 'name' => 'Line Manager', 'is_active' => true, 'user_id' => $managerUser->id]);
    $employeeUser = ehuUser($company);
    $employee = Employee::query()->create(['company_id' => $company->id, 'name' => 'Bilal Ahmed', 'is_active' => true, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id]);
    $hr = ehuUser($company, [HrPermissions::ViewAllRecords, HrPermissions::ManageEmployeeRequests, HrPermissions::ApproveLeave], 'ehu_hr');

    $claimType = EmployeeRequestType::query()->create([
        'company_id'            => $company->id, 'code' => 'claim_ehu', 'name' => 'Travel claim', 'category' => 'claim_ehu',
        'approval_request_type' => 'claim_ehu', 'is_financial' => true, 'is_active' => true,
        'configuration'         => ['expense_natures' => 'Travel, Fuel'],
    ]);

    return [
        'company'       => $company,
        'employee'      => $employee,
        'employee_user' => $employeeUser,
        'manager_user'  => $managerUser,
        'hr'            => $hr,
        'claim_type'    => $claimType,
        'heads_up_type' => EmployeeRequestType::query()->where('company_id', $company->id)->where('code', ExpenseHeadsUp::Code)->firstOrFail(),
    ];
}

function ehuDraft(array $team, array $payload = []): EmployeeRequest
{
    return EmployeeRequest::query()->create([
        'company_id'      => $team['company']->id,
        'employee_id'     => $team['employee']->id,
        'request_type_id' => $team['heads_up_type']->id,
        'requested_by'    => $team['employee_user']->id,
        'currency_id'     => $team['company']->currency_id,
        'title'           => 'Lahore visit',
        'status'          => 'draft',
        'payload'         => $payload + ['expected_on' => '2026-11-24', 'estimated_amount' => '20000', 'what_for' => 'Hotel and fuel for the Lahore visit'],
    ]);
}

/** A heads-up that went through line manager and HR. */
function ehuApproved(array $team, array $payload = []): EmployeeRequest
{
    $service = app(EmployeeRequestService::class);
    $request = ehuDraft($team, $payload);
    $service->submit($request, $team['employee_user']);
    $service->approve($request->fresh(), $team['manager_user']);
    $service->approve($request->fresh(), $team['hr']);

    return $request->fresh(['requestType', 'employee']);
}

it('provisions the heads-up type and a line manager then HR workflow for each company, once', function () {
    $team = ehuTeam();
    app(ExpenseHeadsUpWorkflowSeeder::class)->provisionForCompany($team['company']);

    $workflows = ApprovalWorkflow::query()->where('company_id', $team['company']->id)->where('request_type', ExpenseHeadsUp::ApprovalRequestType)->with('steps')->get();

    expect(EmployeeRequestType::query()->where('company_id', $team['company']->id)->where('code', ExpenseHeadsUp::Code)->count())->toBe(1)
        ->and($team['heads_up_type']->is_financial)->toBeFalse()
        ->and($workflows)->toHaveCount(1)
        ->and($workflows->first()->steps->pluck('hierarchy_route')->all())->toBe(['requester_manager', 'hr']);
});

it('refuses a heads-up with a past date, no estimate or no purpose', function () {
    $team = ehuTeam();
    $service = app(EmployeeRequestService::class);

    expect(fn () => $service->submit(ehuDraft($team, ['expected_on' => '2026-11-15']), $team['employee_user']))->toThrow(RuntimeException::class, 'cannot be in the past')
        ->and(fn () => $service->submit(ehuDraft($team, ['estimated_amount' => '0']), $team['employee_user']))->toThrow(RuntimeException::class, 'greater than zero')
        ->and(fn () => $service->submit(ehuDraft($team, ['what_for' => '']), $team['employee_user']))->toThrow(RuntimeException::class, 'what you will spend');
});

it('takes a heads-up through the line manager and HR without posting anything to accounting', function () {
    $team = ehuTeam();
    $headsUp = ehuApproved($team);

    expect($headsUp->status)->toBe('approved')
        ->and($headsUp->accounting_move_id)->toBeNull()
        ->and($headsUp->posted_to_accounting_at)->toBeNull()
        ->and($headsUp->payload)->toMatchArray(['expected_on' => '2026-11-24', 'estimated_amount' => '20000.00']);
});

it('turns an approved heads-up into a draft claim once, for its owner only', function () {
    $team = ehuTeam();
    $headsUp = ehuApproved($team);
    $service = app(ExpenseHeadsUpService::class);

    expect($service->canConvert($headsUp, $team['employee_user']))->toBeTrue()
        ->and($service->canConvert($headsUp, $team['manager_user']))->toBeFalse()
        ->and(fn () => $service->convertToClaim($headsUp, $team['manager_user'], $team['claim_type']->id, '21000', '2026-11-16', 'Travel'))->toThrow(RuntimeException::class, 'Only the employee');

    $claim = $service->convertToClaim($headsUp, $team['employee_user'], $team['claim_type']->id, '21000', '2026-11-16', 'Travel');

    expect($claim->status)->toBe('draft')
        ->and($claim->request_type_id)->toBe($team['claim_type']->id)
        ->and($claim->employee_id)->toBe($team['employee']->id)
        ->and((float) $claim->billed_amount)->toBe(21000.0)
        ->and($claim->nature_of_expense)->toBe('Travel')
        ->and($claim->payload)->toMatchArray(['spent_on' => '2026-11-16', 'heads_up_id' => $headsUp->id, 'heads_up_estimate' => '20000.00'])
        ->and(data_get($headsUp->fresh()->payload, 'claim_request_id'))->toBe($claim->id)
        ->and($service->canConvert($headsUp->fresh(['requestType', 'employee']), $team['employee_user']))->toBeFalse()
        ->and(fn () => $service->convertToClaim($headsUp->fresh(), $team['employee_user'], $team['claim_type']->id, '21000', '2026-11-16', 'Travel'))->toThrow(RuntimeException::class, 'only once');
});

it('only offers real claim types and refuses a future spend date', function () {
    $team = ehuTeam();
    EmployeeRequestType::query()->create([
        'company_id'            => $team['company']->id, 'code' => AdvanceKinds::AdvanceCode, 'name' => 'Business advance', 'category' => AdvanceKinds::AdvanceCategory,
        'approval_request_type' => AdvanceKinds::AdvanceCode, 'is_financial' => true, 'is_active' => true,
    ]);
    $headsUp = ehuApproved($team);
    $service = app(ExpenseHeadsUpService::class);

    expect(array_values($service->claimTypeOptions($headsUp)))->toBe(['Travel claim'])
        ->and(fn () => $service->convertToClaim($headsUp, $team['employee_user'], $team['claim_type']->id, '5000', '2026-11-20', null))->toThrow(RuntimeException::class, 'cannot be in the future');
});

it('flags a claim that is more than ten percent over the heads-up estimate', function () {
    $team = ehuTeam();
    $headsUp = ehuApproved($team, ['estimated_amount' => '10000']);
    $service = app(ExpenseHeadsUpService::class);

    $atLimit = $service->convertToClaim($headsUp, $team['employee_user'], $team['claim_type']->id, '11000', '2026-11-16', null);
    $over = $atLimit->replicate();
    $over->billed_amount = '12000';
    $over->save();

    expect($service->comparison($atLimit))->toMatchArray(['estimated' => '10000.00', 'actual' => '11000.00', 'over_by_percent' => 10.0, 'flagged' => false])
        ->and($service->comparison($over))->toMatchArray(['over_by_percent' => 20.0, 'flagged' => true])
        ->and($service->comparison(ehuDraft($team)))->toBeNull();
});

it('lets the owner cancel an approved heads-up that is not claimed', function () {
    $team = ehuTeam();
    $headsUp = ehuApproved($team);
    $service = app(ExpenseHeadsUpService::class);

    expect(fn () => $service->cancel($headsUp, $team['hr']))->toThrow(RuntimeException::class, 'Only the employee');

    $cancelled = $service->cancel($headsUp, $team['employee_user']);

    expect($cancelled->status)->toBe('withdrawn')
        ->and($service->canConvert($cancelled->fresh(['requestType', 'employee']), $team['employee_user']))->toBeFalse();
});

it('reminds the employee the day after the expected date, then weekly, once a day', function () {
    $team = ehuTeam();
    $headsUp = ehuApproved($team, ['expected_on' => '2026-11-20']);
    $service = app(ExpenseHeadsUpService::class);

    expect($service->sendReminders(null, '2026-11-20'))->toBe(['heads_ups' => 0, 'notified' => 0])
        ->and($service->sendReminders(null, '2026-11-21'))->toBe(['heads_ups' => 1, 'notified' => 1])
        ->and($service->sendReminders(null, '2026-11-21'))->toBe(['heads_ups' => 1, 'notified' => 0])
        ->and($service->sendReminders(null, '2026-11-24'))->toBe(['heads_ups' => 0, 'notified' => 0])
        ->and($service->sendReminders(null, '2026-11-28'))->toBe(['heads_ups' => 1, 'notified' => 1]);

    $service->convertToClaim($headsUp, $team['employee_user'], $team['claim_type']->id, '19000', '2026-11-16', null);

    expect($service->sendReminders(null, '2026-12-05'))->toBe(['heads_ups' => 0, 'notified' => 0]);
});

function ehuActAs(User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
}

it('shows the heads-up fields and no tax or bank fields on the new request form', function () {
    $team = ehuTeam();
    ehuActAs($team['employee_user']);

    Livewire::test(ManageEmployeeRequests::class)
        ->mountTableAction('submit_new')
        ->fillForm(['employee_id' => $team['employee']->id, 'request_type_id' => $team['heads_up_type']->id])
        ->assertFormFieldVisible('payload.expected_on')
        ->assertFormFieldVisible('payload.estimated_amount')
        ->assertFormFieldVisible('payload.what_for')
        ->assertFormFieldHidden('billed_amount')
        ->assertFormFieldHidden('account_title')
        ->assertFormFieldHidden('payload.spent_on');
});

it('asks for the date spent on a claim and not on a heads-up', function () {
    $team = ehuTeam();
    ehuActAs($team['employee_user']);

    Livewire::test(ManageEmployeeRequests::class)
        ->mountTableAction('submit_new')
        ->fillForm(['employee_id' => $team['employee']->id, 'request_type_id' => $team['claim_type']->id])
        ->assertFormFieldVisible('payload.spent_on')
        ->assertFormFieldExists('payload.spent_on', fn ($field): bool => $field->isRequired())
        ->assertFormFieldHidden('payload.expected_on');
});

it('offers Turn into claim only on an approved, unclaimed heads-up of the employee', function () {
    $team = ehuTeam();
    $headsUp = ehuApproved($team);

    ehuActAs($team['employee_user']);
    Livewire::test(ManageEmployeeRequests::class)
        ->assertTableActionVisible('convert_heads_up', $headsUp)
        ->assertTableActionVisible('cancel_heads_up', $headsUp)
        ->callTableAction('convert_heads_up', $headsUp, data: [
            'claim_type_id' => $team['claim_type']->id,
            'nature'        => 'Travel',
            'amount_spent'  => '21000',
            'spent_on'      => '2026-11-16',
        ])
        ->assertNotified('Draft claim created')
        ->assertTableActionHidden('convert_heads_up', $headsUp->fresh());

    expect(EmployeeRequest::query()->where('request_type_id', $team['claim_type']->id)->where('status', 'draft')->count())->toBe(1);

    ehuActAs($team['manager_user']);
    Livewire::test(ManageEmployeeRequests::class)
        ->assertTableActionHidden('convert_heads_up', $headsUp->fresh());
});
