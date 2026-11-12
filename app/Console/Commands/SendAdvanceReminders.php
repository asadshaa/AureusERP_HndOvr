<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Advances\AdvanceReminderService;

class SendAdvanceReminders extends Command
{
    protected $signature = 'hr:advance-reminders
        {--company= : Only this company id}
        {--date= : Run as of this day (Y-m-d, Karachi); default today}';

    protected $description = 'Remind employees to settle business advances (two days before the settle-by date, then weekly once overdue); in-app notifications only, at most once per advance and day';

    public function handle(AdvanceReminderService $reminders): int
    {
        $result = $reminders->send(
            $this->option('company') ? (int) $this->option('company') : null,
            $this->option('date') ?: null,
        );

        $this->info("Advances due a reminder: {$result['advances']}; notifications sent: {$result['notified']}.");

        return self::SUCCESS;
    }
}
