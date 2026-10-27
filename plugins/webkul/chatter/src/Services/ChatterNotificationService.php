<?php

namespace Webkul\Chatter\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Chatter\Mail\MessageMail;
use Webkul\Chatter\Models\Message;
use Webkul\Chatter\Notifications\ChatterDatabaseNotification;
use Webkul\Chatter\Support\ChatterMentions;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;

class ChatterNotificationService
{
    protected string $mailView = 'chatter::mail.message-mail';

    public function notifyFollowers(Message $message): void
    {
        $this->viaDatabase($message);

        $this->viaEmail($message);
    }

    protected function viaEmail(Message $message): void
    {
        $record = $message->messageable;

        if (! $record || ! method_exists($record, 'followers')) {
            return;
        }

        $followers = $record->followers()->with('partner')->get();

        if ($followers->isEmpty()) {
            return;
        }

        $causer = $message->causer;
        $authorPartnerId = $this->resolveAuthorPartnerId($causer);

        $from = $this->resolveFrom($message, $causer, $record);
        $recordName = $this->resolveRecordName($record);
        $recordUrl = $this->resolveRecordUrl($record);

        foreach ($followers as $follower) {
            $partner = $follower->partner;

            if (! $partner?->email) {
                continue;
            }

            if ($authorPartnerId && (int) $partner->id === (int) $authorPartnerId) {
                continue;
            }

            $payload = [
                'record_url'  => $recordUrl,
                'record_name' => $recordName,
                'model_name'  => class_basename($record),
                'subject'     => __('chatter::filament/resources/actions/chatter/message-action.setup.actions.mail.subject', [
                    'record_name' => $recordName,
                ]),
                'content'     => $this->buildContent($message),
                'from'        => $from,
                'to'          => [
                    'address' => $partner->email,
                    'name'    => $partner->name,
                ],
            ];

            try {
                Mail::to($partner->email, '"'.addslashes((string) $partner->name).'"')
                    ->send(new MessageMail($this->mailView, $payload));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    protected function viaDatabase(Message $message): void
    {
        $record = $message->messageable;

        if (! $record || ! method_exists($record, 'followers')) {
            return;
        }

        $causerUserId = $this->resolveCauserUserId($message->causer);
        $recordName = $this->resolveRecordName($record);
        $recordUrl = $this->resolveRecordUrl($record);
        $causerName = $this->resolveCauserName($message, $record);

        $mentionedUserIds = $this->notifyMentions($message, $record, $causerUserId, $causerName, $recordName, $recordUrl);

        if ($message->type === 'activity') {
            $this->notifyActivityAssignee($message, $causerUserId, $mentionedUserIds, $causerName, $recordName, $recordUrl);

            return;
        }

        $assignedUserId = $this->resolveAssignedUserId($message, $record);

        if (! $assignedUserId && $message->assigned_to) {
            $assignedUserId = (int) $message->assigned_to;
        }

        if ($assignedUserId && $assignedUserId !== $causerUserId && ! in_array($assignedUserId, $mentionedUserIds, true)) {
            $this->sendAssignedNotification($assignedUserId, $causerName, $recordName, $recordUrl);
        }

        $excludedIds = array_merge([$causerUserId, $assignedUserId], $mentionedUserIds);

        $recipients = $this->resolveFollowerUsers($record, $excludedIds);

        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $user) {
            [$title, $body, $icon, $color] = $this->resolveNotificationPayload($message, $record, $causerName, $recordName, $user);

            $user->notify(new ChatterDatabaseNotification($title, $body, $icon, $color, $recordUrl));
        }
    }

    protected function resolveAssignedUserId(Message $message, Model $record): ?int
    {
        if ($message->type !== 'notification' || ! method_exists($record, 'resolveChatterAssignedUserId')) {
            return null;
        }

        $properties = is_array($message->properties) ? $message->properties : [];

        return $record->resolveChatterAssignedUserId($properties);
    }

    protected function sendAssignedNotification(int $assigneeId, string $causerName, string $recordName, string $recordUrl): void
    {
        $assignee = User::find($assigneeId);

        if (! $assignee) {
            return;
        }

        $assignee->notify(new ChatterDatabaseNotification(
            __('chatter::notifications.database.assigned.title', ['causer' => $causerName, 'record' => $recordName]),
            __('chatter::notifications.database.assigned.body', ['record' => $recordName]),
            'heroicon-o-user-plus',
            'info',
            $recordUrl,
        ));
    }

    protected function summarizeChanges(Message $message): ?string
    {
        $changes = is_array($message->properties) ? $message->properties : [];

        if (empty($changes)) {
            return null;
        }

        $rows = [];

        foreach ($changes as $field => $change) {
            if (! is_array($change)) {
                continue;
            }

            $label = ucwords(str_replace('_', ' ', (string) $field));
            $old = $change['old_value'] ?? null;
            $new = $change['new_value'] ?? null;
            $old = is_array($old) ? implode(', ', $old) : $old;
            $new = is_array($new) ? implode(', ', $new) : $new;

            if (isset($change['old_value']) && isset($change['new_value'])) {
                $rows[] = $label.': '.$old.' → '.$new;
            } elseif (isset($change['new_value'])) {
                $rows[] = $label.': '.$new;
            }
        }

        if (empty($rows)) {
            return null;
        }

        return Str::limit(implode(' · ', $rows), 160);
    }

    protected function notifyMentions(Message $message, Model $record, ?int $causerUserId, string $causerName, string $recordName, string $recordUrl): array
    {
        $mentionedIds = ChatterMentions::extractUserIds($message->body);

        if (empty($mentionedIds)) {
            return [];
        }

        $companyId = $this->resolveRecordCompanyId($message, $record);

        if ($companyId === null) {
            return [];
        }

        $users = CurrentCompany::scopeUsersOf(User::with('partner'), $companyId)
            ->whereIn('id', $mentionedIds)
            ->when($causerUserId, fn ($query) => $query->where('id', '!=', $causerUserId))
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        $title = __('chatter::notifications.database.mention.title', ['causer' => $causerName, 'record' => $recordName]);
        $body = $this->plainBody($message);

        foreach ($users as $user) {
            if ($user->partner) {
                $record->addFollower($user->partner);
            }

            $user->notify(new ChatterDatabaseNotification($title, $body, 'heroicon-o-at-symbol', 'warning', $recordUrl));
        }

        return $users->pluck('id')->all();
    }

    protected function notifyActivityAssignee(Message $message, ?int $causerUserId, array $mentionedUserIds, string $causerName, string $recordName, string $recordUrl): void
    {
        $assigneeId = $message->assigned_to;

        if (! $assigneeId || (int) $assigneeId === (int) $causerUserId || in_array((int) $assigneeId, $mentionedUserIds, true)) {
            return;
        }

        $assignee = User::find($assigneeId);

        if (! $assignee) {
            return;
        }

        $assignee->notify(new ChatterDatabaseNotification(
            __('chatter::notifications.database.activity.title', ['causer' => $causerName, 'record' => $recordName]),
            $this->plainBody($message),
            'heroicon-o-clock',
            'info',
            $recordUrl,
        ));
    }

    protected function resolveFollowerUsers(Model $record, array $excludedIds)
    {
        $excludedIds = array_filter($excludedIds);

        $users = $record->followers()
            ->with('partner.user')
            ->get()
            ->map(fn ($follower) => $follower->partner?->user)
            ->filter()
            ->reject(fn (User $user) => in_array((int) $user->id, array_map('intval', $excludedIds), true))
            ->unique('id')
            ->values();

        $companyId = $record->getAttribute('company_id') ? (int) $record->getAttribute('company_id') : null;

        if ($companyId === null || $users->isEmpty()) {
            return $users;
        }

        $memberIds = CurrentCompany::scopeUsersOf(User::query(), $companyId)
            ->whereIn('id', $users->pluck('id'))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $users->filter(fn (User $user): bool => in_array((int) $user->id, $memberIds, true))->values();
    }

    /** The company a chattered record belongs to: its own, else the message's, else the active company. */
    protected function resolveRecordCompanyId(Message $message, Model $record): ?int
    {
        $companyId = $record->getAttribute('company_id') ?: $message->company_id ?: CurrentCompany::id();

        return $companyId ? (int) $companyId : null;
    }

    protected function resolveTypeMeta(Message $message): array
    {
        if ($message->type === 'notification') {
            return match ($message->event) {
                'created' => ['chatter::notifications.database.created.title', 'heroicon-o-plus-circle', 'success'],
                default   => ['chatter::notifications.database.updated.title', 'heroicon-o-pencil-square', 'primary'],
            };
        }

        return ['chatter::notifications.database.message.title', 'heroicon-o-chat-bubble-left-ellipsis', 'primary'];
    }

    protected function resolveCauserUserId(mixed $causer): ?int
    {
        if (! $causer) {
            return null;
        }

        if ($causer instanceof User) {
            return (int) $causer->id;
        }

        return isset($causer->user_id) ? (int) $causer->user_id : null;
    }

    protected function plainBody(Message $message): string
    {
        $content = strip_tags($message->body ?? $message->summary ?? '');

        return Str::limit(trim($content), 140);
    }

    protected function buildContent(Message $message): string
    {
        $content = $message->body ?? $message->summary ?? '';

        $changes = is_array($message->properties) ? $message->properties : [];

        if ($message->event === 'created' || empty($changes)) {
            return $content;
        }

        $rows = [];

        foreach ($changes as $field => $change) {
            if (! is_array($change)) {
                continue;
            }

            $label = ucwords(str_replace('_', ' ', (string) $field));
            $old = $change['old_value'] ?? null;
            $new = $change['new_value'] ?? null;
            $old = is_array($old) ? implode(', ', $old) : $old;
            $new = is_array($new) ? implode(', ', $new) : $new;

            if (isset($change['old_value']) && isset($change['new_value'])) {
                $rows[] = e($label).': '.e((string) $old).' → '.e((string) $new);
            } elseif (isset($change['new_value'])) {
                $rows[] = e($label).': '.e((string) $new);
            }
        }

        if (empty($rows)) {
            return $content;
        }

        return $content.'<ul><li>'.implode('</li><li>', $rows).'</li></ul>';
    }

    protected function resolveAuthorPartnerId(mixed $causer): ?int
    {
        if (! $causer) {
            return null;
        }

        if ($causer instanceof Partner) {
            return (int) $causer->id;
        }

        return $causer->partner_id ? (int) $causer->partner_id : null;
    }

    protected function resolveFrom(Message $message, mixed $causer, mixed $record): array
    {
        $from = [
            'address' => $causer?->email ?? config('mail.from.address'),
            'name'    => $causer?->name ?? config('mail.from.name'),
        ];

        $company = $message->company ?? ($record->company ?? null);

        if ($company) {
            $from['company'] = $company->toArray();
        }

        return $from;
    }

    protected function resolveCauserName(Message $message, ?Model $record): string
    {
        if (filled($message->causer?->name)) {
            return (string) $message->causer->name;
        }

        if ($record) {
            if (method_exists($record, 'employee') && filled($record->employee?->name)) {
                return (string) $record->employee->name;
            }

            if ($record->getAttribute('employee_id')) {
                $employee = Employee::find($record->getAttribute('employee_id'));
                if (filled($employee?->name)) {
                    return (string) $employee->name;
                }
            }

            if (method_exists($record, 'creator') && filled($record->creator?->name)) {
                return (string) $record->creator->name;
            }

            if ($record->getAttribute('creator_id')) {
                $creator = User::find($record->getAttribute('creator_id'));
                if (filled($creator?->name)) {
                    return (string) $creator->name;
                }
            }

            if (method_exists($record, 'user') && filled($record->user?->name)) {
                return (string) $record->user->name;
            }

            if ($record->getAttribute('user_id')) {
                $user = User::find($record->getAttribute('user_id'));
                if (filled($user?->name)) {
                    return (string) $user->name;
                }
            }

            if (method_exists($record, 'requester') && filled($record->requester?->name)) {
                return (string) $record->requester->name;
            }

            if ($record->getAttribute('requested_by')) {
                $requester = User::find($record->getAttribute('requested_by'));
                if (filled($requester?->name)) {
                    return (string) $requester->name;
                }
            }
        }

        return 'A team member';
    }

    protected function resolveNotificationPayload(
        Message $message,
        Model $record,
        string $causerName,
        string $recordName,
        ?User $recipient = null
    ): array {
        [$titleKey, $icon, $color] = $this->resolveTypeMeta($message);

        $isTargetEmployee = $recipient && (
            (int) $recipient->id === (int) ($record->employee?->user_id ?? 0)
            || (int) $recipient->id === (int) ($record->user_id ?? 0)
        );

        $actorRole = $this->resolveActorRole($record, $message->causer);
        $actorLabel = "{$actorRole} ({$causerName})";

        if ($record instanceof Leave) {
            $employeeName = $record->employee?->name ?? $causerName;
            $days = $record->number_of_days ? ' ('.(float) $record->number_of_days.' days)' : '';

            if ($message->event === 'created') {
                if ($isTargetEmployee && $causerName !== $employeeName) {
                    $title = "{$actorRole} ({$causerName}) created a time off request for you: {$recordName}";
                    $body = "A time off request ({$recordName}{$days}) was created for you by {$actorLabel}.";
                } else {
                    $title = "{$employeeName} requested time off: {$recordName}";
                    $body = "{$employeeName} requested {$recordName}{$days}.";
                }
                $icon = 'heroicon-o-calendar-days';
                $color = 'warning';

                return [$title, $body, $icon, $color];
            }

            if ($isTargetEmployee && $causerName !== $employeeName) {
                $title = "{$actorRole} ({$causerName}) updated your time off: {$recordName}";
                $body = $this->summarizeChanges($message) ?? "Your time off request ({$recordName}) was updated by {$actorLabel}.";
            } else {
                $title = "{$employeeName} updated time off: {$recordName}";
                $body = $this->summarizeChanges($message) ?? "Time off request ({$recordName}) for {$employeeName} was updated.";
            }

            return [$title, $body, $icon, $color];
        }

        if ($record instanceof LeaveAllocation) {
            $employeeName = $record->employee?->name ?? $causerName;
            $days = $record->number_of_days ? ' ('.(float) $record->number_of_days.' days)' : '';

            if ($message->event === 'created') {
                if ($isTargetEmployee && $causerName !== $employeeName) {
                    $title = "{$actorRole} ({$causerName}) allocated {$recordName} for you";
                    $body = "A new leave allocation ({$recordName}) was created for you by {$actorLabel}{$days}.";
                } else {
                    $title = "{$employeeName} requested time off: {$recordName}";
                    $body = "A new leave allocation ({$recordName}) was created for {$employeeName}{$days}.";
                }
                $icon = 'heroicon-o-plus-circle';
                $color = 'success';

                return [$title, $body, $icon, $color];
            }

            if ($isTargetEmployee && $causerName !== $employeeName) {
                $title = "{$actorRole} ({$causerName}) updated your leave allocation: {$recordName}";
                $body = $this->summarizeChanges($message) ?? "Your leave allocation ({$recordName}) was updated by {$actorLabel}.";
            } else {
                $title = "{$employeeName} updated leave allocation: {$recordName}";
                $body = $this->summarizeChanges($message) ?? "Leave allocation ({$recordName}) for {$employeeName} was updated.";
            }

            return [$title, $body, $icon, $color];
        }

        if ($record instanceof EmployeeRequest) {
            $employeeName = $record->employee?->name ?? $record->requester?->name ?? $causerName;
            $kind = $record->payload['kind'] ?? null;

            if ($kind === 'attendance_time_change') {
                $dateStr = $record->payload['formatted_date'] ?? $record->payload['attendance_date'] ?? '';
                if ($isTargetEmployee && $causerName !== $employeeName) {
                    $title = "{$actorRole} ({$causerName}) updated your attendance time change";
                    $body = 'Your attendance time change request'.($dateStr ? " for {$dateStr}" : '')." was updated by {$actorLabel}.";
                } else {
                    $title = "{$employeeName} requested attendance time change";
                    $body = "Attendance time change requested for {$employeeName}".($dateStr ? " ({$dateStr})" : '').'.';
                }
                $icon = 'heroicon-o-clock';
                $color = 'warning';

                return [$title, $body, $icon, $color];
            }

            if ($kind === 'attendance_missing_day') {
                $dateStr = $record->payload['formatted_date'] ?? $record->payload['attendance_date'] ?? '';
                if ($isTargetEmployee && $causerName !== $employeeName) {
                    $title = "{$actorRole} ({$causerName}) updated your missed attendance";
                    $body = 'Your missed attendance request'.($dateStr ? " for {$dateStr}" : '')." was updated by {$actorLabel}.";
                } else {
                    $title = "{$employeeName} requested missed attendance";
                    $body = "Missed attendance day requested for {$employeeName}".($dateStr ? " ({$dateStr})" : '').'.';
                }
                $icon = 'heroicon-o-clock';
                $color = 'warning';

                return [$title, $body, $icon, $color];
            }

            if ($isTargetEmployee && $causerName !== $employeeName) {
                $title = "{$actorRole} ({$causerName}) updated your {$recordName}";
                $body = $record->description ?: "Your {$recordName} request was updated by {$actorLabel}.";
            } else {
                $title = "{$employeeName} requested {$recordName}";
                $body = $record->description ?: "Request submitted by {$employeeName}.";
            }

            return [$title, $body, $icon, $color];
        }

        $title = __($titleKey, ['causer' => $causerName, 'record' => $recordName]);
        $body = $message->type === 'notification'
            ? ($this->summarizeChanges($message) ?? $this->plainBody($message))
            : $this->plainBody($message);

        return [$title, $body, $icon, $color];
    }

    protected function resolveActorRole(Model $record, ?User $causer): string
    {
        if (! $causer) {
            return 'HR';
        }

        $employee = null;
        if (method_exists($record, 'employee') && $record->relationLoaded('employee')) {
            $employee = $record->employee;
        } elseif ($record->getAttribute('employee_id')) {
            $employee = Employee::find($record->getAttribute('employee_id'));
        }

        $causerEmployee = $causer->relationLoaded('employee') ? $causer->employee : Employee::where('user_id', $causer->id)->first();

        if ($employee && $causerEmployee && (int) $employee->parent_id === (int) $causerEmployee->id) {
            return 'Line Manager';
        }

        if ($causer->hasRole(['Admin', 'Super Admin', 'hr', 'hr_manager', 'hr manager', 'hr_ops_manager', 'hr ops manager', 'hr_administrator', 'human resources', 'human resources manager'])
            || $causer->can('hr_approve_leave')
            || $causer->can('hr_manage_attendance')) {
            return 'HR';
        }

        return 'Line Manager';
    }

    protected function resolveRecordName(mixed $record): string
    {
        if ($record instanceof Leave) {
            return $record->holidayStatus?->name ?? 'Time Off';
        }

        if ($record instanceof LeaveAllocation) {
            return $record->name ?? $record->holidayStatus?->name ?? 'Leave Allocation';
        }

        if ($record instanceof EmployeeRequest) {
            return $record->title ?? $record->requestType?->name ?? 'Employee Request';
        }

        $attribute = property_exists($record, 'recordTitleAttribute') ? $record->recordTitleAttribute : null;

        return (string) ($record->name
            ?? ($attribute ? $record->getAttribute($attribute) : null)
            ?? $record->title
            ?? $record->getKey());
    }

    protected function resolveRecordUrl(mixed $record): string
    {
        if (method_exists($record, 'getChatterResourceUrl')) {
            return (string) $record->getChatterResourceUrl();
        }

        return '';
    }
}
