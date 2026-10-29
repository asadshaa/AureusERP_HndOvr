<?php

/**
 * HR parity plan 2.7 -- "Return to requester" in the approval engine: only a
 * current-step approver can return (with a comment); a returned request is
 * not pending anywhere (canAct, queue badge, action center, control center);
 * only the requester resubmits, which restarts it at step 1 in a new round
 * where earlier approvals no longer count; withdraw while pending or
 * returned; everything stays in the decision history; company isolation.
 */

use App\Services\ControlCenter\ControlCenterService;
use App\Services\Dashboard\RoleActionCenterService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Webkul\Employee\Exceptions\AttendanceMonthLockedException;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Contracts\ReturnableApprovalSubject;
use Webkul\Support\Enums\ApprovalRequestStatus;
use Webkul\Support\Filament\Resources\ApprovalRequestResource;
use Webkul\Support\Filament\Resources\ApprovalRequestResource\Pages\ListApprovalRequests;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

/** A company row standing in for a subject wired for "Return to requester" (leave, employee requests). */
class ReturnableTestSubject extends Company implements ReturnableApprovalSubject
{
    protected $table = 'companies';

    public function approvalSubjectUrl(?User $recipient = null): ?string
    {
        return 'https://aureus.test/subjects/'.$this->getKey();
    }
}

function returnUser(Company $company, string $name, ?string $role = null): User
{
    $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role) {
        $user->assignRole(Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']));
    }

    foreach (['view_any_support_approval::request', 'view_support_approval::request'] as $permission) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    return $user;
}

/** @return array{company: Company, employeeUser: User, managerUser: User, hrUser: User, otherHr: User} */
function returnFixture(): array
{
    $pkr = Currency::query()->where('code', 'PKR')->value('id');
    $company = Company::factory()->create(['currency_id' => $pkr, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $pkr, 'is_active' => true]);

    $managerUser = returnUser($company, 'Mo Manager');
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Mo Manager']);
    $employeeUser = returnUser($company, 'Esa Employee');
    Employee::query()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'name' => 'Esa Employee', 'parent_id' => $manager->id]);
    $hrUser = returnUser($company, 'Hana HR', 'hr_manager');
    $otherHr = returnUser($otherCompany, 'Other HR', 'hr_manager');

    $workflow = ApprovalWorkflow::query()->create(['company_id' => $company->id, 'request_type' => 'return_check', 'name' => 'Return check', 'is_active' => true]);
    $workflow->steps()->create(['sequence' => 1, 'name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);
    $workflow->steps()->create(['sequence' => 2, 'name' => 'HR Review', 'hierarchy_route' => 'hr', 'required_approvals' => 1]);

    return compact('company', 'employeeUser', 'managerUser', 'hrUser', 'otherHr');
}

it('labels the returned status as an info badge in every language', function (): void {
    expect(ApprovalRequestStatus::labelFor('returned'))->toBe('Returned for changes')
        ->and(ApprovalRequestStatus::colorFor('returned'))->toBe('info')
        ->and(ApprovalRequestStatus::options())->toHaveKey('returned');

    foreach (['en' => 'Returned for changes', 'ar' => 'أُعيد لإجراء تعديلات', 'es' => 'Devuelto para cambios'] as $locale => $label) {
        app()->setLocale($locale);
        expect(ApprovalRequestStatus::Returned->getLabel())->toBe($label);
    }
    app()->setLocale('en');
});

it('lets only the current-step approver return a request, with a comment', function (): void {
    $f = returnFixture();
    $engine = app(ApprovalEngine::class);
    $request = $engine->submit($f['company'], $f['employeeUser'], 'return_check', null, ['company_id' => (int) $f['company']->id]);

    foreach ([$f['employeeUser'], $f['hrUser'], $f['otherHr']] as $notApprover) {
        expect(fn () => $engine->returnToRequester($request->fresh(), $notApprover, 'Fix it'))
            ->toThrow(RuntimeException::class, 'not an approver');
    }
    expect(fn () => $engine->returnToRequester($request->fresh(), $f['managerUser'], '   '))
        ->toThrow(RuntimeException::class, 'comment is required');

    $returned = $engine->returnToRequester($request->fresh(), $f['managerUser'], 'Attach the quotation');

    expect($returned->status)->toBe('returned')
        ->and($returned->current_step_sequence)->toBe(1)
        ->and($returned->completed_at)->toBeNull()
        ->and($returned->decisions->last()->only(['decision', 'reason', 'actor_id', 'submission_round']))
        ->toBe(['decision' => 'returned', 'reason' => 'Attach the quotation', 'actor_id' => $f['managerUser']->id, 'submission_round' => 1])
        ->and($engine->canAct($returned, $f['managerUser']))->toBeFalse();

    // The requester is told, with the comment.
    $notice = $f['employeeUser']->notifications()->get()->first(fn ($n) => str_starts_with((string) ($n->data['viewData']['idempotency_key'] ?? ''), 'approval_returned_'));
    expect($notice)->not->toBeNull()
        ->and($notice->data['body'])->toContain('Attach the quotation')->toContain('Mo Manager');
});

it('never counts a returned request as pending: queue badge, action center, control center', function (): void {
    $f = returnFixture();
    $engine = app(ApprovalEngine::class);
    $companyId = (int) $f['company']->id;
    $pending = $engine->submit(ReturnableTestSubject::query()->findOrFail($f['company']->id), $f['employeeUser'], 'return_check', null, ['company_id' => $companyId]);
    $toReturn = $engine->submit($f['employeeUser'], $f['employeeUser'], 'return_check', null, ['company_id' => $companyId]);

    $admin = returnUser($f['company'], 'Ada Admin');
    $admin->assignRole(Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail());
    $adminTitle = fn (): ?string => collect(app(RoleActionCenterService::class)->getPendingActionsFor($admin, $companyId)['items'])
        ->firstWhere('id', 'admin_approvals_pending')['title'] ?? null;
    $attentionTitle = fn (): ?string => collect(app(ControlCenterService::class)->getAttentionItems($companyId, now()->toDateString()))
        ->firstWhere('id', 'pending_approvals')['title'] ?? null;

    $this->actingAs($f['managerUser']);
    expect(ApprovalRequestResource::getNavigationBadge())->toBe('2')
        ->and($adminTitle())->toStartWith('2 ')
        ->and($attentionTitle())->toStartWith('2 ');

    $engine->returnToRequester($toReturn->fresh(), $f['managerUser'], 'Wrong dates');

    expect(ApprovalRequestResource::getNavigationBadge())->toBe('1')
        ->and($engine->actionableRequestsFor($f['managerUser'], $companyId)->pluck('id')->all())->toBe([$pending->id])
        ->and($adminTitle())->toStartWith('1 ')
        ->and($attentionTitle())->toStartWith('1 ');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $list = Livewire::test(ListApprovalRequests::class)
        ->assertTableColumnFormattedStateSet('status', 'Returned for changes', $toReturn)
        ->assertTableActionHidden('return_to_requester', $toReturn)
        ->assertTableActionVisible('return_to_requester', $pending)
        ->filterTable('status', 'returned')
        ->assertCanSeeTableRecords([$toReturn])
        ->assertCanNotSeeTableRecords([$pending]);

    $statusColumn = $list->instance()->getTable()->getColumn('status');
    $statusColumn->record($toReturn->fresh());
    expect($statusColumn->getColor('returned'))->toBe('info');
});

it('returns from the Approval Queue action, with the comment, only for subjects wired for it', function (): void {
    $f = returnFixture();
    $engine = app(ApprovalEngine::class);
    $context = ['company_id' => (int) $f['company']->id];
    $request = $engine->submit(ReturnableTestSubject::query()->findOrFail($f['company']->id), $f['employeeUser'], 'return_check', null, $context);
    // A flow without a resubmit path (here a plain user subject) is never offered the button.
    $unwired = $engine->submit($f['employeeUser'], $f['employeeUser'], 'return_check', null, $context);

    $this->actingAs($f['managerUser']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect($engine->canAct($unwired, $f['managerUser']))->toBeTrue()
        ->and($engine->canReturn($unwired, $f['managerUser']))->toBeFalse()
        ->and($engine->canReturn($request, $f['managerUser']))->toBeTrue();

    Livewire::test(ListApprovalRequests::class)
        ->assertTableActionHidden('return_to_requester', $unwired)
        ->assertTableActionVisible('return_to_requester', $request)
        ->callTableAction('return_to_requester', $request, ['comment' => ''])
        ->assertHasTableActionErrors(['comment' => 'required']);
    expect($request->fresh()->status)->toBe('pending');

    Livewire::test(ListApprovalRequests::class)
        ->callTableAction('return_to_requester', $request, ['comment' => 'Add the receipt'])
        ->assertHasNoTableActionErrors();
    expect($request->fresh()->status)->toBe('returned');

    // The requester's notice links to the subject itself.
    $notice = $f['employeeUser']->notifications()->get()->first(fn ($n) => str_starts_with((string) ($n->data['viewData']['idempotency_key'] ?? ''), "approval_returned_{$request->id}_"));
    expect(collect($notice->data['actions'])->pluck('url')->first())->toBe('https://aureus.test/subjects/'.$f['company']->id);
});

it('lets only the requester resubmit, restarting at step 1 where earlier approvals no longer count', function (): void {
    $f = returnFixture();
    $engine = app(ApprovalEngine::class);
    $request = $engine->submit($f['company'], $f['employeeUser'], 'return_check', '100', ['company_id' => (int) $f['company']->id]);

    $engine->approve($request->fresh(), $f['managerUser'], 'Fine');
    $returned = $engine->returnToRequester($request->fresh(), $f['hrUser'], 'Amount too high');
    expect($returned->current_step_sequence)->toBe(2);

    foreach ([$f['managerUser'], $f['hrUser'], $f['otherHr']] as $notRequester) {
        expect($engine->canResubmit($returned, $notRequester))->toBeFalse()
            ->and(fn () => $engine->resubmit($returned->fresh(), $notRequester))->toThrow(RuntimeException::class);
    }

    $resubmitted = $engine->resubmit($returned->fresh(), $f['employeeUser'], '80', null, 'Lowered the amount', ['amount' => '100'], ['amount' => '80']);

    expect($resubmitted->status)->toBe('pending')
        ->and($resubmitted->current_step_sequence)->toBe(1)
        ->and($resubmitted->submission_round)->toBe(2)
        ->and((string) $resubmitted->amount)->toBe('80.0000')
        ->and($engine->canAct($resubmitted, $f['managerUser']))->toBeTrue()
        ->and($engine->canAct($resubmitted, $f['hrUser']))->toBeFalse()
        ->and(fn () => $engine->resubmit($resubmitted->fresh(), $f['employeeUser']))->toThrow(RuntimeException::class, 'returned for changes');

    // The line manager decides step 1 again (not "already decided"), then HR.
    $engine->approve($resubmitted->fresh(), $f['managerUser'], 'Fine now');
    $approved = $engine->approve($request->fresh(), $f['hrUser']);

    expect($approved->status)->toBe('approved')
        ->and($approved->decisions->map(fn ($d): string => $d->decision.':'.$d->submission_round)->all())
        ->toBe(['approved:1', 'returned:1', 'resubmitted:2', 'approved:2', 'approved:2'])
        ->and($approved->decisions->firstWhere('decision', 'resubmitted')->new_values)->toBe(['amount' => '80']);

    $history = ApprovalRequestResource::decisionHistoryText($approved);
    expect($history)->toContain('[RETURNED] Step 2: HR Review by Hana HR')
        ->toContain('(Note: Amount too high)')
        ->toContain('[RESUBMITTED] Step 1: Line Manager Review (round 2) by Esa Employee')
        ->toContain('changed: Amount: 100 → 80');
});

it('resubmits through submit() for the same subject instead of opening a second request', function (): void {
    $f = returnFixture();
    $engine = app(ApprovalEngine::class);
    $context = ['company_id' => (int) $f['company']->id];
    $request = $engine->submit($f['company'], $f['employeeUser'], 'return_check', null, $context);
    $engine->returnToRequester($request->fresh(), $f['managerUser'], 'Fix it');

    expect(fn () => $engine->submit($f['company'], $f['managerUser'], 'return_check', null, $context))
        ->toThrow(RuntimeException::class, 'Only the person who submitted');

    $again = $engine->submit($f['company'], $f['employeeUser'], 'return_check', null, $context);

    expect($again->id)->toBe($request->id)
        ->and($again->status)->toBe('pending')
        ->and($again->submission_round)->toBe(2);
});

it('lets the requester withdraw while pending or returned, and nobody else', function (): void {
    $f = returnFixture();
    $engine = app(ApprovalEngine::class);
    $context = ['company_id' => (int) $f['company']->id];

    $pending = $engine->submit($f['company'], $f['employeeUser'], 'return_check', null, $context);
    expect($engine->canWithdraw($pending, $f['managerUser']))->toBeFalse()
        ->and($engine->canWithdraw($pending, $f['otherHr']))->toBeFalse()
        ->and(fn () => $engine->withdraw($pending->fresh(), $f['hrUser']))->toThrow(RuntimeException::class);

    $withdrawn = $engine->withdraw($pending->fresh(), $f['employeeUser'], 'Not needed');
    expect($withdrawn->status)->toBe('withdrawn')
        ->and($withdrawn->decisions->last()->decision)->toBe('withdrawn')
        ->and($engine->canAct($withdrawn, $f['managerUser']))->toBeFalse()
        ->and(collect($f['managerUser']->notifications()->get())->contains(fn ($n) => str_starts_with((string) ($n->data['viewData']['idempotency_key'] ?? ''), 'approval_withdrawn_')))->toBeTrue();

    $returned = $engine->submit($f['employeeUser'], $f['employeeUser'], 'return_check', null, $context);
    $engine->returnToRequester($returned->fresh(), $f['managerUser'], 'Fix it');
    expect($engine->withdraw($returned->fresh(), $f['employeeUser'])->status)->toBe('withdrawn')
        ->and(fn () => $engine->withdraw($returned->fresh(), $f['employeeUser']))->toThrow(RuntimeException::class);
});

/** A subject whose approval side effect refuses the decision (like overtime below 30 minutes, or a locked month). */
class RefusingTestSubject extends Company
{
    public static ?RuntimeException $refusal = null;

    protected $table = 'companies';

    public function synchronizeApprovalState($request): void
    {
        if (static::$refusal && in_array($request->status, ['approved', 'rejected'], true)) {
            throw static::$refusal;
        }
    }
}

it('shows the reason, not an error page, when the subject refuses an approve or reject from the Approval Queue', function (): void {
    $f = returnFixture();
    $engine = app(ApprovalEngine::class);
    $workflow = ApprovalWorkflow::query()->create(['company_id' => $f['company']->id, 'request_type' => 'refusal_check', 'name' => 'Refusal check', 'is_active' => true]);
    $workflow->steps()->create(['sequence' => 1, 'name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);
    $request = $engine->submit(RefusingTestSubject::query()->findOrFail($f['company']->id), $f['employeeUser'], 'refusal_check', null, ['company_id' => (int) $f['company']->id]);

    $this->actingAs($f['managerUser']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    RefusingTestSubject::$refusal = new RuntimeException('Only 20 minutes were recorded; at least 30 are needed.');
    Livewire::test(ListApprovalRequests::class)
        ->callTableAction('approve', $request, ['reason' => 'OK'])
        ->assertNotified('Could not approve');
    expect($request->fresh()->status)->toBe('pending')
        ->and($request->fresh()->decisions)->toHaveCount(0);

    RefusingTestSubject::$refusal = new AttendanceMonthLockedException('October 2026 is finalised.', (int) $f['company']->id, '2026-10');
    Livewire::test(ListApprovalRequests::class)
        ->callTableAction('reject', $request, ['reason' => 'No'])
        ->assertNotified('Could not reject');
    expect($request->fresh()->status)->toBe('pending');

    RefusingTestSubject::$refusal = null;
    Livewire::test(ListApprovalRequests::class)
        ->callTableAction('approve', $request, ['reason' => 'OK'])
        ->assertNotified('Approval recorded');
    expect($request->fresh()->status)->toBe('approved');
});
