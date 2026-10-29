<?php

namespace Webkul\Support\Services;

use Throwable;
use Webkul\Security\Models\User;
use Webkul\Support\Contracts\ReturnableApprovalSubject;
use Webkul\Support\Models\ApprovalRequest;

/**
 * Links for approval notifications. Approvers go to the Approval Queue with
 * the request's details open; a requester goes to the request itself when
 * the subject says where that is (ReturnableApprovalSubject, so this base
 * plugin depends on no subject plugin), else to the queue when they may
 * open it.
 */
final class ApprovalLinks
{
    public static function queue(ApprovalRequest $request): ?string
    {
        try {
            return route('filament.admin.resources.approval-requests.index', [
                'tableAction'       => 'view',
                'tableActionRecord' => $request->id,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    public static function forRequester(ApprovalRequest $request, User $recipient): ?string
    {
        $subject = $request->subject;

        if ($subject instanceof ReturnableApprovalSubject) {
            try {
                $url = $subject->approvalSubjectUrl($recipient);

                if (filled($url)) {
                    return (string) $url;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $recipient->can('view_any_support_approval::request') ? self::queue($request) : null;
    }

    /** "Leave Request", "Employee Loan"... for notification text. */
    public static function label(ApprovalRequest $request): string
    {
        return str((string) $request->request_type)->headline()->toString();
    }
}
