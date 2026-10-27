<?php

/**
 * Multi-company isolation of collaboration pickers (docs/MULTI_COMPANY_ACCESS_PLAN.md §2.4,
 * leaks #10, #21, #24): @mentions, followers, timesheet filters and approver
 * pickers only offer people and records of the active company, and a mention
 * of an outsider never notifies them or makes them a follower.
 */

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Chatter\Filament\Actions\Chatter\FollowerAction;
use Webkul\Chatter\Models\Message;
use Webkul\Chatter\Notifications\ChatterDatabaseNotification;
use Webkul\Chatter\Services\ChatterNotificationService;
use Webkul\Chatter\Support\ChatterMentions;
use Webkul\Partner\Models\Partner;
use Webkul\Project\Models\Project;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar as WebkulPermissionRegistrar;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Filament\Resources\ApprovalWorkflowResource;
use Webkul\Support\Models\Company;
use Webkul\Timesheet\Filament\Resources\TimesheetResource;

/** A user whose active company is $company, who also belongs to $alsoIn. */
function collabMember(Company $company, string $name, array $alsoIn = []): User
{
    $user = User::factory()->create([
        'name' => $name, 'default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::GLOBAL,
    ]);
    $user->allowedCompanies()->sync(collect($alsoIn)->pluck('id')->all());

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(WebkulPermissionRegistrar::class)->forgetCachedPermissions();
    CurrentCompany::flush();

    return $user->refresh();
}

function mentionTag(User $user): string
{
    return '<span data-type="mention" data-id="'.$user->id.'" data-label="'.e($user->name).'">@'.e($user->name).'</span>';
}

beforeEach(function (): void {
    $this->tag = Str::random(6);
    $this->shipLine = Company::factory()->create(['name' => 'ShipLine '.$this->tag, 'is_active' => true]);
    $this->openPort = Company::factory()->create(['name' => 'OpenPort '.$this->tag, 'is_active' => true]);

    $this->author = collabMember($this->shipLine, "Author {$this->tag}");
    $this->colleague = collabMember($this->shipLine, "Colleague {$this->tag}");
    $this->sharedMember = collabMember($this->openPort, "Shared {$this->tag}", [$this->shipLine]);
    $this->outsider = collabMember($this->openPort, "Outsider {$this->tag}");

    Auth::login($this->author);
    CurrentCompany::flush();
});

it('offers only the active company\'s users in the @mention search', function () {
    $results = ChatterMentions::searchUsers($this->tag);

    expect(array_keys($results))
        ->toContain($this->colleague->id, $this->sharedMember->id)
        ->not->toContain($this->outsider->id);
});

it('offers only the active company\'s and shared partners as followers', function () {
    $sharedPartner = Partner::factory()->create(['name' => "Shared partner {$this->tag}", 'company_id' => null]);
    $foreignPartner = Partner::factory()->create(['name' => "Foreign partner {$this->tag}", 'company_id' => $this->openPort->id]);

    $ids = FollowerAction::followerCandidates()->where('name', 'like', "%{$this->tag}%")->pluck('id')->all();

    expect($ids)
        ->toContain($this->colleague->partner_id, $this->sharedMember->partner_id, $sharedPartner->id)
        ->not->toContain($this->outsider->partner_id, $foreignPartner->id);

    expect(fn () => FollowerAction::followerCandidates()->findOrFail($foreignPartner->id))
        ->toThrow(ModelNotFoundException::class);
});

it('does not notify or auto-follow a mentioned user from another company', function () {
    Notification::fake();
    Mail::fake();

    $record = Partner::factory()->create(['name' => "Record {$this->tag}", 'company_id' => $this->shipLine->id]);

    $message = new Message([
        'type' => 'comment',
        'body' => '<p>Please check '.mentionTag($this->colleague).' '.mentionTag($this->outsider).'</p>',
    ]);
    $message->setRelation('messageable', $record);
    $message->setRelation('causer', $this->author);

    app(ChatterNotificationService::class)->notifyFollowers($message);

    $followerPartnerIds = $record->followers()->pluck('partner_id')->map(fn ($id): int => (int) $id)->all();

    Notification::assertSentTo($this->colleague, ChatterDatabaseNotification::class);
    Notification::assertNotSentTo($this->outsider, ChatterDatabaseNotification::class);

    expect($followerPartnerIds)
        ->toContain((int) $this->colleague->partner_id)
        ->not->toContain((int) $this->outsider->partner_id);
});

it('lists only the workflow company\'s users as specific approvers', function () {
    $options = array_keys(ApprovalWorkflowResource::approverUserOptions($this->shipLine->id));

    expect($options)
        ->toContain($this->colleague->id, $this->sharedMember->id)
        ->not->toContain($this->outsider->id);
});

it('limits the timesheet user and project filter options to the active company', function () {
    $ourProject = Project::factory()->create(['name' => "Our project {$this->tag}", 'company_id' => $this->shipLine->id]);
    $theirProject = Project::factory()->create(['name' => "Their project {$this->tag}", 'company_id' => $this->openPort->id]);

    $userIds = TimesheetResource::scopeFilterOptions(User::query(), 'user')->pluck('id')->all();
    $projectIds = TimesheetResource::scopeFilterOptions(Project::query(), 'project')->pluck('id')->all();

    expect($userIds)
        ->toContain($this->colleague->id, $this->sharedMember->id)
        ->not->toContain($this->outsider->id)
        ->and($projectIds)
        ->toContain($ourProject->id)
        ->not->toContain($theirProject->id);
});
