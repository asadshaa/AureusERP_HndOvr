<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Checklists\ChecklistReminderService;

class SendChecklistTaskReminders extends Command
{
    protected $signature = 'hr:checklist-reminders
                            {--company= : Only this company id}
                            {--date= : Local date (Y-m-d) instead of today}';

    protected $description = 'Remind assignees (or the company HR for unassigned tasks) of overdue onboarding / exit checklist tasks, at most once per task per day';

    public function handle(ChecklistReminderService $reminders): int
    {
        $date = $this->option('date') ?: null;

        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('--date must look like 2026-10-28.');

            return self::INVALID;
        }

        $result = $reminders->send($this->option('company') !== null ? (int) $this->option('company') : null, $date);

        $this->info("Checklist reminders: {$result['notified']} notification(s) for {$result['tasks']} overdue task(s), {$result['skipped']} already reminded today.");

        return self::SUCCESS;
    }
}
