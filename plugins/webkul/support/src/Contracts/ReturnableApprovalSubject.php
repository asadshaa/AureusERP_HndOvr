<?php

namespace Webkul\Support\Contracts;

use Webkul\Security\Models\User;

/**
 * An approval subject wired for "Return to requester": its approval
 * synchronisation makes it editable while the request is returned, and its
 * screens let the requester resubmit it (which restarts the approval at the
 * first step). The Approval Queue only offers "Return to requester" for such
 * subjects, so a request of a flow without a resubmit path is never stranded.
 * Today: employee requests (loan, advance, letters, claims, attendance
 * corrections...) and leave.
 */
interface ReturnableApprovalSubject
{
    /** Where the requester (or another recipient) opens the record from an approval notification. */
    public function approvalSubjectUrl(?User $recipient = null): ?string;
}
