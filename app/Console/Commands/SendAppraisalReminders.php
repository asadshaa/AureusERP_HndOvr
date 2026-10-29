<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Appraisals\AppraisalReminderService;

class SendAppraisalReminders extends Command
{
    protected $signature = 'hr:appraisal-reminders
                            {--company= : Only this company id}
                            {--date= : Local date (Y-m-d) instead of today}';

    protected $description = 'Remind employees of open self reviews and reviewers (or HR) of open manager reviews before the deadline and when overdue; each reminder goes out once';

    public function handle(AppraisalReminderService $reminders): int
    {
        $date = $this->option('date') ?: null;

        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('--date must look like 2026-11-02.');

            return self::INVALID;
        }

        $result = $reminders->send($this->option('company') !== null ? (int) $this->option('company') : null, $date);

        $this->info("Appraisal reminders: {$result['notified']} notification(s) for {$result['reviews']} appraisal(s), {$result['skipped']} already reminded.");

        return self::SUCCESS;
    }
}
