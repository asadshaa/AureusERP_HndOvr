<?php

return [
    // How long a pending transfer stays claimable before it expires.
    'expiry_hours' => (int) env('ACCOUNTING_TRANSFER_EXPIRY_HOURS', 168),

    // Cap on simultaneously pending outbound transfers per sender, to
    // bound bulk-exfiltration attempts through this feature.
    'max_pending_per_sender' => (int) env('ACCOUNTING_TRANSFER_MAX_PENDING', 25),
];
