<?php

namespace Webkul\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Accounting\Enums\WebRtcSessionStatus;
use Webkul\Accounting\Models\WebRtcSession;

/**
 * Flips WebRTC signaling sessions past their expiry to Expired.
 *
 * Housekeeping, not a security control: WebRtcSignalingService only ever
 * resolves a session through isConnectable(), which already tests
 * expires_at, so an unrun sweep cannot let a stale session be joined. This
 * exists so the status column means what it says, and so a session left
 * sitting in "waiting" because nobody ever connected stops looking live.
 *
 * Mirrors ExpireDocumentTransfersCommand, including its caveat: this
 * repository wires no application scheduler (bootstrap/app.php registers no
 * withSchedule), so run it from external cron.
 *
 * Deliberately flips status rather than deleting rows. The transfer's own
 * record of what happened lives in accounting_document_audits, so these
 * rows are not the evidence trail -- but choosing a retention window is a
 * data policy decision, not a cleanup detail, and belongs to whoever
 * operates the instance.
 */
class ExpireWebRtcSessionsCommand extends Command
{
    protected $signature = 'accounting:webrtc:expire';

    protected $description = 'Mark WebRTC transfer sessions past their expiry as expired';

    public function handle(): int
    {
        $count = WebRtcSession::query()
            ->whereIn('status', [WebRtcSessionStatus::Waiting, WebRtcSessionStatus::Connected])
            ->where('expires_at', '<=', now())
            ->update(['status' => WebRtcSessionStatus::Expired]);

        $this->info("Expired {$count} WebRTC session(s).");

        return self::SUCCESS;
    }
}
