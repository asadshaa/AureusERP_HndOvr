<?php

namespace Webkul\Employee\Services\Checklists;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Webkul\Employee\Models\EmployeeChecklistTask;
use Webkul\Employee\Support\ChecklistKinds;

/**
 * hr:checklist-reminders (daily, 09:30 Karachi): overdue onboarding / exit
 * tasks are reminded to their assignee, or to the company's HR approvers
 * for the HR queue. Each task is claimed for the day with a conditional
 * update of last_reminded_on before anyone is told, so a task is reminded
 * at most once a day however often the command runs.
 */
class ChecklistReminderService
{
    public function __construct(
        protected ChecklistNotifier $notifier,
    ) {}

    /** @return array{tasks: int, notified: int, skipped: int} */
    public function send(?int $companyId = null, ?string $date = null): array
    {
        $today = $date !== null
            ? CarbonImmutable::parse($date, ChecklistKinds::timezone())->startOfDay()
            : ChecklistKinds::today();

        $candidates = EmployeeChecklistTask::query()
            ->overdue($today)
            ->whereHas('checklist', fn (Builder $checklists) => $checklists->where('status', ChecklistKinds::ChecklistOpen))
            ->whereHas('employee', fn (Builder $employees) => $employees->whereNull('employees_employees.deleted_at'))
            ->whereHas('company', fn (Builder $companies) => $companies->where('is_active', true))
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->with(['employee:id,name', 'checklist:id,type'])
            ->orderBy('id')
            ->get();

        $claimed = new Collection;

        foreach ($candidates as $task) {
            $updated = EmployeeChecklistTask::query()
                ->whereKey($task->getKey())
                ->where(fn (Builder $query) => $query->whereNull('last_reminded_on')->orWhereDate('last_reminded_on', '<', $today->toDateString()))
                ->update(['last_reminded_on' => $today->toDateString()]);

            if ($updated === 1) {
                $claimed->push($task);
            }
        }

        return [
            'tasks'    => $claimed->count(),
            'notified' => $claimed->isEmpty() ? 0 : $this->notifier->overdue($claimed, $today),
            'skipped'  => $candidates->count() - $claimed->count(),
        ];
    }
}
