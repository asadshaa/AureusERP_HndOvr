<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\DocumentExpiryReminderService;

class SendDocumentExpiryReminders extends Command
{
    protected $signature = 'hr:document-expiry-reminders
                            {--company= : Only this company id}
                            {--date= : Local date (Y-m-d) instead of today}';

    protected $description = 'Remind HR and the employee 30 days and 7 days before an employee document expires and on expiry (once per document per threshold)';

    public function handle(DocumentExpiryReminderService $reminders): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $date = $this->option('date') ?: null;

        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('--date must look like 2026-10-28.');

            return self::INVALID;
        }

        $result = $reminders->send($companyId, $date);

        $this->info("Document expiry reminders: {$result['notified']} notification(s) for {$result['documents']} document(s), {$result['skipped']} already sent.");

        return self::SUCCESS;
    }
}
