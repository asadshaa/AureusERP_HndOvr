<?php

/**
 * HR parity plan 1.9: Loan, Salary advance and HR letter request types per
 * company, and the HR fallback approver fix (spec D5): the HR approver set is
 * the company's real HR people only -- never manager-role users, never HR of
 * another company, never a group owner just for being one.
 */

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Employee\Database\Seeders\SelfServiceRequestWorkflowSeeder;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

function selfServiceUser(Company $company, ?string $role = null): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role) {
        $user->assignRole(Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail());
    }

    return $user;
}

function selfServiceFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $hrUser = selfServiceUser($company, 'hr_manager');
    $managerUser = selfServiceUser($company, 'manager');
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Line Manager']);

    $employeeUser = selfServiceUser($company);
    $employee = Employee::query()->create([
        'company_id'   => $company->id, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id,
        'name'         => 'Sara Employee', 'job_title' => 'Dispatcher', 'joining_date' => '2024-03-01',
        'base_salary'  => '100000.0000',
    ]);

    $orphanUser = selfServiceUser($company);
    $orphan = Employee::query()->create(['company_id' => $company->id, 'user_id' => $orphanUser->id, 'name' => 'No Manager']);

    $otherHr = selfServiceUser($otherCompany, 'hr_manager');

    return compact('company', 'otherCompany', 'hrUser', 'managerUser', 'manager', 'employeeUser', 'employee', 'orphanUser', 'orphan', 'otherHr');
}

function selfServiceType(Company $company, string $code): EmployeeRequestType
{
    return EmployeeRequestType::query()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
}

/** @param array<string, mixed> $payload */
function selfServiceRequest(Company $company, Employee $employee, string $code, ?string $amount, array $payload): EmployeeRequest
{
    return EmployeeRequest::query()->create([
        'company_id'      => $company->id,
        'employee_id'     => $employee->id,
        'request_type_id' => selfServiceType($company, $code)->id,
        'requested_by'    => $employee->user_id,
        'amount'          => $amount,
        'payload'         => $payload,
    ]);
}

function stepRoutes(Company $company, string $requestType): array
{
    return ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', $requestType)->firstOrFail()
        ->steps->map(fn ($step): string => $step->hierarchy_route ?? 'role:'.strtolower((string) $step->approverRole?->name))->all();
}

// ---------------------------------------------------------------------
// Seeding
// ---------------------------------------------------------------------

it('seeds the three request types for every company idempotently and for a newly created company', function (): void {
    app(SelfServiceRequestWorkflowSeeder::class)->run();
    app(SelfServiceRequestWorkflowSeeder::class)->run();

    $codes = [EmployeeRequestKinds::LoanCode, EmployeeRequestKinds::SalaryAdvanceCode, EmployeeRequestKinds::HrLetterCode];

    Company::query()->each(function (Company $company) use ($codes): void {
        foreach ($codes as $code) {
            expect(EmployeeRequestType::query()->where('company_id', $company->id)->where('code', $code)->count())->toBe(1)
                ->and(ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', $code)->count())->toBe(1);
        }
    });

    $new = Company::factory()->create(['currency_id' => Currency::query()->where('code', 'PKR')->value('id'), 'is_active' => true]);

    foreach ($codes as $code) {
        $type = selfServiceType($new, $code);
        expect($type->is_active)->toBeTrue()
            ->and($type->is_financial)->toBeFalse()
            ->and($type->approval_request_type)->toBe($code);
    }

    expect(selfServiceType($new, EmployeeRequestKinds::SalaryAdvanceCode)->configuration)
        ->toBe([EmployeeRequestKinds::AdvanceSalaryPercentKey => '50']);
});

it('routes loan to line manager then HR then finance, advance to line manager then HR, and letters to HR only', function (): void {
    $f = selfServiceFixture();

    expect(stepRoutes($f['company'], EmployeeRequestKinds::LoanCode))->toBe(['requester_manager', 'hr'])
        ->and(stepRoutes($f['company'], EmployeeRequestKinds::SalaryAdvanceCode))->toBe(['requester_manager', 'hr'])
        ->and(stepRoutes($f['company'], EmployeeRequestKinds::HrLetterCode))->toBe(['hr']);

    // Once the company has a finance approver, re-provisioning adds the loan's finance step (only).
    selfServiceUser($f['company'], 'controller');
    app(SelfServiceRequestWorkflowSeeder::class)->provisionForCompany($f['company']);
    app(SelfServiceRequestWorkflowSeeder::class)->provisionForCompany($f['company']);

    expect(stepRoutes($f['company'], EmployeeRequestKinds::LoanCode))->toBe(['requester_manager', 'hr', 'role:controller'])
        ->and(stepRoutes($f['company'], EmployeeRequestKinds::SalaryAdvanceCode))->toBe(['requester_manager', 'hr']);
});

// ---------------------------------------------------------------------
// HR fallback approver fix (D5)
// ---------------------------------------------------------------------

it('keeps manager-role users, other companies HR and group owners out of the HR approver set', function (): void {
    $f = selfServiceFixture();
    $engine = app(ApprovalEngine::class);

    $directHr = selfServiceUser($f['company']);
    $directHr->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'hr_view_all_records', 'guard_name' => 'web']));

    $memberOwner = selfServiceUser($f['company']);
    $memberOwner->givePermissionTo(Permission::query()->firstOrCreate(['name' => CurrentCompany::MANAGE_MEMBERSHIPS, 'guard_name' => 'web']));

    $outsideOwner = selfServiceUser($f['otherCompany'], 'hr_manager');
    $outsideOwner->givePermissionTo(Permission::query()->firstOrCreate(['name' => CurrentCompany::MANAGE_MEMBERSHIPS, 'guard_name' => 'web']));
    expect(CurrentCompany::canAccess((int) $f['company']->id, $outsideOwner))->toBeTrue();

    $ids = $engine->resolveHrApproversForCompany((int) $f['company']->id)->pluck('id')->all();

    expect($f['managerUser']->can('hr_manage_employee_requests'))->toBeTrue()
        ->and($ids)->toContain($f['hrUser']->id, $directHr->id)
        ->and($ids)->not->toContain($f['managerUser']->id, $f['otherHr']->id, $memberOwner->id, $outsideOwner->id, $f['employeeUser']->id)
        ->and($engine->isCompanyHrApprover($f['managerUser'], (int) $f['company']->id))->toBeFalse()
        ->and($engine->isCompanyHrApprover($f['otherHr'], (int) $f['company']->id))->toBeFalse()
        ->and($engine->resolveHrApproversForCompany((int) $f['company']->id, $f['hrUser']->id)->pluck('id'))->not->toContain($f['hrUser']->id);
});

it('lets only the company HR decide the fallback step of an employee without a line manager', function (): void {
    $f = selfServiceFixture();
    $engine = app(ApprovalEngine::class);

    $request = selfServiceRequest($f['company'], $f['orphan'], EmployeeRequestKinds::SalaryAdvanceCode, '1000', [
        'reason' => 'Rent', 'deduct_month' => now()->format('Y-m'),
    ]);
    $approval = app(EmployeeRequestService::class)->submit($request, $f['orphanUser']);

    expect($engine->canAct($approval, $f['managerUser']))->toBeFalse()
        ->and($engine->canAct($approval, $f['otherHr']))->toBeFalse()
        ->and($engine->canAct($approval, $f['orphanUser']))->toBeFalse()
        ->and($engine->canAct($approval, $f['hrUser']))->toBeTrue()
        ->and($engine->resolveAuthorizedApprovers($approval)->pluck('id')->all())->toBe([$f['hrUser']->id])
        ->and(fn () => $engine->approve($approval, $f['managerUser']))->toThrow(RuntimeException::class, 'not an approver');

    // The leave workflow's line-manager step uses the same fallback.
    if (! $engine->matchingWorkflow((int) $f['company']->id, 'leave_request')) {
        ApprovalWorkflow::query()
            ->create(['company_id' => $f['company']->id, 'request_type' => 'leave_request', 'name' => 'Leave', 'is_active' => true])
            ->steps()->create(['sequence' => 1, 'name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);
    }
    expect($engine->matchingWorkflow((int) $f['company']->id, 'leave_request')->steps->first()->hierarchy_route)->toBe('requester_manager');

    $leaveSubject = selfServiceRequest($f['company'], $f['orphan'], EmployeeRequestKinds::HrLetterCode, null, []);
    $leaveApproval = $engine->submit($leaveSubject, $f['orphanUser'], 'leave_request', null, ['company_id' => $f['company']->id]);

    expect($f['managerUser']->can('hr_approve_leave'))->toBeTrue()
        ->and($engine->canAct($leaveApproval, $f['managerUser']))->toBeFalse()
        ->and($engine->canAct($leaveApproval, $f['hrUser']))->toBeTrue();
});

// ---------------------------------------------------------------------
// Loan
// ---------------------------------------------------------------------

it('validates loan instalments and start month', function (int|string|null $instalments, ?string $startMonth, string $message): void {
    $f = selfServiceFixture();
    $request = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::LoanCode, '60000', [
        'purpose' => 'Medical', 'instalments' => $instalments, 'start_month' => $startMonth,
    ]);

    expect(fn () => app(EmployeeRequestService::class)->submit($request, $f['employeeUser']))->toThrow(RuntimeException::class, $message);
    expect($request->fresh()->status)->toBe('draft');
})->with([
    'zero instalments'  => [0, null, 'between 1 and 24'],
    'too many'          => [25, null, 'between 1 and 24'],
    'not a number'      => ['six', null, 'between 1 and 24'],
    'past start month'  => [6, '2020-01', 'this month or later'],
    'missing month'     => [6, null, 'this month or later'],
]);

it('approves a loan through line manager, HR and finance and records its instalment schedule', function (): void {
    $f = selfServiceFixture();
    $finance = selfServiceUser($f['company'], 'controller');
    app(SelfServiceRequestWorkflowSeeder::class)->provisionForCompany($f['company']);
    $service = app(EmployeeRequestService::class);
    $start = now()->addMonth()->format('Y-m');

    $request = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::LoanCode, '100000', [
        'purpose' => 'House repair', 'instalments' => '3', 'start_month' => $start,
    ]);
    $approval = $service->submit($request, $f['employeeUser']);

    $schedule = $request->fresh()->payload['schedule'];
    expect($schedule)->toHaveCount(3)
        ->and(array_column($schedule, 'amount'))->toBe(['33333.33', '33333.33', '33333.34'])
        ->and($schedule[0]['month'])->toBe($start)
        ->and($request->fresh()->currency_id)->toBe($f['company']->currency_id)
        ->and(app(ApprovalEngine::class)->canAct($approval, $f['hrUser']))->toBeFalse()
        ->and(app(ApprovalEngine::class)->canAct($approval, $f['managerUser']))->toBeTrue();

    $service->approve($request->fresh(), $f['managerUser']);
    expect($request->fresh()->status)->toBe('pending_approval')
        ->and(app(ApprovalEngine::class)->canAct($request->fresh()->approvalRequest, $f['hrUser']))->toBeTrue();

    $service->approve($request->fresh(), $f['hrUser']);
    expect($request->fresh()->status)->toBe('pending_approval')
        ->and(app(ApprovalEngine::class)->canAct($request->fresh()->approvalRequest, $finance))->toBeTrue();

    $service->approve($request->fresh(), $finance);
    $request = $request->fresh(['approvalRequest.decisions']);

    expect($request->status)->toBe('approved')
        ->and($request->payload['recorded_at'])->not->toBeNull()
        ->and($request->payload['schedule'])->toHaveCount(3)
        ->and($request->accounting_move_id)->toBeNull()
        ->and($request->approvalRequest->decisions->pluck('actor_id')->all())->toBe([$f['managerUser']->id, $f['hrUser']->id, $finance->id]);
});

// ---------------------------------------------------------------------
// Salary advance
// ---------------------------------------------------------------------

it('caps a salary advance at the configured share of the monthly salary', function (): void {
    $f = selfServiceFixture();
    $service = app(EmployeeRequestService::class);
    $month = now()->format('Y-m');

    $tooMuch = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::SalaryAdvanceCode, '50000.01', ['reason' => 'School fees', 'deduct_month' => $month]);
    expect(fn () => $service->submit($tooMuch, $f['employeeUser']))->toThrow(RuntimeException::class, 'cannot exceed 50% of the monthly salary');

    $ok = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::SalaryAdvanceCode, '50000', ['reason' => 'School fees', 'deduct_month' => $month]);
    $service->submit($ok, $f['employeeUser']);
    expect($ok->fresh()->status)->toBe('pending_approval')
        ->and($ok->fresh()->payload['cap_amount'])->toBe('50000.0000');

    selfServiceType($f['company'], EmployeeRequestKinds::SalaryAdvanceCode)->update(['configuration' => [EmployeeRequestKinds::AdvanceSalaryPercentKey => '30']]);
    $configured = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::SalaryAdvanceCode, '40000', ['reason' => 'Fees', 'deduct_month' => $month]);
    expect(fn () => $service->submit($configured, $f['employeeUser']))->toThrow(RuntimeException::class, 'cannot exceed 30%');

    // No salary on file: no cap, but the reason and month are still required.
    $noSalary = selfServiceRequest($f['company'], $f['orphan'], EmployeeRequestKinds::SalaryAdvanceCode, '900000', ['deduct_month' => $month]);
    expect(fn () => $service->submit($noSalary, $f['orphanUser']))->toThrow(RuntimeException::class, 'reason');
    $noSalary->update(['payload' => ['reason' => 'Emergency', 'deduct_month' => $month]]);
    $service->submit($noSalary->fresh(), $f['orphanUser']);
    expect($noSalary->fresh()->status)->toBe('pending_approval');

    $service->approve($ok->fresh(), $f['managerUser']);
    $service->approve($ok->fresh(), $f['hrUser']);
    expect($ok->fresh()->status)->toBe('approved')
        ->and($ok->fresh()->payload['recorded_at'])->not->toBeNull();
});

// ---------------------------------------------------------------------
// HR letter
// ---------------------------------------------------------------------

it('lets HR approve a letter request, generate the PDF, and serve it through a signed download', function (): void {
    Storage::fake('local');
    $f = selfServiceFixture();
    $service = app(EmployeeRequestService::class);

    $request = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::HrLetterCode, null, [
        'letter_type'    => EmployeeRequestKinds::SalaryCertificate, 'addressed_to' => 'Meezan Bank',
        'letter_purpose' => 'Car financing', 'needed_by' => now()->addWeek()->toDateString(),
    ]);
    $approval = $service->submit($request, $f['employeeUser']);

    expect(app(ApprovalEngine::class)->canAct($approval, $f['managerUser']))->toBeFalse()
        ->and(app(ApprovalEngine::class)->canAct($approval, $f['otherHr']))->toBeFalse()
        ->and(fn () => $service->generateLetterPdf($request->fresh(), $f['hrUser']))->toThrow(RuntimeException::class, 'Approve the letter request');

    $service->approve($request->fresh(), $f['hrUser']);
    expect(fn () => $service->generateLetterPdf($request->fresh(), $f['otherHr']))->toThrow(RuntimeException::class, 'Only HR of this company')
        ->and(fn () => $service->generateLetterPdf($request->fresh(), $f['managerUser']))->toThrow(RuntimeException::class, 'Only HR of this company');

    $issued = $service->generateLetterPdf($request->fresh(), $f['hrUser'], includeSalary: true);
    $path = $issued->payload['letter_path'];

    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(str_starts_with(Storage::disk('local')->get($path), '%PDF'))->toBeTrue()
        ->and($issued->payload['letter_includes_salary'])->toBeTrue()
        ->and($issued->payload['letter_issued_by'])->toBe($f['hrUser']->id);

    $url = $service->letterDownloadUrl($issued);

    $this->actingAs($f['employeeUser'])->get($url)->assertOk()->assertDownload();
    $this->actingAs($f['hrUser'])->get($url)->assertOk();
    $this->actingAs($f['managerUser'])->get($url)->assertForbidden();
    $this->actingAs($f['otherHr'])->get($url)->assertForbidden();
    $this->actingAs($f['employeeUser'])->get(route('employees.requests.letter', ['employeeRequest' => $issued->id]))->assertForbidden();
    $this->actingAs($f['employeeUser'])->get(URL::temporarySignedRoute('employees.requests.letter', now()->subMinute(), ['employeeRequest' => $issued->id]))->assertForbidden();
});

it('accepts an uploaded letter file from HR and validates the letter request fields', function (): void {
    Storage::fake('local');
    $f = selfServiceFixture();
    $service = app(EmployeeRequestService::class);

    $invalid = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::HrLetterCode, null, ['letter_type' => 'poem', 'addressed_to' => 'X', 'letter_purpose' => 'Y', 'needed_by' => now()->toDateString()]);
    expect(fn () => $service->submit($invalid, $f['employeeUser']))->toThrow(RuntimeException::class, 'type of letter');

    $request = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::HrLetterCode, null, ['letter_type' => 'noc', 'addressed_to' => 'Embassy', 'letter_purpose' => 'Visa', 'needed_by' => now()->toDateString()]);
    $service->submit($request, $f['employeeUser']);
    $service->approve($request->fresh(), $f['hrUser']);

    Storage::disk('local')->put('employees/requests/letters/manual-noc.pdf', '%PDF-1.4 test');
    $issued = $service->issueLetter($request->fresh(), $f['hrUser'], 'employees/requests/letters/manual-noc.pdf');

    expect($issued->payload['letter_path'])->toBe('employees/requests/letters/manual-noc.pdf')
        ->and($service->canDownloadLetter($f['employeeUser'], $issued))->toBeTrue()
        ->and($service->canDownloadLetter($f['orphanUser'], $issued))->toBeFalse();
});

// ---------------------------------------------------------------------
// Self-service screen and isolation
// ---------------------------------------------------------------------

it('shows employees their own requests with status on the Employee Requests screen, and nobody else\'s', function (): void {
    $f = selfServiceFixture();
    $month = now()->format('Y-m');
    $own = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::SalaryAdvanceCode, '1000', ['reason' => 'Fees', 'deduct_month' => $month]);
    app(EmployeeRequestService::class)->submit($own, $f['employeeUser']);
    $colleague = selfServiceRequest($f['company'], $f['orphan'], EmployeeRequestKinds::SalaryAdvanceCode, '1000', ['reason' => 'Fees', 'deduct_month' => $month]);

    Auth::login($f['employeeUser']);

    expect(EmployeeRequestResource::getEloquentQuery()->pluck('id')->all())->toContain($own->id)->not->toContain($colleague->id);

    Livewire::test(ManageEmployeeRequests::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$own->fresh()])
        ->assertCanNotSeeTableRecords([$colleague])
        ->assertSee('pending_approval');
});

it('submits a loan from the Employee Requests form with the company currency', function (): void {
    $f = selfServiceFixture();
    Auth::login($f['employeeUser']);
    $start = now()->addMonth()->format('Y-m');

    Livewire::test(ManageEmployeeRequests::class)
        ->callTableAction('submit_new', data: [
            'employee_id'     => $f['employee']->id,
            'request_type_id' => selfServiceType($f['company'], EmployeeRequestKinds::LoanCode)->id,
            'amount'          => '24000',
            'payload'         => ['purpose' => 'Wedding', 'instalments' => '12', 'start_month' => $start],
        ])
        ->assertHasNoTableActionErrors();

    $request = EmployeeRequest::query()->where('employee_id', $f['employee']->id)->latest('id')->firstOrFail();

    expect($request->status)->toBe('pending_approval')
        ->and((string) $request->amount)->toBe('24000.0000')
        ->and($request->currency_id)->toBe($f['company']->currency_id)
        ->and($request->payload['schedule'])->toHaveCount(12)
        ->and($request->payload['schedule'][0]['amount'])->toBe('2000.00');

    Livewire::test(ManageEmployeeRequests::class)
        ->callTableAction('submit_new', data: [
            'employee_id'     => $f['employee']->id,
            'request_type_id' => selfServiceType($f['company'], EmployeeRequestKinds::LoanCode)->id,
            'amount'          => '24000',
            'payload'         => ['purpose' => 'Wedding', 'instalments' => '30', 'start_month' => $start],
        ])
        ->assertHasTableActionErrors(['payload.instalments']);
});

it('refuses a request type of another company', function (): void {
    $f = selfServiceFixture();
    $request = EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'request_type_id' => selfServiceType($f['otherCompany'], EmployeeRequestKinds::HrLetterCode)->id,
        'payload'         => ['letter_type' => 'noc', 'addressed_to' => 'X', 'letter_purpose' => 'Y', 'needed_by' => now()->toDateString()],
    ]);

    expect(fn () => app(EmployeeRequestService::class)->submit($request, $f['employeeUser']))
        ->toThrow(RuntimeException::class, 'same company');
});

it('shows the employee the loan schedule and the approver trail on the request details', function (): void {
    $f = selfServiceFixture();
    $request = selfServiceRequest($f['company'], $f['employee'], EmployeeRequestKinds::LoanCode, '3000', [
        'purpose' => 'Bike', 'instalments' => '3', 'start_month' => now()->format('Y-m'),
    ]);
    app(EmployeeRequestService::class)->submit($request, $f['employeeUser']);
    app(EmployeeRequestService::class)->approve($request->fresh(), $f['managerUser'], 'Fine by me');

    Auth::login($f['employeeUser']);

    Livewire::test(ManageEmployeeRequests::class)
        ->mountTableAction('view', $request->fresh())
        ->assertHasNoErrors()
        ->assertOk();

    $record = EmployeeRequestResource::getEloquentQuery()->findOrFail($request->id);

    expect(EmployeeRequestResource::instalmentScheduleHtml($record))->toContain('1,000.00', now()->format('F Y'))
        ->and(EmployeeRequestResource::approvalTrailHtml($record))->toContain($f['managerUser']->name, 'Fine by me', 'Waiting: HR Review');
});
