<?php

namespace Webkul\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Accounting\Enums\DocumentTransferStatus;
use Webkul\Accounting\Models\DocumentTransfer;

/**
 * Flips pending transfers past their expiry to Expired.
 *
 * claim() already refuses an expired transfer, so this is housekeeping
 * for reporting and for the recipient's inbox rather than a security
 * control -- an unrun sweep cannot let a stale transfer be claimed.
 *
 * This repository has no application scheduler wired (bootstrap/app.php
 * registers no withSchedule), so run it from external cron.
 */
class ExpireDocumentTransfersCommand extends Command
{
    protected $signature = 'accounting:transfers:expire';

    protected $description = 'Mark pending document transfers past their expiry as expired';

    public function handle(): int
    {
        $count = DocumentTransfer::query()
            ->pending()
            ->where('expires_at', '<=', now())
            ->update(['status' => DocumentTransferStatus::Expired]);

        $this->info("Expired {$count} transfer(s).");

        return self::SUCCESS;
    }
}
