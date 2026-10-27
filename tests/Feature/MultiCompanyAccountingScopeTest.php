<?php

/**
 * Multi-company isolation for accounting configuration and move forms
 * (docs/MULTI_COMPANY_ACCESS_PLAN.md §2.4 rows 13-17): a user only imports
 * into, lists and picks companies they belong to, and the active company's
 * records are the only ones they see.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Account\Models\FiscalPosition;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Accounting\Filament\Clusters\Configuration\Pages\ImportChartOfAccounts;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CoaImportHistoryResource;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\FiscalPositionResource;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\PaymentTermResource;
use Webkul\Accounting\Filament\Clusters\Customers\Resources\InvoiceResource\Pages\CreateInvoice;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource;
use Webkul\Accounting\Models\CoaImportBatch;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar as WebkulPermissionRegistrar;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/** A signed-in user whose active company is $company, who also belongs to $alsoIn. */
function acctMember(Company $company, array $alsoIn = [], string ...$permissions): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $user->allowedCompanies()->sync(collect($alsoIn)->pluck('id')->all());

    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(WebkulPermissionRegistrar::class)->forgetCachedPermissions();
    CurrentCompany::flush();

    $user->refresh();
    Auth::login($user);

    return $user;
}

function acctImportBatch(Company $company): CoaImportBatch
{
    return CoaImportBatch::query()->create(['company_id' => $company->id, 'filename' => 'coa-'.Str::random(5).'.csv', 'status' => 'imported']);
}

beforeEach(function (): void {
    DB::table('plugins')->updateOrInsert(['name' => 'accounting'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    Filament::setCurrentPanel('admin');

    $this->shipLine = Company::factory()->create(['name' => 'ShipLine '.Str::random(4), 'is_active' => true]);
    $this->openPort = Company::factory()->create(['name' => 'OpenPort '.Str::random(4), 'is_active' => true]);
});

it('refuses a chart of accounts import into a company the user does not belong to', function () {
    acctMember($this->shipLine, [], 'page_accounting_import_chart_of_accounts');
    $this->mock(CoaImportService::class)->shouldNotReceive('import');

    $page = Livewire::test(ImportChartOfAccounts::class)
        ->assertSet('data.company_id', $this->shipLine->id)
        ->set('data.company_id', $this->openPort->id)
        ->call('import')
        ->assertNotified('Import refused');

    expect(CoaImportBatch::query()->where('company_id', $this->openPort->id)->exists())->toBeFalse()
        ->and(array_keys($page->instance()->form->getComponent('company_id')->getOptions()))->toBe([$this->shipLine->id]);
});

it('lists only the active company\'s chart of accounts import history', function () {
    $ours = acctImportBatch($this->shipLine);
    $theirs = acctImportBatch($this->openPort);
    acctMember($this->shipLine, [$this->openPort]);

    expect(CoaImportHistoryResource::getEloquentQuery()->pluck('id')->all())->toContain($ours->id)->not->toContain($theirs->id);
});

it('lists only the active company\'s fiscal positions', function () {
    $ours = FiscalPosition::query()->create(['company_id' => $this->shipLine->id, 'name' => 'Ours '.Str::random(4)]);
    $theirs = FiscalPosition::query()->create(['company_id' => $this->openPort->id, 'name' => 'Theirs '.Str::random(4)]);
    acctMember($this->shipLine);

    expect(FiscalPositionResource::getEloquentQuery()->pluck('id')->all())->toContain($ours->id)->not->toContain($theirs->id);
});

it('lists the active company\'s and shared payment terms, and only a group owner changes shared ones', function () {
    $ours = PaymentTerm::query()->create(['company_id' => $this->shipLine->id, 'name' => 'Ours '.Str::random(4)]);
    $shared = PaymentTerm::query()->create(['company_id' => null, 'name' => 'Shared '.Str::random(4)]);
    $theirs = PaymentTerm::query()->create(['company_id' => $this->openPort->id, 'name' => 'Theirs '.Str::random(4)]);
    acctMember($this->shipLine);

    expect(PaymentTermResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($ours->id, $shared->id)
        ->not->toContain($theirs->id);

    $companyAllowsChange = new ReflectionMethod(PaymentTermResource::class, 'companyAllowsChange');
    expect($companyAllowsChange->invoke(null, $ours))->toBeTrue()
        ->and($companyAllowsChange->invoke(null, $shared))->toBeFalse();
});

it('lists the active company\'s and shared report templates only', function () {
    $ours = ReportTemplate::factory()->create(['company_id' => $this->shipLine->id]);
    $shared = ReportTemplate::factory()->create(['company_id' => null]);
    $theirs = ReportTemplate::factory()->create(['company_id' => $this->openPort->id]);
    acctMember($this->shipLine);

    expect(ReportTemplateResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($ours->id, $shared->id)
        ->not->toContain($theirs->id);
});

it('rejects an invoice company the user cannot use, and lets a group owner pick any of their companies', function () {
    $permissions = ['view_any_accounting_invoice', 'create_accounting_invoice'];

    acctMember($this->shipLine, [$this->openPort], ...$permissions);

    Livewire::test(CreateInvoice::class)
        ->assertSchemaStateSet(['company_id' => $this->shipLine->id])
        ->fillForm(['company_id' => $this->openPort->id])
        ->call('create')
        ->assertHasFormErrors(['company_id']);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['company_id' => $this->shipLine->id])
        ->call('create')
        ->assertHasNoFormErrors(['company_id']);

    acctMember($this->shipLine, [$this->openPort], CurrentCompany::MANAGE_MEMBERSHIPS, ...$permissions);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['company_id' => $this->openPort->id])
        ->call('create')
        ->assertHasNoFormErrors(['company_id']);
});
