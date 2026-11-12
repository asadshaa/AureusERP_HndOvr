<?php

/**
 * Employees -> Reporting lines and Reporting history: access, the people table
 * (line manager, one column per purpose with the "own" marker, no query per
 * row), changing a line manager or the manager for one purpose, HR changing
 * her own line (pending until another person decides), bulk reassign and the
 * history list. The rules themselves live in ReportingLineService.
 */

use Database\Seeders\HrRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Pages\HrHelp;
use Webkul\Employee\Filament\Pages\ReportingHistory;
use Webkul\Employee\Filament\Pages\ReportingLines;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\ReportingAssignment;
use Webkul\Employee\Models\ReportingChange;
use Webkul\Employee\Services\Reporting\ReportingLineService;
use Webkul\Employee\Support\ReportingPurposes;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

function rlPerson(Company $company, string $name, ?Employee $manager = null, ?string $role = null): Employee
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
        'employee_number'   => 'RL-'.$company->id.'-'.$user->id,
        'employment_status' => 'active',
        'is_active'         => true,
        'parent_id'         => $manager?->id,
    ]);
}

function rlActAs(Employee $person): User
{
    $user = $person->user->fresh();
    Auth::login($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @return array<int, string> titles of the notifications shown to the user (waiting or already picked up by the page) */
function rlNotificationTitles(): array
{
    return collect(session('filament.notifications', []))
        ->merge(session('filament.claimed_notifications', []))
        ->pluck('title')
        ->all();
}

beforeEach(function (): void {
    app(HrRoleSeeder::class)->run();
    ReportingLineService::flushMemo();
    $this->company = Company::factory()->create(['name' => 'Lines Co', 'is_active' => true]);
    $this->hr = rlPerson($this->company, 'Hira HR', null, 'hr_manager');
    $this->boss = rlPerson($this->company, 'Bilal Boss');
    $this->alt = rlPerson($this->company, 'Alia Alternate');
    $this->staff = rlPerson($this->company, 'Sana Staff', $this->boss);
});

it('opens only for people who may manage reporting lines', function (): void {
    rlActAs($this->hr);
    expect(ReportingLines::canAccess())->toBeTrue()
        ->and(ReportingHistory::canAccess())->toBeTrue();

    rlActAs($this->staff);
    expect(ReportingLines::canAccess())->toBeFalse()
        ->and(ReportingHistory::canAccess())->toBeFalse();
});

it('shows another company\'s HR only their own company\'s people', function (): void {
    $other = Company::factory()->create(['name' => 'Other Co', 'is_active' => true]);
    $otherHr = rlPerson($other, 'Olga HR', null, 'hr_manager');
    $otherStaff = rlPerson($other, 'Omar Staff');

    rlActAs($otherHr);

    Livewire::test(ReportingLines::class)
        ->assertCanSeeTableRecords([$otherStaff, $otherHr])
        ->assertCanNotSeeTableRecords([$this->staff, $this->boss]);
});

it('lists the line manager and marks a manager set for one purpose as own', function (): void {
    ReportingAssignment::query()->create([
        'company_id'          => $this->company->id,
        'employee_id'         => $this->staff->id,
        'purpose'             => ReportingPurposes::Leave,
        'manager_employee_id' => $this->alt->id,
    ]);

    rlActAs($this->hr);

    Livewire::test(ReportingLines::class)
        ->assertCanSeeTableRecords([$this->staff])
        ->assertTableColumnStateSet('parent.name', 'Bilal Boss', $this->staff)
        ->assertTableColumnStateSet('purpose_leave', 'Alia Alternate', $this->staff)
        ->assertTableColumnStateSet('purpose_claims', 'Bilal Boss', $this->staff)
        ->assertSeeHtml('<em>Alia Alternate</em>')
        ->filterTable('has_own_approver', true)
        ->assertCanSeeTableRecords([$this->staff])
        ->assertCanNotSeeTableRecords([$this->boss, $this->alt]);
});

it('filters people without a line manager', function (): void {
    rlActAs($this->hr);

    Livewire::test(ReportingLines::class)
        ->filterTable('no_line_manager', true)
        ->assertCanSeeTableRecords([$this->boss, $this->alt])
        ->assertCanNotSeeTableRecords([$this->staff]);
});

it('changes a line manager and records it in the history', function (): void {
    $hr = rlActAs($this->hr);

    Livewire::test(ReportingLines::class)
        ->callTableAction('changeLineManager', $this->staff, ['manager' => $this->alt->id, 'reason' => 'Team moved'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Reporting line updated');

    expect($this->staff->fresh()->parent_id)->toBe($this->alt->id);

    $change = ReportingChange::query()->where('employee_id', $this->staff->id)->latest('id')->first();
    expect($change)->not->toBeNull()
        ->and($change->status)->toBe(ReportingChange::Applied)
        ->and($change->purpose)->toBeNull()
        ->and($change->old_manager_employee_id)->toBe($this->boss->id)
        ->and($change->new_manager_employee_id)->toBe($this->alt->id)
        ->and($change->requested_by)->toBe($hr->id)
        ->and($change->reason)->toBe('Team moved');
});

it('refuses a loop with a plain message and changes nothing', function (): void {
    rlActAs($this->hr);

    // Sana reports to Bilal, so Bilal cannot report to Sana.
    expect(fn () => app(ReportingLineService::class)->change(Auth::user(), $this->boss, null, $this->staff->id))
        ->toThrow(InvalidArgumentException::class, 'already reports to Bilal Boss');

    // The picker does not even offer the people who report to Bilal.
    Livewire::test(ReportingLines::class)
        ->callTableAction('changeLineManager', $this->boss, ['manager' => $this->staff->id])
        ->assertHasTableActionErrors(['manager']);

    // Setting the same manager again is refused with a plain message.
    Livewire::test(ReportingLines::class)
        ->callTableAction('changeLineManager', $this->staff, ['manager' => $this->boss->id])
        ->assertNotified('Not changed');

    expect($this->boss->fresh()->parent_id)->toBeNull()
        ->and(ReportingChange::query()->where('employee_id', $this->boss->id)->count())->toBe(0);
});

it('sets a manager for one purpose and clears it again', function (): void {
    rlActAs($this->hr);

    Livewire::test(ReportingLines::class)
        ->callTableAction('changeForPurpose', $this->staff, ['purpose' => ReportingPurposes::Claims, 'manager' => $this->alt->id])
        ->assertNotified('Reporting line updated');

    $service = app(ReportingLineService::class);
    ReportingLineService::flushMemo();
    expect($service->hasOwnManager($this->staff, ReportingPurposes::Claims))->toBeTrue()
        ->and($service->managerFor($this->staff->fresh(), ReportingPurposes::Claims)->id)->toBe($this->alt->id)
        ->and($this->staff->fresh()->parent_id)->toBe($this->boss->id);

    Livewire::test(ReportingLines::class)
        ->callTableAction('changeForPurpose', $this->staff, ['purpose' => ReportingPurposes::Claims, 'manager' => null])
        ->assertNotified('Reporting line updated');

    ReportingLineService::flushMemo();
    expect($service->hasOwnManager($this->staff, ReportingPurposes::Claims))->toBeFalse()
        ->and($service->managerFor($this->staff->fresh(), ReportingPurposes::Claims)->id)->toBe($this->boss->id)
        ->and(ReportingChange::query()->where('employee_id', $this->staff->id)->where('purpose', ReportingPurposes::Claims)->count())->toBe(2);
});

it('shows the approval flow of a person with real names', function (): void {
    $workflow = ApprovalWorkflow::query()->create(['company_id' => $this->company->id, 'request_type' => 'leave_request', 'name' => 'Leave approval', 'is_active' => true]);
    ApprovalStep::query()->create(['workflow_id' => $workflow->id, 'sequence' => 1, 'name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);

    rlActAs($this->hr);

    Livewire::test(ReportingLines::class)
        ->mountTableAction('seeFlow', $this->staff)
        ->assertHasNoErrors();

    $html = view('employees::filament.pages.reporting-flow', [
        'groups' => app(ReportingLineService::class)->flowFor($this->staff),
    ])->render();

    expect($html)->toContain('Leave')
        ->and($html)->toContain('1. Line Manager Review')
        ->and($html)->toContain('Bilal Boss (line manager)');
});

it('sends HR\'s change to her own line for approval and lets another HR person decide it', function (): void {
    $secondHr = rlPerson($this->company, 'Haris HR', null, 'hr_manager');

    rlActAs($this->hr);

    Livewire::test(ReportingLines::class)
        ->callTableAction('changeLineManager', $this->hr, ['manager' => $this->boss->id])
        ->assertHasNoTableActionErrors();

    expect(rlNotificationTitles())->toContain('Sent for approval');

    $change = ReportingChange::query()->where('employee_id', $this->hr->id)->firstOrFail();
    expect($change->status)->toBe(ReportingChange::Pending)
        ->and($this->hr->fresh()->parent_id)->toBeNull();

    // She cannot decide her own change.
    Livewire::test(ReportingHistory::class)
        ->assertCanSeeTableRecords([$change])
        ->assertTableActionHidden('approve', $change)
        ->assertTableActionHidden('reject', $change);

    expect(ReportingHistory::getNavigationBadge())->toBeNull();

    rlActAs($secondHr);

    expect(ReportingHistory::getNavigationBadge())->toBe('1');

    Livewire::test(ReportingHistory::class)
        ->assertTableActionVisible('approve', $change)
        ->callTableAction('approve', $change);

    expect(rlNotificationTitles())->toContain('Change approved');

    expect($this->hr->fresh()->parent_id)->toBe($this->boss->id)
        ->and($change->fresh()->status)->toBe(ReportingChange::Applied)
        ->and($change->fresh()->decided_by)->toBe($secondHr->user_id);
});

it('lets another HR person reject a waiting change with a note', function (): void {
    $secondHr = rlPerson($this->company, 'Haris HR', null, 'hr_manager');
    $change = app(ReportingLineService::class)->change(rlActAs($this->hr), $this->hr, null, $this->boss->id);

    rlActAs($secondHr);

    Livewire::test(ReportingHistory::class)
        ->callTableAction('reject', $change, ['note' => 'Not now'])
        ->assertNotified('Change rejected');

    expect($change->fresh()->status)->toBe(ReportingChange::Rejected)
        ->and($this->hr->fresh()->parent_id)->toBeNull();
});

it('reports the people a bulk reassign could not change', function (): void {
    rlActAs($this->hr);

    // Alia cannot become her own manager; Sana can move to her.
    Livewire::test(ReportingLines::class)
        ->callTableBulkAction('giveNewManager', [$this->alt, $this->staff], ['manager' => $this->alt->id])
        ->assertHasNoTableBulkActionErrors();

    expect(rlNotificationTitles())->toContain('Reporting line updated for 1 person', '1 could not be changed');

    expect($this->staff->fresh()->parent_id)->toBe($this->alt->id)
        ->and($this->alt->fresh()->parent_id)->toBeNull();
});

it('lists the history and filters it', function (): void {
    $service = app(ReportingLineService::class);
    $actor = rlActAs($this->hr);

    $line = $service->change($actor, $this->staff, null, $this->alt->id, 'Moved');
    $leave = $service->change($actor, $this->staff, ReportingPurposes::Leave, $this->boss->id);
    $pending = $service->change($actor, $this->hr, null, $this->boss->id);

    Livewire::test(ReportingHistory::class)
        ->assertCanSeeTableRecords([$line, $leave, $pending])
        ->assertTableColumnStateSet('status', ReportingChange::Pending, $pending)
        ->filterTable('status', ReportingChange::Pending)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$line, $leave])
        ->removeTableFilter('status')
        ->filterTable('purpose', ReportingPurposes::Leave)
        ->assertCanSeeTableRecords([$leave])
        ->assertCanNotSeeTableRecords([$line, $pending])
        ->filterTable('purpose', 'line')
        ->assertCanSeeTableRecords([$line, $pending])
        ->assertCanNotSeeTableRecords([$leave])
        ->removeTableFilter('purpose')
        ->searchTable('Sana')
        ->assertCanSeeTableRecords([$line, $leave])
        ->assertCanNotSeeTableRecords([$pending]);
});

it('does not show another company\'s history', function (): void {
    $other = Company::factory()->create(['name' => 'Other Co', 'is_active' => true]);
    $otherHr = rlPerson($other, 'Olga HR', null, 'hr_manager');
    $otherBoss = rlPerson($other, 'Omar Boss');
    $otherStaff = rlPerson($other, 'Owen Staff');
    $foreign = app(ReportingLineService::class)->change(rlActAs($otherHr), $otherStaff, null, $otherBoss->id);

    $mine = app(ReportingLineService::class)->change(rlActAs($this->hr), $this->staff, null, $this->alt->id);

    Livewire::test(ReportingHistory::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('does not run more queries when there are more people', function (): void {
    rlActAs($this->hr);

    $count = function (): int {
        // Creating people queues chatter work that would otherwise be replayed (and counted) on every request.
        (new ReflectionProperty(app(), 'terminatingCallbacks'))->setValue(app(), []);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ReportingLines::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count(); // warm per-process caches (permissions, schema)
    $before = $count();

    foreach (range(1, 12) as $index) {
        $person = rlPerson($this->company, 'Extra '.$index, $this->boss);
        ReportingAssignment::query()->create([
            'company_id'          => $this->company->id,
            'employee_id'         => $person->id,
            'purpose'             => ReportingPurposes::Overtime,
            'manager_employee_id' => $this->alt->id,
        ]);
    }

    rlActAs($this->hr);

    expect($count())->toBeLessThanOrEqual($before + 3);
});

it('explains reporting lines on the help page', function (): void {
    rlActAs($this->hr);

    $topics = collect(app(HrHelp::class)->getGroups())->pluck('topics')->flatten(1)->pluck('id');

    expect($topics)->toContain('reporting-lines');
});
