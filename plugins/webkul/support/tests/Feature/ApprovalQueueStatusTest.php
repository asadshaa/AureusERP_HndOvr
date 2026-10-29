<?php

/**
 * Approval Queue statuses: a request the employee withdrew (status
 * "withdrawn", e.g. a withdrawn leave) reads as a gray "Withdrawn" badge, is
 * a status filter option, and is never counted as pending anywhere -- queue
 * badge, dashboard action center, control center.
 */

use App\Services\ControlCenter\ControlCenterService;
use App\Services\Dashboard\RoleActionCenterService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\ApprovalRequestStatus;
use Webkul\Support\Filament\Resources\ApprovalRequestResource;
use Webkul\Support\Filament\Resources\ApprovalRequestResource\Pages\ListApprovalRequests;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

function approvalQueueUser(Company $company): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

/** @return array{company: Company, requester: User, approver: User} */
function approvalQueueFixture(): array
{
    $company = Company::factory()->create(['currency_id' => Currency::query()->where('code', 'PKR')->value('id'), 'is_active' => true]);
    $requester = approvalQueueUser($company);
    $approver = approvalQueueUser($company);
    foreach (['view_any_support_approval::request', 'view_support_approval::request'] as $permission) {
        $approver->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    ApprovalWorkflow::query()
        ->create(['company_id' => $company->id, 'request_type' => 'queue_status_check', 'name' => 'Queue status check', 'is_active' => true])
        ->steps()->create(['sequence' => 1, 'name' => 'Named approver', 'approver_user_id' => $approver->id, 'required_approvals' => 1]);

    return compact('company', 'requester', 'approver');
}

it('labels each approval status and only treats pending as waiting', function (): void {
    expect(ApprovalRequestStatus::options())->toBe([
        'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn',
    ])
        ->and(ApprovalRequestStatus::labelFor('withdrawn'))->toBe('Withdrawn')
        ->and(ApprovalRequestStatus::colorFor('withdrawn'))->toBe('gray')
        ->and(ApprovalRequestStatus::colorFor('pending'))->toBe('warning')
        ->and(ApprovalRequestStatus::colorFor('approved'))->toBe('success')
        ->and(ApprovalRequestStatus::colorFor('rejected'))->toBe('danger')
        ->and(ApprovalRequestStatus::labelFor('on_hold'))->toBe('On Hold')
        ->and(ApprovalRequestStatus::colorFor('on_hold'))->toBe('gray');
});

it('shows a withdrawn request as a gray Withdrawn badge, filters by it, and never counts it as pending', function (): void {
    $f = approvalQueueFixture();
    $engine = app(ApprovalEngine::class);
    $companyId = (int) $f['company']->id;

    $pending = $engine->submit($f['company'], $f['requester'], 'queue_status_check', null, ['company_id' => $companyId]);
    $withdrawn = $engine->submit($f['requester'], $f['requester'], 'queue_status_check', null, ['company_id' => $companyId]);

    $admin = approvalQueueUser($f['company']);
    $admin->assignRole(Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail());
    $adminApprovalsTitle = fn (): ?string => collect(app(RoleActionCenterService::class)->getPendingActionsFor($admin, $companyId)['items'])
        ->firstWhere('id', 'admin_approvals_pending')['title'] ?? null;
    $attentionTitle = fn (): ?string => collect(app(ControlCenterService::class)->getAttentionItems($companyId, now()->toDateString()))
        ->firstWhere('id', 'pending_approvals')['title'] ?? null;

    $this->actingAs($f['approver']);
    expect(ApprovalRequestResource::getNavigationBadge())->toBe('2')
        ->and($adminApprovalsTitle())->toStartWith('2 ')
        ->and($attentionTitle())->toStartWith('2 ');

    // Exactly how LeaveCancellationService::withdraw() closes the approval request.
    $withdrawn->update(['status' => ApprovalRequestStatus::Withdrawn->value, 'completed_at' => now()]);

    expect(ApprovalRequestResource::getNavigationBadge())->toBe('1')
        ->and($engine->actionableRequestsFor($f['approver'], $companyId)->pluck('id')->all())->toBe([$pending->id])
        ->and($engine->canAct($withdrawn->fresh(), $f['approver']))->toBeFalse()
        ->and($adminApprovalsTitle())->toStartWith('1 ')
        ->and($attentionTitle())->toStartWith('1 ');

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $list = Livewire::test(ListApprovalRequests::class)
        ->assertCanSeeTableRecords([$pending, $withdrawn])
        ->assertTableColumnFormattedStateSet('status', 'Withdrawn', $withdrawn)
        ->assertTableColumnFormattedStateSet('status', 'Pending', $pending);

    $statusColumn = $list->instance()->getTable()->getColumn('status');
    $statusColumn->record($withdrawn->fresh());
    expect($statusColumn->getColor('withdrawn'))->toBe('gray');
    $statusColumn->record($pending->fresh());
    expect($statusColumn->getColor('pending'))->toBe('warning');

    $list->filterTable('status', 'withdrawn')
        ->assertCanSeeTableRecords([$withdrawn])
        ->assertCanNotSeeTableRecords([$pending])
        ->filterTable('status', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$withdrawn]);

    // Once nothing is pending, the badge disappears and no "waiting" action is offered.
    $pending->update(['status' => ApprovalRequestStatus::Withdrawn->value, 'completed_at' => now()]);

    expect(ApprovalRequestResource::getNavigationBadge())->toBeNull()
        ->and($adminApprovalsTitle())->toBeNull()
        ->and($attentionTitle())->toBeNull();
});
