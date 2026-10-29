<?php

namespace Webkul\Employee\Services\Checklists;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Employee\Models\EmployeeChecklist;
use Webkul\Employee\Models\EmployeeChecklistTask;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;

/**
 * In-app notifications of onboarding / exit checklist tasks: to each
 * assignee when tasks are created or handed to them, and the daily overdue
 * reminder. A task without an assignee goes to the company's HR approvers
 * (ApprovalEngine::resolveHrApproversForCompany()). One notification per
 * person, listing their tasks, never one per task.
 */
class ChecklistNotifier
{
    /** @var array<int, array<int, int>> */
    private array $hrApprovers = [];

    public function __construct(
        protected ApprovalEngine $approvals,
    ) {}

    /**
     * Tell each assignee about their new tasks; the person who started the
     * checklist is not told about their own tasks.
     *
     * @param  Collection<int, EmployeeChecklistTask>  $tasks
     */
    public function tasksCreated(EmployeeChecklist $checklist, Collection $tasks, ?User $actor = null): int
    {
        $tasks = $tasks->filter(fn (EmployeeChecklistTask $task): bool => $task->isPending())->values();
        $employeeName = (string) $checklist->employee?->name;
        $notified = 0;

        foreach ($this->byRecipient($tasks, (int) $checklist->company_id) as $userId => $mine) {
            if ($actor !== null && (int) $actor->getKey() === $userId) {
                continue;
            }

            $user = $mine['user'];
            $count = count($mine['tasks']);
            $first = collect($mine['tasks'])->sortBy(fn (EmployeeChecklistTask $task): string => $task->due_date?->toDateString() ?? '9999')->first();

            $this->send($user, Notification::make()
                ->title(ChecklistKinds::typeLabel($checklist->type).' of '.$employeeName.': '.$count.' '.Str::plural('task', $count).' for you')
                ->body('First: '.$first->title.($first->due_date ? ', due '.$first->due_date->format('D j M') : '').'. Mark each one done in My tasks.')
                ->icon($checklist->type === ChecklistKinds::Offboarding ? 'heroicon-o-arrow-right-start-on-rectangle' : 'heroicon-o-user-plus')
                ->color('info')
                ->viewData(['employee_checklist' => $checklist->getKey()])
                ->actions([$this->myTasksAction()]));
            $notified++;
        }

        return $notified;
    }

    /**
     * The daily reminder of overdue tasks, one notification per person.
     *
     * @param  Collection<int, EmployeeChecklistTask>  $tasks  tasks already claimed for today
     */
    public function overdue(Collection $tasks, CarbonImmutable $today): int
    {
        $notified = 0;
        $tasksByCompany = $tasks->groupBy('company_id');

        $recipients = [];
        foreach ($tasksByCompany as $companyId => $companyTasks) {
            foreach ($this->byRecipient($companyTasks, (int) $companyId) as $userId => $mine) {
                $recipients[$userId]['user'] = $mine['user'];
                $recipients[$userId]['tasks'] = array_merge($recipients[$userId]['tasks'] ?? [], $mine['tasks']);
            }
        }

        foreach ($recipients as $mine) {
            $list = collect($mine['tasks'])->sortBy(fn (EmployeeChecklistTask $task): string => $task->due_date?->toDateString() ?? '');
            $count = $list->count();
            $lines = $list->take(3)->map(fn (EmployeeChecklistTask $task): string => $task->title.' ('.$task->employee?->name.', due '.$task->due_date?->format('j M').')')->implode('; ');

            $this->send($mine['user'], Notification::make()
                ->title($count.' onboarding / exit '.Str::plural('task', $count).' overdue')
                ->body($lines.($count > 3 ? '; and '.($count - 3).' more.' : '.'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->viewData(['employee_checklist_overdue' => $today->toDateString()])
                ->actions([$this->myTasksAction()]));
            $notified++;
        }

        return $notified;
    }

    /** A task was handed to someone (reassigned, or their account / manager link was just set). */
    public function taskAssigned(EmployeeChecklistTask $task, User $assignee): void
    {
        $this->send($assignee, Notification::make()
            ->title('New '.mb_strtolower(ChecklistKinds::typeLabel($task->checklist?->type)).' task: '.$task->title)
            ->body('For '.$task->employee?->name.($task->due_date ? ', due '.$task->due_date->format('D j M') : '').'.')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('info')
            ->viewData(['employee_checklist_task' => $task->getKey()])
            ->actions([$this->myTasksAction()]));
    }

    /**
     * Active recipients of each task, with their tasks.
     *
     * @param  Collection<int, EmployeeChecklistTask>  $tasks
     * @return array<int, array{user: User, tasks: array<int, EmployeeChecklistTask>}>
     */
    protected function byRecipient(Collection $tasks, int $companyId): array
    {
        $ids = [];

        foreach ($tasks as $task) {
            $recipientIds = $task->assignee_id !== null ? [(int) $task->assignee_id] : $this->hrApproverIds($companyId);

            foreach ($recipientIds as $id) {
                $ids[$id][] = $task;
            }
        }

        if ($ids === []) {
            return [];
        }

        $users = User::query()->whereKey(array_keys($ids))->where('is_active', true)->get()->keyBy('id');
        $result = [];

        foreach ($ids as $id => $mine) {
            if ($users->has($id)) {
                $result[$id] = ['user' => $users->get($id), 'tasks' => $mine];
            }
        }

        return $result;
    }

    /** @return array<int, int> */
    protected function hrApproverIds(int $companyId): array
    {
        return $this->hrApprovers[$companyId] ??= $this->approvals->resolveHrApproversForCompany($companyId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    protected function myTasksAction(): Action
    {
        return Action::make('my_tasks')
            ->button()
            ->label('My tasks')
            ->url(route('filament.admin.pages.my-tasks'));
    }

    protected function send(User $user, Notification $notification): void
    {
        try {
            $user->notifyNow($notification->toDatabase());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
