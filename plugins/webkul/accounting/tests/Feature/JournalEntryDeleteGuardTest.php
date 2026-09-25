<?php

use Filament\Facades\Filament;
use Livewire\Livewire;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\JournalEntryResource\Pages\ListJournalEntries;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;

/**
 * Regression test for the "posted journal entries can be permanently
 * deleted" defect: a posted Move must never be removable via the row or
 * bulk Delete action. Correcting a posted entry has to go through Reverse.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $company = Company::factory()->create(['is_active' => true]);
    $this->user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $this->user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    foreach (['delete_accounting_journal::entry', 'delete_any_accounting_journal::entry', 'view_any_accounting_journal::entry', 'view_accounting_journal::entry'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->user->givePermissionTo(['delete_accounting_journal::entry', 'delete_any_accounting_journal::entry', 'view_any_accounting_journal::entry', 'view_accounting_journal::entry']);
    $this->user = $this->user->fresh();

    // creator_id must be the acting test user -- JournalEntryResource's query
    // is scoped through HasPermissionScope (creator_id/assigned user), not
    // just company, so a record owned by someone else wouldn't resolve on
    // the table at all.
    $this->postedMove = Move::factory()->create(['company_id' => $company->id, 'creator_id' => $this->user->id, 'state' => MoveState::POSTED]);

    test()->actingAs($this->user);
});

it('hides the row delete action for a posted journal entry', function () {
    // The "posted" preset view is the table's default, so postedMove is
    // already visible without picking a tab.
    Livewire::test(ListJournalEntries::class)
        ->assertTableActionHidden('delete', $this->postedMove);
});
