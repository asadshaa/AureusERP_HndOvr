<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\ExpenseHeadsUpService;

class SendExpenseHeadsUpReminders extends Command
{
    protected $signature = 'hr:heads-up-reminders
        {--company= : Only this company id}
        {--date= : Run as of this day (Y-m-d, Karachi); default today}';

    protected $description = 'Remind employees to turn an approved expense heads-up into a claim (the day after the expected date, then weekly); in-app notifications only, at most once per heads-up and day';

    public function handle(ExpenseHeadsUpService $headsUps): int
    {
        $result = $headsUps->sendReminders(
            $this->option('company') ? (int) $this->option('company') : null,
            $this->option('date') ?: null,
        );

        $this->info("Heads-ups due a reminder: {$result['heads_ups']}; notifications sent: {$result['notified']}.");

        return self::SUCCESS;
    }
}
