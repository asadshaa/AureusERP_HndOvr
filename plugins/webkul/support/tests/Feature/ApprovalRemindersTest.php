<?php

/**
 * HR parity plan 2.7 -- approval reminders (approvals:send-reminders,
 * ApprovalReminderService): reminder after N hours to the current step's
 * approvers, then every N hours up to the maximum, then one escalation to the
 * company HR and the line manager's own manager; nothing at night (20:00 -
 * 09:00 Asia/Karachi) or on the weekend; idempotent per request, step, round
 * and reminder number; company isolation; the Approval Queue "waiting for Xh"
 * and "reminded N×" badges.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Filament\Resources\ApprovalRequestResource;
use Webkul\Support\Filament\Resources\ApprovalRequestResource\Pages\ListApprovalRequests;
use Webkul\Support\Filament\Resources\ApprovalWorkflowResource\Pages\EditApprovalWorkflow;
use Webkul\Support\Models\ApprovalReminder;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\ApprovalReminderService;

beforeEach(function (): void {
    config([
        'approvals.reminders.enabled'       => true,
        'approvals.reminders.after_hours'   => 24,
        'approvals.reminders.every_hours'   => 24,
        'approvals.reminders.max_reminders' => 3,
        'approvals.reminders.timezone'      => 'Asia/Karachi',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Karachi wall-clock time as the test "now" (the app runs in UTC). */
function remindersAt(string $karachi): Carbon
{
    $moment = Carbon::parse($karachi, 'Asia/Karachi')->utc();
    Carbon::setTestNow($moment);

    return $moment;
}

function remindersUser(Company $company, string $name, ?string $role = null): User
{
    $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role) {
        $user->assignRole(Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']));
    }

    return $user;
}

/**
 * Employee -> line manager -> director, an HR manager, and a two-step
 * workflow (line manager, then HR) for request type "reminder_check".
 *
 * @param  array<string, mixed>  $reminderSettings
 * @return array{company: Company, employeeUser: User, managerUser: User, directorUser: User, hrUser: User, workflow: ApprovalWorkflow}
 */
function remindersFixture(array $reminderSettings = []): array
{
    $company = Company::factory()->create(['currency_id' => Currency::query()->where('code', 'PKR')->value('id'), 'is_active' => true]);

    $directorUser = remindersUser($company, 'Dana Director');
    $director = Employee::query()->create(['company_id' => $company->id, 'user_id' => $directorUser->id, 'name' => 'Dana Director']);

    $managerUser = remindersUser($company, 'Mo Manager');
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Mo Manager', 'parent_id' => $director->id]);

    $employeeUser = remindersUser($company, 'Esa Employee');
    Employee::query()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'name' => 'Esa Employee', 'parent_id' => $manager->id]);

    $hrUser = remindersUser($company, 'Hana HR', 'hr_manager');

    $workflow = ApprovalWorkflow::query()->create($reminderSettings + [
        'company_id' => $company->id, 'request_type' => 'reminder_check', 'name' => 'Reminder check', 'is_active' => true,
    ]);
    $workflow->steps()->create(['sequence' => 1, 'name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);
    $workflow->steps()->create(['sequence' => 2, 'name' => 'HR Review', 'hierarchy_route' => 'hr', 'required_approvals' => 1]);

    return compact('company', 'employeeUser', 'managerUser', 'directorUser', 'hrUser', 'workflow');
}

function remindersSubmit(array $f, mixed $subject = null): ApprovalRequest
{
    return app(ApprovalEngine::class)->submit($subject ?? $f['company'], $f['employeeUser'], 'reminder_check', null, ['company_id' => (int) $f['company']->id]);
}

function remindersRun(array $f): array
{
    return app(ApprovalReminderService::class)->run(now(), (int) $f['company']->id);
}

/** @return array<int, string> idempotency keys of the user's notifications starting with $prefix */
function remindersKeys(User $user, string $prefix): array
{
    return $user->notifications()->get()
        ->map(fn ($notification): string => (string) ($notification->data['viewData']['idempotency_key'] ?? ''))
        ->filter(fn (string $key): bool => str_starts_with($key, $prefix))
        ->values()
        ->all();
}

it('defers anything due at night or on the weekend to the next working 09:00 in Karachi', function (): void {
    $service = app(ApprovalReminderService::class);
    $karachi = fn (string $at) => Carbon::parse($at, 'Asia/Karachi');

    // 2026-10-27 is a Tuesday, 2026-10-30 a Friday.
    expect($service->sendableFrom($karachi('2026-10-27 12:00'))->setTimezone('Asia/Karachi')->format('D Y-m-d H:i'))->toBe('Tue 2026-10-27 12:00')
        ->and($service->sendableFrom($karachi('2026-10-27 21:00'))->setTimezone('Asia/Karachi')->format('D Y-m-d H:i'))->toBe('Wed 2026-10-28 09:00')
        ->and($service->sendableFrom($karachi('2026-10-28 03:00'))->setTimezone('Asia/Karachi')->format('D Y-m-d H:i'))->toBe('Wed 2026-10-28 09:00')
        ->and($service->sendableFrom($karachi('2026-10-30 20:30'))->setTimezone('Asia/Karachi')->format('D Y-m-d H:i'))->toBe('Mon 2026-11-02 09:00')
        ->and($service->sendableFrom($karachi('2026-10-31 10:00'))->setTimezone('Asia/Karachi')->format('D Y-m-d H:i'))->toBe('Mon 2026-11-02 09:00')
        ->and($service->isQuietTime($karachi('2026-10-27 19:59')))->toBeFalse()
        ->and($service->isQuietTime($karachi('2026-10-27 20:00')))->toBeTrue()
        ->and($service->isQuietTime($karachi('2026-10-28 08:59')))->toBeTrue()
        ->and($service->isQuietTime($karachi('2026-11-01 12:00')))->toBeTrue();
});

it('reminds the current approver after 24 hours and then daily, once per reminder number', function (): void {
    $f = remindersFixture();
    remindersAt('2026-10-26 10:00'); // Monday
    $request = remindersSubmit($f);

    remindersAt('2026-10-27 09:30');
    expect(remindersRun($f)['reminded'])->toBe(0)
        ->and(remindersKeys($f['managerUser'], 'approval_reminder_'))->toBe([]);

    remindersAt('2026-10-27 10:00');
    expect(remindersRun($f))->toMatchArray(['reminded' => 1, 'escalated' => 0, 'notified' => 1]);

    // Same hour again, and an overlapping run: nothing more.
    expect(remindersRun($f)['reminded'])->toBe(0);
    $this->artisan('approvals:send-reminders', ['--company' => $f['company']->id])->assertSuccessful();

    $step = $request->fresh(['workflow.steps'])->currentStep();
    expect(remindersKeys($f['managerUser'], 'approval_reminder_'))->toBe(["approval_reminder_{$request->id}_step_{$step->id}_r1_n1_{$f['managerUser']->id}"])
        // Only the approver the step waits on: not HR, not the employee, not the director.
        ->and(remindersKeys($f['hrUser'], 'approval_reminder_'))->toBe([])
        ->and(remindersKeys($f['employeeUser'], 'approval_reminder_'))->toBe([])
        ->and(remindersKeys($f['directorUser'], 'approval_reminder_'))->toBe([]);

    $notification = $f['managerUser']->notifications()->get()->first(fn ($n) => str_starts_with((string) ($n->data['viewData']['idempotency_key'] ?? ''), 'approval_reminder_'));
    expect($notification->data['title'])->toContain('waiting for your approval')
        ->and($notification->data['body'])->toContain('reminder 1 of 3')
        ->and(collect($notification->data['actions'])->pluck('url')->first())->toContain('approval-requests')->toContain('tableActionRecord='.$request->id);

    remindersAt('2026-10-28 09:59');
    expect(remindersRun($f)['reminded'])->toBe(0);

    remindersAt('2026-10-28 10:00');
    expect(remindersRun($f)['reminded'])->toBe(1)
        ->and(ApprovalReminder::query()->where('request_id', $request->id)->orderBy('reminder_number')->pluck('reminder_number')->all())->toBe([1, 2]);
});

it('sends nothing at night or on the weekend and sends the deferred reminder at the next 09:00 run', function (): void {
    $f = remindersFixture();
    remindersAt('2026-10-30 11:00'); // Friday
    $request = remindersSubmit($f);

    expect(app(ApprovalReminderService::class)->nextFor($request->fresh())['send_from']->setTimezone('Asia/Karachi')->format('D Y-m-d H:i'))
        ->toBe('Mon 2026-11-02 09:00');

    foreach (['2026-10-31 11:00', '2026-11-01 15:00', '2026-11-02 08:00'] as $quiet) {
        remindersAt($quiet);
        expect(remindersRun($f))->toMatchArray(['reminded' => 0, 'quiet' => true]);
    }

    remindersAt('2026-11-02 09:00');
    expect(remindersRun($f)['reminded'])->toBe(1);

    // The next one counts from the reminder actually sent, not from Saturday.
    remindersAt('2026-11-02 20:30');
    expect(remindersRun($f))->toMatchArray(['reminded' => 0, 'quiet' => true]);
    remindersAt('2026-11-03 08:59');
    expect(remindersRun($f)['reminded'])->toBe(0);
    remindersAt('2026-11-03 09:00');
    expect(remindersRun($f)['reminded'])->toBe(1)
        ->and(remindersKeys($f['managerUser'], 'approval_reminder_'))->toHaveCount(2);
});

it('escalates once to HR and the line manager\'s own manager after the maximum reminders', function (): void {
    $f = remindersFixture(['remind_after_hours' => 2, 'remind_every_hours' => 1, 'max_reminders' => 2]);
    $otherCompany = Company::factory()->create(['currency_id' => Currency::query()->where('code', 'PKR')->value('id'), 'is_active' => true]);
    $otherHr = remindersUser($otherCompany, 'Other HR', 'hr_manager');

    remindersAt('2026-10-27 10:00'); // Tuesday
    $request = remindersSubmit($f);

    remindersAt('2026-10-27 11:59');
    expect(remindersRun($f)['reminded'])->toBe(0);
    remindersAt('2026-10-27 12:00');
    expect(remindersRun($f)['reminded'])->toBe(1);
    remindersAt('2026-10-27 13:00');
    expect(remindersRun($f)['reminded'])->toBe(1);
    remindersAt('2026-10-27 13:30');
    expect(remindersRun($f))->toMatchArray(['reminded' => 0, 'escalated' => 0]);
    remindersAt('2026-10-27 14:00');
    expect(remindersRun($f))->toMatchArray(['reminded' => 0, 'escalated' => 1, 'notified' => 2]);

    // Once only, and nothing more after it.
    remindersAt('2026-10-28 14:00');
    expect(remindersRun($f))->toMatchArray(['reminded' => 0, 'escalated' => 0]);

    expect(remindersKeys($f['managerUser'], 'approval_reminder_'))->toHaveCount(2)
        ->and(remindersKeys($f['hrUser'], 'approval_escalation_'))->toHaveCount(1)
        ->and(remindersKeys($f['directorUser'], 'approval_escalation_'))->toHaveCount(1)
        ->and(remindersKeys($f['managerUser'], 'approval_escalation_'))->toBe([])
        ->and(remindersKeys($f['employeeUser'], 'approval_escalation_'))->toBe([])
        ->and(remindersKeys($otherHr, 'approval_escalation_'))->toBe([])
        ->and(ApprovalReminder::query()->where('request_id', $request->id)->where('kind', 'escalation')->value('recipient_ids'))
        ->toEqualCanonicalizing([$f['hrUser']->id, $f['directorUser']->id]);

    $escalation = $f['hrUser']->notifications()->get()->first(fn ($n) => str_starts_with((string) ($n->data['viewData']['idempotency_key'] ?? ''), 'approval_escalation_'));
    expect($escalation->data['title'])->toContain('Escalated')->toContain('4h')
        ->and($escalation->data['body'])->toContain('after 2 reminder(s)');
});

it('restarts the clock when the step advances and skips workflows with reminders off', function (): void {
    $f = remindersFixture();
    $engine = app(ApprovalEngine::class);
    remindersAt('2026-10-26 10:00');
    $request = remindersSubmit($f);

    remindersAt('2026-10-27 10:00');
    remindersRun($f);
    expect(ApprovalRequestResource::remindersText($request->fresh(['workflow.steps', 'reminders'])))->toBe('reminded 1×');

    remindersAt('2026-10-27 15:00');
    $engine->approve($request->fresh(), $f['managerUser']);
    $request = $request->fresh(['workflow.steps', 'reminders']);

    expect($request->current_step_sequence)->toBe(2)
        ->and($request->current_step_started_at->equalTo(now()))->toBeTrue()
        ->and(ApprovalRequestResource::remindersText($request))->toBeNull()
        ->and(app(ApprovalReminderService::class)->nextFor($request)['due_at']->setTimezone('Asia/Karachi')->format('Y-m-d H:i'))->toBe('2026-10-28 15:00');

    remindersAt('2026-10-28 15:00');
    expect(remindersRun($f)['reminded'])->toBe(1)
        ->and(remindersKeys($f['hrUser'], 'approval_reminder_'))->toHaveCount(1);

    // Reminders switched off on the workflow: nothing further.
    $f['workflow']->update(['reminders_enabled' => false]);
    remindersAt('2026-10-29 15:00');
    expect(remindersRun($f)['reminded'])->toBe(0)
        ->and(app(ApprovalReminderService::class)->nextFor($request->fresh()))->toBeNull();
});

it('keeps companies apart: each company\'s approvers and HR only hear about their own requests', function (): void {
    $a = remindersFixture(['remind_after_hours' => 1, 'max_reminders' => 0]);
    $b = remindersFixture(['remind_after_hours' => 1, 'max_reminders' => 0]);

    remindersAt('2026-10-27 10:00');
    $requestA = remindersSubmit($a);
    $requestB = remindersSubmit($b);

    remindersAt('2026-10-27 11:00');
    // Only company A: B's request is left alone.
    expect(remindersRun($a))->toMatchArray(['checked' => 1, 'escalated' => 1]);
    expect(remindersKeys($a['hrUser'], 'approval_escalation_'))->toBe(["approval_escalation_{$requestA->id}_step_{$requestA->fresh(['workflow.steps'])->currentStep()->id}_r1_{$a['hrUser']->id}"])
        ->and(remindersKeys($b['hrUser'], 'approval_escalation_'))->toBe([]);

    // Every company: B's HR hears about B's request only.
    app(ApprovalReminderService::class)->run(now());
    expect(remindersKeys($b['hrUser'], 'approval_escalation_'))->toHaveCount(1)
        ->and(remindersKeys($b['hrUser'], 'approval_escalation_')[0])->toStartWith("approval_escalation_{$requestB->id}_")
        ->and(remindersKeys($a['hrUser'], 'approval_escalation_'))->toHaveCount(1)
        ->and(remindersKeys($b['directorUser'], 'approval_escalation_'))->toHaveCount(1)
        ->and(remindersKeys($a['directorUser'], 'approval_escalation_'))->toHaveCount(1);
});

it('shows "waiting for Xh" and a "reminded N×" badge in the Approval Queue', function (): void {
    $f = remindersFixture();
    $approver = $f['managerUser'];
    foreach (['view_any_support_approval::request', 'view_support_approval::request'] as $permission) {
        $approver->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    remindersAt('2026-10-26 10:00');
    $reminded = remindersSubmit($f);
    remindersAt('2026-10-27 09:00');
    $fresh = remindersSubmit($f, $f['employeeUser']);

    remindersAt('2026-10-27 12:00');
    remindersRun($f);

    $this->actingAs($approver);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListApprovalRequests::class)
        ->assertCanSeeTableRecords([$reminded, $fresh])
        ->assertTableColumnStateSet('waiting_for', 'waiting for 26h', $reminded)
        ->assertTableColumnStateSet('reminders_sent', 'reminded 1×', $reminded)
        ->assertTableColumnStateSet('waiting_for', 'waiting for 3h', $fresh)
        ->assertTableColumnStateSet('reminders_sent', null, $fresh);

    // Escalated, then decided: the badge says so, then disappears.
    $f['workflow']->update(['max_reminders' => 1]);
    remindersAt('2026-10-28 12:00');
    remindersRun($f);
    expect(ApprovalRequestResource::remindersText($reminded->fresh(['workflow.steps', 'reminders'])))->toBe('reminded 1× · escalated');

    app(ApprovalEngine::class)->approve($reminded->fresh(), $approver);
    app(ApprovalEngine::class)->approve($reminded->fresh(), $f['hrUser']);
    expect(ApprovalRequestResource::waitingText($reminded->fresh()))->toBeNull()
        ->and(ApprovalRequestResource::remindersText($reminded->fresh(['workflow.steps', 'reminders'])))->toBeNull();
});

it('edits the reminder settings on the Approval Workflow screen; empty fields use the defaults', function (): void {
    $f = remindersFixture();
    $admin = $f['hrUser'];
    foreach (['view_any_support_approval::workflow', 'view_support_approval::workflow', 'update_support_approval::workflow'] as $permission) {
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(app(ApprovalReminderService::class)->settingsFor($f['workflow']->fresh()))
        ->toBe(['enabled' => true, 'after_hours' => 24, 'every_hours' => 24, 'max_reminders' => 3]);

    Livewire::test(EditApprovalWorkflow::class, ['record' => $f['workflow']->getRouteKey()])
        ->assertFormFieldExists('reminders_enabled')
        ->assertFormFieldExists('remind_after_hours')
        ->assertFormFieldExists('remind_every_hours')
        ->assertFormFieldExists('max_reminders')
        ->fillForm(['remind_after_hours' => 8, 'remind_every_hours' => 4, 'max_reminders' => 2])
        ->call('save')
        ->assertHasNoFormErrors();

    $workflow = $f['workflow']->fresh();
    expect(app(ApprovalReminderService::class)->settingsFor($workflow))
        ->toBe(['enabled' => true, 'after_hours' => 8, 'every_hours' => 4, 'max_reminders' => 2])
        ->and($workflow->steps()->count())->toBe(2);

    Livewire::test(EditApprovalWorkflow::class, ['record' => $f['workflow']->getRouteKey()])
        ->fillForm(['remind_after_hours' => 0])
        ->call('save')
        ->assertHasFormErrors(['remind_after_hours' => 'min']);
});

it('stops chasing a step that has been waiting longer than max_age_days, but still shows how long it waits', function (): void {
    config(['approvals.reminders.max_age_days' => 14]);
    $f = remindersFixture();
    remindersAt('2026-10-12 10:00'); // Monday
    $old = remindersSubmit($f);
    remindersAt('2026-10-27 09:00');
    $recent = remindersSubmit($f, $f['employeeUser']);

    remindersAt('2026-10-28 10:00'); // the old step has waited 16 days
    $result = remindersRun($f);

    expect($result)->toMatchArray(['checked' => 1, 'reminded' => 1, 'escalated' => 0])
        ->and(app(ApprovalReminderService::class)->nextFor($old->fresh(), now()))->toBeNull()
        ->and(ApprovalReminder::query()->where('request_id', $old->id)->exists())->toBeFalse()
        ->and(ApprovalReminder::query()->where('request_id', $recent->id)->count())->toBe(1)
        ->and(ApprovalRequestResource::waitingText($old->fresh()))->toBe('waiting for 384h');

    // 0 switches the cut-off off.
    config(['approvals.reminders.max_age_days' => 0]);
    expect(app(ApprovalReminderService::class)->nextFor($old->fresh(), now()))->not->toBeNull();
});
