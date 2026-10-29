<?php

namespace Webkul\Support\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Webkul\Support\Services\ApprovalReminderService;

/**
 * Hourly (routes/console.php): reminds the approvers of approval requests left
 * waiting at a step, then escalates once to HR -- see ApprovalReminderService
 * for the timing, quiet hours and idempotency.
 */
class SendApprovalRemindersCommand extends Command
{
    protected $signature = 'approvals:send-reminders
                            {--company= : Only this company id}
                            {--dry-run : Only report what is due}';

    protected $description = 'Remind approvers of approval requests waiting too long, then escalate once to HR (never at night or on weekends, Asia/Karachi)';

    public function handle(ApprovalReminderService $reminders): int
    {
        if (! Schema::hasTable('support_approval_reminders')) {
            return self::SUCCESS;
        }

        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $dryRun = (bool) $this->option('dry-run');
        $result = $reminders->run(now(), $companyId, $dryRun);

        if ($result['quiet']) {
            $this->info('Quiet hours or weekend in '.$reminders->timezone().': no approval reminders sent.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%sApproval reminders: %d request(s) checked, %d reminder(s) and %d escalation(s)%s, %d notification(s).',
            $dryRun ? 'Dry run. ' : '',
            $result['checked'],
            $result['reminded'],
            $result['escalated'],
            $dryRun ? ' due' : ' sent',
            $result['notified'],
        ));

        return self::SUCCESS;
    }
}
