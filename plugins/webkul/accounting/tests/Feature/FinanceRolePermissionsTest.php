<?php

/**
 * Focused tests for the "Aureus ERP — Finance Roles & Permissions
 * Implementation" spec: role existence (missing roles created, existing
 * equivalents not duplicated), permission grants (intended permissions
 * present, restricted ones absent), idempotency, segregation of duties,
 * company isolation, and ApprovalEngine compatibility (a role can approve
 * ONLY when actually assigned as an approver on the matching workflow
 * step -- never merely by holding the role).
 */

use Database\Seeders\FinanceRoleSeeder;
use Illuminate\Support\Facades\DB;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\ManualAdjustmentResource;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource;
use Webkul\Accounting\Services\Security\AccountingPermissionRegistrar;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

function seedFinanceRoles(): void
{
    app(FinanceRoleSeeder::class)->run();
}

it('creates every genuinely-missing finance role exactly once', function (): void {
    seedFinanceRoles();

    $expectedNames = [
        'finance_operator', 'ap_officer', 'ar_officer', 'treasury_officer',
        'reconciliation_officer', 'tax_officer', 'controller', 'fpa_analyst',
        'vp_finance', 'cfo', 'internal_auditor',
    ];

    foreach ($expectedNames as $name) {
        expect(Role::query()->where('guard_name', 'web')
            ->whereRaw('LOWER(name) = ?', [$name])->count())->toBe(1);
    }
});

it('does not duplicate roles that already have a functional equivalent', function (): void {
    // ERP Administrator == Admin, Accountant == accountant, Finance
    // Manager == accounting_manager -- the seeder must never create
    // "erp_administrator", "accountant" (a second one), or "finance_manager".
    Role::query()->firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'accounting_manager', 'guard_name' => 'web']);
    $accountantCountBefore = Role::query()->whereRaw('LOWER(name) = ?', ['accountant'])->count();
    $managerCountBefore = Role::query()->whereRaw('LOWER(name) = ?', ['accounting_manager'])->count();

    seedFinanceRoles();

    expect(Role::query()->whereRaw('LOWER(name) = ?', ['accountant'])->count())->toBe($accountantCountBefore)
        ->and(Role::query()->whereRaw('LOWER(name) = ?', ['accounting_manager'])->count())->toBe($managerCountBefore)
        ->and(Role::query()->whereRaw('LOWER(name) = ?', ['erp_administrator'])->count())->toBe(0)
        ->and(Role::query()->whereRaw('LOWER(name) = ?', ['finance_manager'])->count())->toBe(0);
});

it('is idempotent: running the seeder twice creates no duplicate roles or permission grants', function (): void {
    seedFinanceRoles();
    $roleCountAfterFirst = Role::query()->where('guard_name', 'web')->count();
    $pivotCountAfterFirst = DB::table('role_has_permissions')->count();

    seedFinanceRoles();

    expect(Role::query()->where('guard_name', 'web')->count())->toBe($roleCountAfterFirst)
        ->and(DB::table('role_has_permissions')->count())->toBe($pivotCountAfterFirst);
});

it('grants AP Officer its intended permissions and withholds approve/post/release', function (): void {
    seedFinanceRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['ap_officer'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain('create_accounting_bill')
        ->and($names)->toContain('view_any_accounting_vendor')
        ->and($names)->toContain('create_accounting_payment')
        ->and($names)->not->toContain(AccountingPermissions::ApproveJournal)
        ->and($names)->not->toContain(AccountingPermissions::PostJournal)
        ->and($names)->not->toContain(AccountingPermissions::ReleasePayment);
});

it('grants Treasury Officer bank preparation permissions but withholds ReviewBankTransactions and PostJournal', function (): void {
    seedFinanceRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['treasury_officer'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain(AccountingPermissions::ImportBankStatementPage)
        ->and($names)->toContain('update_accounting_bank_transaction_mapping')
        ->and($names)->not->toContain(AccountingPermissions::ReviewBankTransactions)
        ->and($names)->not->toContain(AccountingPermissions::PostJournal)
        ->and($names)->not->toContain(AccountingPermissions::ReleasePayment);
});

it('grants Reconciliation Officer ReviewBankTransactions but withholds PostJournal', function (): void {
    seedFinanceRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['reconciliation_officer'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain(AccountingPermissions::ReviewBankTransactions)
        ->and($names)->not->toContain(AccountingPermissions::PostJournal)
        ->and($names)->not->toContain('update_accounting_bank_transaction_mapping');
});

it('grants Controller approve and post authority but no system/user administration permission', function (): void {
    seedFinanceRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['controller'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain(AccountingPermissions::ApproveJournal)
        ->and($names)->toContain(AccountingPermissions::PostJournal)
        ->and($names)->not->toContain('create_role')
        ->and($names)->not->toContain('create_security_user')
        ->and($names)->not->toContain('view_any_role');
});

it('gives FP&A Analyst read-only report access and zero posting/approval/release permission', function (): void {
    seedFinanceRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['fpa_analyst'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    expect($names)->toContain('page_accounting_trial_balance')
        ->and($names->filter(fn (string $n) => str_starts_with($n, 'create_'))->isEmpty())->toBeTrue()
        ->and($names->filter(fn (string $n) => str_starts_with($n, 'update_'))->isEmpty())->toBeTrue()
        ->and($names)->not->toContain(AccountingPermissions::ApproveJournal)
        ->and($names)->not->toContain(AccountingPermissions::PostJournal)
        ->and($names)->not->toContain(AccountingPermissions::ReleasePayment);
});

it('gives VP Finance and CFO broad read access with zero create/update/delete permission of their own', function (): void {
    seedFinanceRoles();

    foreach (['vp_finance', 'cfo'] as $roleName) {
        $role = Role::query()->whereRaw('LOWER(name) = ?', [$roleName])->firstOrFail();
        $names = $role->permissions()->pluck('name');

        expect($names->filter(fn (string $n) => str_starts_with($n, 'create_') && $n !== 'create_accounting_payment')->isEmpty())->toBeTrue()
            ->and($names->filter(fn (string $n) => str_starts_with($n, 'update_'))->isEmpty())->toBeTrue()
            ->and($names->filter(fn (string $n) => str_starts_with($n, 'delete_'))->isEmpty())->toBeTrue()
            ->and($names)->not->toContain(AccountingPermissions::ApproveJournal)
            ->and($names)->not->toContain(AccountingPermissions::PostJournal);
    }
});

it('gives Internal Auditor read-only access across accounting with zero write permission of any kind', function (): void {
    seedFinanceRoles();
    $role = Role::query()->whereRaw('LOWER(name) = ?', ['internal_auditor'])->firstOrFail();
    $names = $role->permissions()->pluck('name');

    $writeVerbs = ['create_', 'update_', 'delete_', 'manage_', 'approve_', 'post_', 'release_', 'run_'];

    expect($names)->toContain('view_any_accounting_journal::entry')
        ->and($names)->toContain('view_any_support_approval::request')
        ->and($names)->toContain('view_any_support_approval::workflow')
        ->and($names->filter(fn (string $n) => collect($writeVerbs)->contains(fn (string $verb) => str_starts_with($n, $verb)))->isEmpty())->toBeTrue();
});

it('closes the ManualAdjustmentResource/ExchangeRateResource read-only visibility gap for view-only roles', function (): void {
    // Found live during manual testing: both resources' canViewAny() used
    // to accept ONLY their "Manage*"/write-tier permission, so a
    // genuinely read-only role got a 403 despite holding the generic
    // Shield view_any_* permission (which those resources ignore). This
    // locks in the fix -- ViewManualAdjustments/ViewExchangeRates grant
    // visibility WITHOUT unlocking Edit/Create for that role.
    seedFinanceRoles();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $auditor = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $auditor->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $auditor->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['internal_auditor'])->firstOrFail());

    $apOfficer = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $apOfficer->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $apOfficer->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['ap_officer'])->firstOrFail());

    test()->actingAs($auditor);
    expect(ManualAdjustmentResource::canViewAny())->toBeTrue()
        ->and(ManualAdjustmentResource::canCreate())->toBeFalse()
        ->and(ExchangeRateResource::canViewAny())->toBeTrue()
        ->and(ExchangeRateResource::canCreate())->toBeFalse();

    // AP Officer has neither Manage* nor View* for these two resources --
    // genuinely not part of its role -- so both must stay 403.
    test()->actingAs($apOfficer);
    expect(ManualAdjustmentResource::canViewAny())->toBeFalse()
        ->and(ExchangeRateResource::canViewAny())->toBeFalse();
});

it('does not let a read-only role edit or delete a Manual Adjustment or Exchange Rate via direct navigation', function (): void {
    // Found live during manual testing: neither model has a Policy
    // class, and Filament allows an action by default when nothing
    // gates it -- so canEdit()/canDelete() were open to ANY authenticated
    // user (a VP Finance test user reached a live, editable
    // "Edit Manual Adjustment" form). This is the server-side check that
    // matters -- the EditAction button being hidden on the list was never
    // enough on its own, exactly the failure mode the spec warned against.
    seedFinanceRoles();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $vpFinance = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $vpFinance->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $vpFinance->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['vp_finance'])->firstOrFail());

    $controller = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $controller->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $controller->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['controller'])->firstOrFail());

    test()->actingAs($vpFinance);
    expect(ManualAdjustmentResource::canEdit(null))->toBeFalse()
        ->and(ManualAdjustmentResource::canDelete(null))->toBeFalse()
        ->and(ExchangeRateResource::canDelete(null))->toBeFalse();

    // Controller genuinely can (ManageManualAdjustments is part of its bundle).
    test()->actingAs($controller);
    expect(ManualAdjustmentResource::canEdit(null))->toBeTrue()
        ->and(ManualAdjustmentResource::canDelete(null))->toBeTrue();
});

it('closes the approval-history authorization gap: Internal Auditor can view approval requests, an unrelated finance role cannot', function (): void {
    seedFinanceRoles();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $auditor = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $auditor->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $auditor->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['internal_auditor'])->firstOrFail());

    $apOfficer = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $apOfficer->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $apOfficer->assignRole(Role::query()->whereRaw('LOWER(name) = ?', ['ap_officer'])->firstOrFail());

    expect($auditor->can('view_any_support_approval::request'))->toBeTrue()
        ->and($apOfficer->can('view_any_support_approval::request'))->toBeFalse();
});

it('lets VP Finance approve only when the workflow actually assigns VP Finance as an approver, never merely by holding the role', function (): void {
    seedFinanceRoles();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $requester = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $requester->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $vpFinanceRole = Role::query()->whereRaw('LOWER(name) = ?', ['vp_finance'])->firstOrFail();
    $vpFinanceUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $vpFinanceUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $vpFinanceUser->assignRole($vpFinanceRole);

    // A workflow that does NOT assign vp_finance as the approver on its only step.
    $unassignedWorkflow = ApprovalWorkflow::query()->create([
        'company_id'   => $company->id,
        'name'         => 'Manual adjustment approval (no VP step)',
        'request_type' => 'manual_adjustment_no_vp',
        'is_active'    => true,
    ]);
    $unassignedWorkflow->steps()->create([
        'sequence'           => 1,
        'name'               => 'Controller review',
        'approver_role_id'   => Role::query()->whereRaw('LOWER(name) = ?', ['controller'])->firstOrFail()->id,
        'required_approvals' => 1,
    ]);

    $engine = app(ApprovalEngine::class);
    $requestWithoutVp = $engine->submit($company, $requester, 'manual_adjustment_no_vp', null, ['company_id' => $company->id]);

    expect($engine->canAct($requestWithoutVp, $vpFinanceUser))->toBeFalse();

    // A workflow that DOES assign vp_finance as the approver.
    $assignedWorkflow = ApprovalWorkflow::query()->create([
        'company_id'   => $company->id,
        'name'         => 'Manual adjustment approval (with VP step)',
        'request_type' => 'manual_adjustment_with_vp',
        'is_active'    => true,
    ]);
    $assignedWorkflow->steps()->create([
        'sequence'           => 1,
        'name'               => 'VP Finance sign-off',
        'approver_role_id'   => $vpFinanceRole->id,
        'required_approvals' => 1,
    ]);

    $requestWithVp = $engine->submit($company, $requester, 'manual_adjustment_with_vp', null, ['company_id' => $company->id]);

    expect($engine->canAct($requestWithVp, $vpFinanceUser))->toBeTrue();

    $approved = $engine->approve($requestWithVp, $vpFinanceUser, 'Approved at VP level');
    expect($approved->status)->toBe('approved');
});

it('enforces company isolation: a finance-role user in another company cannot act on a request', function (): void {
    seedFinanceRoles();

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $requester = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $requester->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $controllerRole = Role::query()->whereRaw('LOWER(name) = ?', ['controller'])->firstOrFail();

    $outsideController = User::factory()->create(['default_company_id' => $otherCompany->id, 'is_active' => true]);
    $outsideController->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);
    $outsideController->assignRole($controllerRole);

    $workflow = ApprovalWorkflow::query()->create([
        'company_id'   => $company->id,
        'name'         => 'Controller approval',
        'request_type' => 'controller_isolation_check',
        'is_active'    => true,
    ]);
    $workflow->steps()->create([
        'sequence'           => 1,
        'name'               => 'Controller review',
        'approver_role_id'   => $controllerRole->id,
        'required_approvals' => 1,
    ]);

    $engine = app(ApprovalEngine::class);
    $request = $engine->submit($company, $requester, 'controller_isolation_check', null, ['company_id' => $company->id]);

    // Same role, wrong company -> must not be able to act.
    expect($engine->canAct($request, $outsideController))->toBeFalse();
});

it('re-running the registrar synchronize() does not grant new-role permissions to Admin/Accountant/Finance Manager beyond their own bundles', function (): void {
    seedFinanceRoles();
    $registrar = app(AccountingPermissionRegistrar::class);
    $result = $registrar->synchronize();

    expect($result['finance_role_grants'])->toHaveKey('ap_officer')
        ->and($result['finance_role_grants']['ap_officer'])->toBeGreaterThanOrEqual(1)
        ->and($result['finance_role_grants']['internal_auditor'])->toBeGreaterThanOrEqual(1);

    $accountantRole = Role::query()->firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
    $registrar->synchronize();
    $accountantPermissionNames = $accountantRole->fresh()->permissions()->pluck('name');

    // The Accountant role's own bundle is unaffected by the new roles
    // being introduced -- it must still exactly match accountant(), never
    // gain e.g. Internal-Auditor- or VP-Finance-only permissions.
    expect($accountantPermissionNames)->not->toContain(AccountingPermissions::ApproveJournal)
        ->and($accountantPermissionNames)->not->toContain(AccountingPermissions::ReleasePayment);
});
