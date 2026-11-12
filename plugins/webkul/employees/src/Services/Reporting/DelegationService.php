<?php

namespace Webkul\Employee\Services\Reporting;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\ApprovalDelegation;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * Substitute approvers: "while I am away, Sana approves for me".
 *
 * Anyone can name a substitute for their OWN approvals; HR (the manage
 * reporting lines permission) can name one for anybody in the company. In the
 * date range the substitute can decide wherever the person could, and is told
 * about what waits for them, but never on their own requests (the approval
 * engine enforces that). A delegation can be revoked at any time.
 */
class DelegationService
{
    /** The longest a substitute may stand in, so a forgotten delegation cannot run for ever. */
    public const MaxDays = 90;

    public function __construct(
        protected HierarchicalNotificationService $notifications,
    ) {}

    public static function today(): string
    {
        return Carbon::now((string) config('attendance.monthly_report.timezone', 'Asia/Karachi'))->toDateString();
    }

    public function canCreateFor(User $actor, User $from, int $companyId): bool
    {
        if (! CurrentCompany::canAccess($companyId, $actor)) {
            return false;
        }

        return (int) $actor->id === (int) $from->id
            || ($actor->can(HrPermissions::ManageReportingLines) && CurrentCompany::canAccess($companyId, $from));
    }

    public function canRevoke(User $actor, ApprovalDelegation $delegation): bool
    {
        return $delegation->revoked_at === null
            && (CurrentCompany::canAccess((int) $delegation->company_id, $actor))
            && ((int) $actor->id === (int) $delegation->from_user_id
                || (int) $actor->id === (int) $delegation->created_by
                || $actor->can(HrPermissions::ManageReportingLines));
    }

    /**
     * @throws RuntimeException|InvalidArgumentException
     */
    public function create(User $actor, int $companyId, User $from, User $to, string $startsOn, string $endsOn, ?string $reason = null): ApprovalDelegation
    {
        if (! $this->canCreateFor($actor, $from, $companyId)) {
            throw new RuntimeException('You may only name a substitute for your own approvals.');
        }
        if ((int) $from->id === (int) $to->id) {
            throw new InvalidArgumentException('Choose someone else as the substitute.');
        }
        if (! $to->is_active || ! CurrentCompany::canAccess($companyId, $to)) {
            throw new InvalidArgumentException($to->name.' cannot act in this company. Choose an active person who works here.');
        }

        $start = Carbon::parse($startsOn)->toDateString();
        $end = Carbon::parse($endsOn)->toDateString();

        if ($end < $start) {
            throw new InvalidArgumentException('The last day cannot be before the first day.');
        }
        if ($end < self::today()) {
            throw new InvalidArgumentException('The last day is already in the past.');
        }
        if (Carbon::parse($start)->diffInDays(Carbon::parse($end)) > self::MaxDays) {
            throw new InvalidArgumentException('A substitute can stand in for at most '.self::MaxDays.' days at a time. Set another one when it ends.');
        }

        $overlap = ApprovalDelegation::query()
            ->where('company_id', $companyId)
            ->where('from_user_id', $from->id)
            ->where('to_user_id', $to->id)
            ->whereNull('revoked_at')
            ->whereDate('starts_on', '<=', $end)
            ->whereDate('ends_on', '>=', $start)
            ->exists();

        if ($overlap) {
            throw new RuntimeException($to->name.' is already '.$from->name."'s substitute for part of these days.");
        }

        $delegation = ApprovalDelegation::query()->create([
            'company_id'   => $companyId,
            'from_user_id' => $from->id,
            'to_user_id'   => $to->id,
            'starts_on'    => $start,
            'ends_on'      => $end,
            'reason'       => filled($reason) ? trim($reason) : null,
            'created_by'   => $actor->id,
        ]);

        ApprovalEngine::flushMemo();
        $this->tell($to, $delegation, 'to', $from->name.' named you as their substitute', 'From '.Carbon::parse($start)->format('j M').' to '.Carbon::parse($end)->format('j M Y').' you can approve what would have gone to '.$from->name.'.');
        if ((int) $actor->id !== (int) $from->id) {
            $this->tell($from, $delegation, 'from', $to->name.' will approve for you', 'HR named '.$to->name.' as your substitute from '.Carbon::parse($start)->format('j M').' to '.Carbon::parse($end)->format('j M Y').'.');
        }

        return $delegation;
    }

    /** @throws RuntimeException */
    public function revoke(User $actor, ApprovalDelegation $delegation): ApprovalDelegation
    {
        if (! $this->canRevoke($actor, $delegation)) {
            throw new RuntimeException('You cannot end this substitute arrangement.');
        }

        $delegation->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
        ApprovalEngine::flushMemo();

        return $delegation->fresh();
    }

    /** @return Builder<ApprovalDelegation> */
    public function query(int $companyId): Builder
    {
        return ApprovalDelegation::query()->where('company_id', $companyId)->with(['fromUser:id,name', 'toUser:id,name', 'creator:id,name'])->latest('id');
    }

    private function tell(User $user, ApprovalDelegation $delegation, string $role, string $title, string $body): void
    {
        $this->notifications->sendIdempotentNotification(
            $user,
            Notification::make()->title($title)->body($body)->icon('heroicon-o-user-plus')->color('info'),
            'approval_delegation_'.$delegation->id.'_'.$role.'_'.$user->id,
            ['event' => 'approval_delegation', 'delegation_id' => $delegation->id],
        );
    }
}
