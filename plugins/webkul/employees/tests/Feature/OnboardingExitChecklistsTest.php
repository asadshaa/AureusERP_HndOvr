<?php

/**
 * Onboarding and exit checklists (HR Parity 2.4): default templates per
 * company, onboarding that starts by itself (once), "Start exit" with the
 * login disabled only on / after the last working day by the scheduled
 * command (audited), undo before the leaving date, document-linked tasks,
 * the daily overdue reminder (once per task per day) and who may do what.
 */

use App\Services\Dashboard\RoleActionCenterService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Chatter\Models\Message;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ChecklistTemplateResource\Pages\ListChecklistTemplates;
use Webkul\Employee\Filament\Pages\MyChecklistTasks;
use Webkul\Employee\Filament\Pages\OnboardingAndExits;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\ChecklistTasksRelationManager;
use Webkul\Employee\Models\ChecklistTemplate;
use Webkul\Employee\Models\DepartureReason;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeChecklist;
use Webkul\Employee\Models\EmployeeChecklistTask;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Policies\EmployeeChecklistTaskPolicy;
use Webkul\Employee\Services\Checklists\EmployeeChecklistService;
use Webkul\Employee\Services\EmployeeDocumentService;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Employee\Support\DefaultChecklistTemplates;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function () {
    Storage::fake('local');
    config(['attendance.monthly_report.timezone' => 'Asia/Karachi']);
    // Wednesday 28 Oct 2026, 10:00 in Karachi.
    test()->travelTo(CarbonImmutable::parse('2026-10-28 10:00', 'Asia/Karachi')->utc());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

function ckCompany(string $name = 'Checklist Co'): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true]);
}

/** @param  array<int, string>  $permissions */
function ckUser(Company $company, array $permissions = []): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

/** Company-wide HR with a decision right: an HR approver of the company. */
function ckHr(Company $company): User
{
    return ckUser($company, [
        HrPermissions::ViewAllRecords,
        HrPermissions::ManageChecklists,
        HrPermissions::ApproveLeave,
        HrPermissions::ManageEmployeeDocuments,
        'view_any_employee_employee',
        'view_employee_employee',
    ]);
}

function ckEmployee(Company $company, string $name, ?User $user = null, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id' => $company->id,
        'name'       => $name,
        'is_active'  => true,
        'user_id'    => $user?->id,
    ]);
}

function ckTask(EmployeeChecklist $checklist, string $code): EmployeeChecklistTask
{
    return EmployeeChecklistTask::query()->where('checklist_id', $checklist->id)->where('code', $code)->firstOrFail();
}

function ckPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%".str_repeat('a', 200)."\n%%EOF\n");
}

/** @return array<int, string> notification titles of the user */
function ckTitles(User $user): array
{
    return $user->fresh()->notifications()->orderBy('created_at')->orderBy('id')->get()
        ->map(fn ($notification): string => (string) $notification->data['title'])->all();
}

/**
 * HR, a line manager with a login and a new joiner with a login, all in one
 * company; HR is signed in.
 *
 * @return array{company: Company, hr: User, managerUser: User, manager: Employee, joinerUser: User}
 */
function ckTeam(): array
{
    $company = ckCompany();
    $hr = ckHr($company);
    $managerUser = ckUser($company);
    $manager = ckEmployee($company, 'Kamran Manager', $managerUser);
    $joinerUser = ckUser($company);

    test()->actingAs($hr);

    return compact('company', 'hr', 'managerUser', 'manager', 'joinerUser');
}

// ---------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------
it('gives every new company the default onboarding and exit templates, once', function () {
    $company = ckCompany('Fresh Templates Co');

    $templates = ChecklistTemplate::query()->where('company_id', $company->id)->with('tasks')->get()->keyBy('type');

    expect($templates)->toHaveCount(2)
        ->and($templates[ChecklistKinds::Onboarding]->tasks)->toHaveCount(10)
        ->and($templates[ChecklistKinds::Offboarding]->tasks)->toHaveCount(9)
        ->and($templates[ChecklistKinds::Onboarding]->is_default)->toBeTrue();

    $cnicTask = $templates[ChecklistKinds::Onboarding]->tasks->firstWhere('code', 'cnic_copy');
    $cnicType = DocumentType::query()->where('company_id', $company->id)->where('code', 'cnic')->first();
    expect($cnicTask->document_type_id)->toBe($cnicType->id)
        ->and($templates[ChecklistKinds::Onboarding]->tasks->firstWhere('code', 'bank_details')->responsible_role)->toBe(ChecklistKinds::RoleEmployee)
        ->and($templates[ChecklistKinds::Offboarding]->tasks->firstWhere('code', 'disable_user')->action_key)->toBe(ChecklistKinds::ActionDisableUser)
        ->and($templates[ChecklistKinds::Offboarding]->tasks->firstWhere('code', 'experience_letter')->action_key)->toBe(ChecklistKinds::ActionExperienceLetter);

    // Idempotent: provisioning again (or the command for every company) adds nothing.
    $templates[ChecklistKinds::Onboarding]->tasks->first()->delete();
    expect(DefaultChecklistTemplates::provisionForCompany($company->id))->toBe(0);

    $this->artisan('hr:provision-checklists')->assertSuccessful();

    expect(ChecklistTemplate::query()->where('company_id', $company->id)->count())->toBe(2)
        ->and($templates[ChecklistKinds::Onboarding]->tasks()->count())->toBe(9);

    // Every existing company (Truck It In, ShipLine, OpenPort...) has exactly one default of each type.
    $defaults = ChecklistTemplate::query()->where('code', DefaultChecklistTemplates::Code)->get()->groupBy('company_id');
    expect(Company::query()->pluck('id')->diff($defaults->keys()))->toBeEmpty()
        ->and($defaults->every(fn ($rows): bool => $rows->count() === 2))->toBeTrue();
});

// ---------------------------------------------------------------------
// Onboarding
// ---------------------------------------------------------------------
it('starts onboarding by itself for a new joiner, once, with assignees and due dates', function () {
    ['company' => $company, 'hr' => $hr, 'managerUser' => $managerUser, 'manager' => $manager, 'joinerUser' => $joinerUser] = ckTeam();
    $otherHr = ckHr($company);

    $joiner = ckEmployee($company, 'Ali Joiner', $joinerUser, ['parent_id' => $manager->id, 'joining_date' => '2026-11-02']);

    $checklists = EmployeeChecklist::query()->where('employee_id', $joiner->id)->get();
    expect($checklists)->toHaveCount(1);

    $checklist = $checklists->first();
    expect($checklist->type)->toBe(ChecklistKinds::Onboarding)
        ->and($checklist->auto_started)->toBeTrue()
        ->and($checklist->anchor_date->toDateString())->toBe('2026-11-02')
        ->and($checklist->tasks()->count())->toBe(10);

    // Assignees by role: the employee, the line manager, HR queue (null).
    expect(ckTask($checklist, 'bank_details')->assignee_id)->toBe($joinerUser->id)
        ->and(ckTask($checklist, 'induction')->assignee_id)->toBe($managerUser->id)
        ->and(ckTask($checklist, 'cnic_copy')->assignee_id)->toBeNull()
        // Days from the joining date; never before the start day.
        ->and(ckTask($checklist, 'bank_details')->due_date->toDateString())->toBe('2026-11-05')
        ->and(ckTask($checklist, 'offer_letter')->due_date->toDateString())->toBe('2026-10-28')
        // Already true: user linked, line manager set.
        ->and(ckTask($checklist, 'user_account')->status)->toBe(ChecklistKinds::TaskDone)
        ->and(ckTask($checklist, 'user_account')->completion_source)->toBe(ChecklistKinds::SourceSystem)
        ->and(ckTask($checklist, 'line_manager')->status)->toBe(ChecklistKinds::TaskDone);

    // Each assignee is told once; the HR user who created the employee is not told about their own queue.
    expect(ckTitles($joinerUser))->toBe(['Onboarding of Ali Joiner: 1 task for you'])
        ->and(ckTitles($managerUser))->toBe(['Onboarding of Ali Joiner: 2 tasks for you'])
        ->and(ckTitles($otherHr))->toHaveCount(1)
        ->and(ckTitles($hr))->toBe([]);

    // Idempotent: saving again, starting again (auto or by HR) never makes a second one.
    $joiner->update(['job_title' => 'Driver']);
    app(EmployeeChecklistService::class)->autoStartOnboarding($joiner->fresh());
    $again = app(EmployeeChecklistService::class)->startOnboarding($joiner->fresh(), $hr);

    expect($again->id)->toBe($checklist->id)
        ->and(EmployeeChecklist::query()->where('employee_id', $joiner->id)->count())->toBe(1)
        ->and(ckTitles($joinerUser))->toHaveCount(1);
});

it('does not start onboarding for back-filled or inactive employees, or without a joining date', function () {
    ['company' => $company] = ckTeam();

    $old = ckEmployee($company, 'Joined Long Ago', null, ['joining_date' => '2024-01-15']);
    $none = ckEmployee($company, 'No Joining Date');
    $inactive = ckEmployee($company, 'Inactive Joiner', null, ['joining_date' => '2026-10-28', 'is_active' => false]);

    expect(EmployeeChecklist::query()->whereIn('employee_id', [$old->id, $none->id, $inactive->id])->count())->toBe(0);
});

it('completes linked tasks when the document is uploaded, by HR or by the employee', function () {
    ['company' => $company, 'hr' => $hr, 'manager' => $manager, 'joinerUser' => $joinerUser] = ckTeam();
    $joiner = ckEmployee($company, 'Sara Joiner', $joinerUser, ['parent_id' => $manager->id, 'joining_date' => '2026-10-28']);
    $checklist = app(EmployeeChecklistService::class)->openChecklist($joiner, ChecklistKinds::Onboarding);
    $type = fn (string $code): int => DocumentType::query()->where('company_id', $company->id)->where('code', $code)->value('id');

    $cnic = app(EmployeeDocumentService::class)->upload($joiner, $hr, ['document_type_id' => $type('cnic')], ckPdf());

    expect(ckTask($checklist, 'cnic_copy')->status)->toBe(ChecklistKinds::TaskDone)
        ->and(ckTask($checklist, 'cnic_copy')->completion_source)->toBe(ChecklistKinds::SourceDocument)
        ->and(ckTask($checklist, 'cnic_copy')->employee_document_id)->toBe($cnic->id)
        ->and(ckTask($checklist, 'contract_signed')->status)->toBe(ChecklistKinds::TaskPending);

    // The employee uploads their own bank details (a type visible to them).
    $this->actingAs($joinerUser);
    app(EmployeeDocumentService::class)->upload($joiner, $joinerUser, ['document_type_id' => $type('bank_details')], ckPdf());

    expect(ckTask($checklist, 'bank_details')->status)->toBe(ChecklistKinds::TaskDone)
        ->and(ckTask($checklist, 'bank_details')->completed_by)->toBe($joinerUser->id);

    // A document already on file completes the task as soon as a checklist starts.
    $this->actingAs($hr);
    $second = ckEmployee($company, 'Has Documents', null, ['joining_date' => '2024-01-01']);
    app(EmployeeDocumentService::class)->upload($second, $hr, ['document_type_id' => $type('offer_letter')], ckPdf());
    $started = app(EmployeeChecklistService::class)->startOnboarding($second, $hr);

    expect(ckTask($started, 'offer_letter')->status)->toBe(ChecklistKinds::TaskDone)
        ->and(ckTask($started, 'offer_letter')->due_date->toDateString())->toBe('2026-10-28');
});

// ---------------------------------------------------------------------
// Exit
// ---------------------------------------------------------------------
it('starts an exit with the leaving details and disables the login only on the last working day, audited', function () {
    ['company' => $company, 'hr' => $hr, 'manager' => $manager] = ckTeam();
    $leaverUser = ckUser($company);
    $leaver = ckEmployee($company, 'Omar Leaver', $leaverUser, ['parent_id' => $manager->id, 'joining_date' => '2023-03-01', 'employment_status' => 'active']);
    $reason = DepartureReason::query()->firstOrCreate(['name' => 'Resigned'], ['reason_code' => 9001]);

    $checklist = app(EmployeeChecklistService::class)->startExit($leaver, $hr, [
        'notice_period_days'    => 30,
        'last_working_day'      => '2026-11-27',
        'leaving_date'          => '2026-11-30',
        'departure_reason_id'   => $reason->id,
        'departure_description' => 'Moving to Lahore',
    ]);

    $leaver->refresh();
    expect($leaver->leaving_date->toDateString())->toBe('2026-11-30')
        ->and($leaver->departure_reason_id)->toBe($reason->id)
        ->and($leaver->departure_description)->toBe('Moving to Lahore')
        ->and($leaver->employment_status)->toBe('notice')
        ->and($checklist->type)->toBe(ChecklistKinds::Offboarding)
        ->and($checklist->notice_period_days)->toBe(30)
        ->and($checklist->previous_values['employment_status'])->toBe('active')
        ->and($checklist->tasks()->count())->toBe(9)
        ->and(ckTask($checklist, 'handover')->due_date->toDateString())->toBe('2026-11-24')
        // Starting the exit never disables the login.
        ->and($leaverUser->fresh()->is_active)->toBeTrue();

    expect(fn () => app(EmployeeChecklistService::class)->startExit($leaver->fresh(), $hr, ['last_working_day' => '2026-11-27', 'leaving_date' => '2026-11-30']))
        ->toThrow(ValidationException::class);

    // One click: disable on the last working day.
    $disableTask = app(EmployeeChecklistService::class)->scheduleUserDisable(ckTask($checklist, 'disable_user'), $hr);
    expect($disableTask->scheduled_for->toDateString())->toBe('2026-11-27')
        ->and($disableTask->status)->toBe(ChecklistKinds::TaskPending);

    // The day before: nothing happens.
    $this->artisan('hr:checklist-disable-users', ['--date' => '2026-11-26'])->expectsOutputToContain('0 user account(s) disabled')->assertSuccessful();
    expect($leaverUser->fresh()->is_active)->toBeTrue();

    // On the day: disabled, task done, recorded on the checklist and the employee's chatter.
    $this->artisan('hr:checklist-disable-users', ['--date' => '2026-11-27'])->expectsOutputToContain('1 user account(s) disabled')->assertSuccessful();

    $task = ckTask($checklist, 'disable_user');
    expect($leaverUser->fresh()->is_active)->toBeFalse()
        ->and($task->status)->toBe(ChecklistKinds::TaskDone)
        ->and($task->completion_source)->toBe(ChecklistKinds::SourceSystem)
        ->and($task->note)->toContain('disabled automatically on 27 Nov 2026')
        ->and($checklist->fresh()->disabled_user_id)->toBe($leaverUser->id)
        ->and($checklist->fresh()->user_disabled_at)->not->toBeNull();

    $audit = Message::query()->where('messageable_type', $leaver->getMorphClass())->where('messageable_id', $leaver->id)->where('event', 'user_disabled')->get();
    expect($audit)->toHaveCount(1)
        ->and($audit->first()->causer_id)->toBe($hr->id);

    // Idempotent.
    $this->artisan('hr:checklist-disable-users', ['--date' => '2026-11-28'])->expectsOutputToContain('0 user account(s) disabled')->assertSuccessful();
    expect(Message::query()->where('messageable_id', $leaver->id)->where('event', 'user_disabled')->count())->toBe(1);
});

it('undoes an exit before the leaving date, restoring the employee and the login, and refuses after it', function () {
    ['company' => $company, 'hr' => $hr] = ckTeam();
    $user = ckUser($company);
    $employee = ckEmployee($company, 'Stays After All', $user, ['joining_date' => '2022-05-01', 'employment_status' => 'active']);

    $checklist = app(EmployeeChecklistService::class)->startExit($employee, $hr, ['last_working_day' => '2026-11-10', 'leaving_date' => '2026-11-15']);
    app(EmployeeChecklistService::class)->scheduleUserDisable(ckTask($checklist, 'disable_user'), $hr);

    // The login was disabled on the last working day; the person is re-hired before the leaving date.
    $this->artisan('hr:checklist-disable-users', ['--date' => '2026-11-10'])->assertSuccessful();
    expect($user->fresh()->is_active)->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Karachi')->utc());
    app(EmployeeChecklistService::class)->cancel($checklist->fresh(), $hr, 'Resignation withdrawn');

    $employee->refresh();
    expect($employee->leaving_date)->toBeNull()
        ->and($employee->employment_status)->toBe('active')
        ->and($employee->departure_date)->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($checklist->fresh()->status)->toBe(ChecklistKinds::ChecklistCancelled)
        ->and(EmployeeChecklistTask::query()->where('checklist_id', $checklist->id)->where('status', ChecklistKinds::TaskPending)->count())->toBe(0);

    // A new exit can start again; after its leaving date it can no longer be undone.
    $second = app(EmployeeChecklistService::class)->startExit($employee, $hr, ['last_working_day' => '2026-11-20', 'leaving_date' => '2026-11-20']);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Karachi')->utc());

    expect(fn () => app(EmployeeChecklistService::class)->cancel($second->fresh(), $hr))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------
// Overdue reminders
// ---------------------------------------------------------------------
it('reminds assignees of overdue tasks at most once per task per day', function () {
    ['company' => $company, 'hr' => $hr, 'managerUser' => $managerUser, 'manager' => $manager, 'joinerUser' => $joinerUser] = ckTeam();
    $otherHr = ckHr($company);
    $joiner = ckEmployee($company, 'Late Paperwork', $joinerUser, ['parent_id' => $manager->id, 'joining_date' => '2026-10-28']);
    $checklist = app(EmployeeChecklistService::class)->openChecklist($joiner, ChecklistKinds::Onboarding);
    $before = count(ckTitles($managerUser));

    // Nothing is overdue yet.
    $this->artisan('hr:checklist-reminders', ['--company' => $company->id, '--date' => '2026-10-28'])->expectsOutputToContain('0 notification(s)')->assertSuccessful();

    // 2 Nov: induction (due 30 Oct) is overdue for the manager; HR-queue tasks for HR.
    $this->artisan('hr:checklist-reminders', ['--company' => $company->id, '--date' => '2026-11-02'])->assertSuccessful();
    $titles = ckTitles($managerUser);
    expect($titles)->toHaveCount($before + 1)
        ->and($titles)->toContain('1 onboarding / exit task overdue')
        ->and(collect(ckTitles($otherHr))->filter(fn (string $title): bool => str_contains($title, 'overdue')))->toHaveCount(1);

    // Same day again: nothing new.
    $this->artisan('hr:checklist-reminders', ['--company' => $company->id, '--date' => '2026-11-02'])->expectsOutputToContain('0 notification(s)')->assertSuccessful();
    expect(ckTitles($managerUser))->toHaveCount($before + 1);

    // Next day: reminded again; a done task never.
    app(EmployeeChecklistService::class)->complete(ckTask($checklist, 'induction'), $managerUser);
    $this->artisan('hr:checklist-reminders', ['--company' => $company->id, '--date' => '2026-11-03'])->assertSuccessful();
    expect(ckTitles($managerUser))->toHaveCount($before + 1)
        ->and(collect(ckTitles($otherHr))->filter(fn (string $title): bool => str_contains($title, 'overdue')))->toHaveCount(2);
});

// ---------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------
it('lets HR manage, line managers and assignees complete their own tasks, and keeps other companies out', function () {
    ['company' => $company, 'hr' => $hr, 'managerUser' => $managerUser, 'manager' => $manager, 'joinerUser' => $joinerUser] = ckTeam();
    $joiner = ckEmployee($company, 'Bilal Joiner', $joinerUser, ['parent_id' => $manager->id, 'joining_date' => '2026-10-29']);
    $checklist = app(EmployeeChecklistService::class)->openChecklist($joiner, ChecklistKinds::Onboarding);
    $service = app(EmployeeChecklistService::class);
    $policy = app(EmployeeChecklistTaskPolicy::class);
    $plainColleague = ckUser($company);

    $other = ckCompany('Other Checklist Co');
    $otherHr = ckHr($other);

    // Line manager: own task yes, HR's task / start exit no.
    $this->actingAs($managerUser);
    expect($service->complete(ckTask($checklist, 'induction'), $managerUser, 'Done on day one')->status)->toBe(ChecklistKinds::TaskDone)
        ->and($policy->seesAllTasksOf($managerUser, $joiner))->toBeTrue();
    expect(fn () => $service->complete(ckTask($checklist, 'cnic_copy'), $managerUser))->toThrow(AuthorizationException::class);
    expect(fn () => $service->startExit($joiner, $managerUser, ['last_working_day' => '2026-12-01', 'leaving_date' => '2026-12-01']))->toThrow(AuthorizationException::class);

    // The employee: own "employee" task only, sees only those.
    $this->actingAs($joinerUser);
    expect(fn () => $service->skip(ckTask($checklist, 'bank_details'), $joinerUser, ''))->toThrow(ValidationException::class);
    expect($service->skip(ckTask($checklist, 'bank_details'), $joinerUser, 'Gave the cheque to HR in person')->status)->toBe(ChecklistKinds::TaskSkipped)
        ->and($policy->view($joinerUser, ckTask($checklist, 'cnic_copy')))->toBeFalse()
        ->and($policy->seesAllTasksOf($joinerUser, $joiner))->toBeFalse();
    expect(fn () => $service->complete(ckTask($checklist, 'cnic_copy'), $joinerUser))->toThrow(AuthorizationException::class);

    // A colleague with no role: nothing.
    $this->actingAs($plainColleague);
    expect($policy->view($plainColleague, ckTask($checklist, 'cnic_copy')))->toBeFalse();
    expect(fn () => $service->complete(ckTask($checklist, 'equipment_issued'), $plainColleague))->toThrow(AuthorizationException::class);

    // HR of another company: nothing here, and the task is not in their list.
    $this->actingAs($otherHr);
    expect(fn () => $service->complete(ckTask($checklist, 'cnic_copy'), $otherHr))->toThrow(AuthorizationException::class);
    expect(fn () => $service->startExit($joiner, $otherHr, ['last_working_day' => '2026-12-01', 'leaving_date' => '2026-12-01']))->toThrow(AuthorizationException::class);
    expect($service->myTasksQuery($otherHr, $company->id)->count())->toBe(0)
        ->and($service->myTasksQuery($otherHr, $other->id)->where('employee_id', $joiner->id)->count())->toBe(0);

    // HR: the queue, reopen, reassign.
    $this->actingAs($hr);
    expect($service->myTasksQuery($hr, $company->id)->pluck('code')->all())->toContain('cnic_copy', 'equipment_issued')
        ->not->toContain('induction', 'bank_details');
    $service->complete(ckTask($checklist, 'cnic_copy'), $hr);
    expect($service->reopen(ckTask($checklist, 'cnic_copy'), $hr)->status)->toBe(ChecklistKinds::TaskPending);
    $service->reassign(ckTask($checklist, 'equipment_issued'), $hr, $plainColleague->id);
    expect(ckTitles($plainColleague))->toBe(['New onboarding task: Equipment issued (laptop, SIM, ID card)']);

    $this->actingAs($plainColleague);
    expect($service->complete(ckTask($checklist, 'equipment_issued'), $plainColleague)->status)->toBe(ChecklistKinds::TaskDone);
    expect(fn () => $service->reopen(ckTask($checklist, 'equipment_issued'), $plainColleague))->toThrow(AuthorizationException::class);
});

it('shows each person their own tasks on My tasks and counts them on the home page', function () {
    ['company' => $company, 'managerUser' => $managerUser, 'manager' => $manager, 'joinerUser' => $joinerUser] = ckTeam();
    ckEmployee($company, 'Zara Joiner', $joinerUser, ['parent_id' => $manager->id, 'joining_date' => '2026-10-28']);

    $this->actingAs($managerUser);
    Livewire::test(MyChecklistTasks::class)
        ->assertOk()
        ->assertSee('Induction and introductions')
        ->assertSee('Zara Joiner')
        ->assertDontSee('CNIC copy collected')
        ->assertDontSee('Bank details provided');

    $counts = app(EmployeeChecklistService::class)->openTaskCounts($managerUser, $company->id);
    expect($counts)->toBe(['open' => 2, 'overdue' => 0]);

    $this->actingAs($joinerUser);
    Livewire::test(MyChecklistTasks::class)
        ->assertSee('Bank details provided')
        ->assertDontSee('Induction and introductions');
});

it('drives the HR screens: start exit from the employee page, the tasks tab, the list and the templates', function () {
    ['company' => $company, 'hr' => $hr, 'manager' => $manager] = ckTeam();
    $leaverUser = ckUser($company);
    $leaver = ckEmployee($company, 'Nadia Leaver', $leaverUser, ['parent_id' => $manager->id, 'joining_date' => '2024-02-01']);

    Livewire::test(ViewEmployee::class, ['record' => $leaver->getRouteKey()])
        ->assertOk()
        ->assertActionVisible('start_exit')
        ->assertActionHidden('undo_exit')
        ->callAction('start_exit', data: [
            'notice_period_days' => 30,
            'last_working_day'   => '2026-11-27',
            'leaving_date'       => '2026-11-30',
        ])
        ->assertHasNoActionErrors();

    $checklist = app(EmployeeChecklistService::class)->openChecklist($leaver, ChecklistKinds::Offboarding);
    expect($checklist)->not->toBeNull()
        ->and($leaver->fresh()->leaving_date->toDateString())->toBe('2026-11-30');

    Livewire::test(ViewEmployee::class, ['record' => $leaver->getRouteKey()])
        ->assertActionHidden('start_exit')
        ->assertActionVisible('undo_exit');

    $disable = ckTask($checklist, 'disable_user');
    Livewire::test(ChecklistTasksRelationManager::class, ['ownerRecord' => $leaver, 'pageClass' => ViewEmployee::class])
        ->assertOk()
        ->assertSee('Exit: 0 of 9 done')
        ->assertCanSeeTableRecords([$disable])
        ->callTableAction('schedule_user_disable', $disable)
        ->callTableAction('complete', ckTask($checklist, 'exit_interview'), data: ['note' => 'Held on Friday']);

    expect(ckTask($checklist, 'disable_user')->scheduled_for->toDateString())->toBe('2026-11-27')
        ->and(ckTask($checklist, 'exit_interview')->note)->toBe('Held on Friday')
        ->and($leaverUser->fresh()->is_active)->toBeTrue();

    Livewire::test(OnboardingAndExits::class)
        ->assertOk()
        ->assertSee('Nadia Leaver')
        ->assertSee('1 / 9');

    Livewire::test(ListChecklistTemplates::class)
        ->assertOk()
        ->assertSee('New joiner onboarding')
        ->assertSee('Employee exit');

    // Line managers see the tab but not the HR buttons; other employees do not see the tab.
    $this->actingAs(User::query()->find($manager->user_id));
    expect(ChecklistTasksRelationManager::canViewForRecord($leaver, ViewEmployee::class))->toBeTrue();
    $this->actingAs(ckUser($company));
    expect(ChecklistTasksRelationManager::canViewForRecord($leaver, ViewEmployee::class))->toBeFalse()
        ->and(OnboardingAndExits::canAccess())->toBeFalse();

    // The home page's pending actions count the HR queue for HR.
    $items = collect(app(RoleActionCenterService::class)->getPendingActionsFor($hr, $company->id)['items']);
    expect($items->firstWhere('id', 'checklist_tasks_open')['url'] ?? null)->toBe(route('filament.admin.pages.my-tasks'));
});

it('completes tasks the system can see: a linked login, a manager, an issued experience letter', function () {
    ['company' => $company, 'hr' => $hr, 'managerUser' => $managerUser, 'manager' => $manager] = ckTeam();
    $joiner = ckEmployee($company, 'No Login Yet', null, ['joining_date' => '2026-10-28']);
    $checklist = app(EmployeeChecklistService::class)->openChecklist($joiner, ChecklistKinds::Onboarding);

    // No login and no manager yet: those tasks wait in the HR queue.
    expect(ckTask($checklist, 'bank_details')->assignee_id)->toBeNull()
        ->and(ckTask($checklist, 'induction')->assignee_id)->toBeNull()
        ->and(ckTask($checklist, 'user_account')->status)->toBe(ChecklistKinds::TaskPending);

    $joinerUser = ckUser($company);
    $joiner->update(['user_id' => $joinerUser->id, 'parent_id' => $manager->id]);

    expect(ckTask($checklist, 'user_account')->status)->toBe(ChecklistKinds::TaskDone)
        ->and(ckTask($checklist, 'line_manager')->status)->toBe(ChecklistKinds::TaskDone)
        ->and(ckTask($checklist, 'bank_details')->assignee_id)->toBe($joinerUser->id)
        ->and(ckTask($checklist, 'induction')->assignee_id)->toBe($managerUser->id)
        ->and(ckTitles($joinerUser))->toBe(['New onboarding task: Bank details provided']);

    // Exit: issuing the experience letter request completes the letter task.
    $exit = app(EmployeeChecklistService::class)->startExit($joiner->fresh(), $hr, ['last_working_day' => '2026-12-31', 'leaving_date' => '2026-12-31']);
    $letterType = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', EmployeeRequestKinds::HrLetterCode)->firstOrFail();
    $request = EmployeeRequest::query()->create([
        'employee_id'     => $joiner->id,
        'request_type_id' => $letterType->id,
        'status'          => 'approved',
        'title'           => 'Experience letter',
        'payload'         => ['letter_type' => 'experience_letter'],
    ]);
    expect(ckTask($exit, 'experience_letter')->status)->toBe(ChecklistKinds::TaskPending);

    $request->update(['payload' => ['letter_type' => 'experience_letter', 'letter_issued_at' => now()->toIso8601String()]]);
    expect(ckTask($exit, 'experience_letter')->status)->toBe(ChecklistKinds::TaskDone)
        ->and(ckTask($exit, 'experience_letter')->completion_source)->toBe(ChecklistKinds::SourceSystem);
});
