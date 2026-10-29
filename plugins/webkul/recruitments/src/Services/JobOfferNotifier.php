<?php

namespace Webkul\Recruitment\Services;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Collection;
use Throwable;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * In-app notifications about job offers (HR parity 3.6), each with an
 * "Open offer" link and an idempotency key, so a retry never notifies
 * twice. Notification text never contains the salary.
 */
class JobOfferNotifier
{
    public function __construct(
        protected ApprovalEngine $approvals,
        protected HierarchicalNotificationService $notifications,
    ) {}

    /** The approvers of the offer's current approval step. */
    public function awaitingApproval(JobOffer $offer, ApprovalRequest $approval): int
    {
        $approval->loadMissing('workflow.steps');
        $step = $approval->currentStep();

        if (! $step || $approval->status !== 'pending') {
            return 0;
        }

        $round = (int) ($approval->submission_round ?: 1);

        return $this->send(
            $this->approvals->resolveAuthorizedApprovers($approval, $step),
            fn (): FilamentNotification => FilamentNotification::make()
                ->warning()
                ->icon('heroicon-o-document-check')
                ->title(__('Job offer awaiting your approval'))
                ->body(__('Offer :reference to :candidate (:position) needs your decision at step ":step".', [
                    'reference' => $offer->reference,
                    'candidate' => $offer->candidateName(),
                    'position'  => $offer->positionLabel(),
                    'step'      => $step->name,
                ])),
            $offer,
            fn (User $user): string => "job_offer_awaiting_{$approval->id}_r{$round}_s{$step->id}_{$user->id}",
        );
    }

    /** The approved offer can be sent: tell whoever made and submitted it. */
    public function approved(JobOffer $offer): int
    {
        return $this->send(
            $this->recruiters($offer),
            fn (): FilamentNotification => FilamentNotification::make()
                ->success()
                ->icon('heroicon-o-check-badge')
                ->title(__('Job offer approved'))
                ->body(__('Offer :reference to :candidate is approved. Send it to the candidate from the offer.', [
                    'reference' => $offer->reference,
                    'candidate' => $offer->candidateName(),
                ])),
            $offer,
            fn (User $user): string => "job_offer_approved_{$offer->id}_{$user->id}",
        );
    }

    /** The candidate accepted or declined. */
    public function responded(JobOffer $offer, bool $accepted): int
    {
        $reason = trim((string) $offer->response_reason);

        return $this->send(
            $this->recruiters($offer),
            fn (): FilamentNotification => $accepted
                ? FilamentNotification::make()
                    ->success()
                    ->icon('heroicon-o-hand-thumb-up')
                    ->title(__('Offer accepted'))
                    ->body(__(':candidate accepted offer :reference (:position). Create the employee from the offer.', [
                        'candidate' => $offer->candidateName(),
                        'reference' => $offer->reference,
                        'position'  => $offer->positionLabel(),
                    ]))
                : FilamentNotification::make()
                    ->danger()
                    ->icon('heroicon-o-hand-thumb-down')
                    ->title(__('Offer declined'))
                    ->body(__(':candidate declined offer :reference (:position).', [
                        'candidate' => $offer->candidateName(),
                        'reference' => $offer->reference,
                        'position'  => $offer->positionLabel(),
                    ]).($reason !== '' ? ' '.__('Reason: :reason', ['reason' => $reason]) : '')),
            $offer,
            fn (User $user): string => 'job_offer_'.($accepted ? 'accepted' : 'declined')."_{$offer->id}_{$user->id}",
        );
    }

    public function expired(JobOffer $offer): int
    {
        return $this->send(
            $this->recruiters($offer),
            fn (): FilamentNotification => FilamentNotification::make()
                ->warning()
                ->icon('heroicon-o-calendar')
                ->title(__('Offer expired'))
                ->body(__('Offer :reference to :candidate expired on :date without an answer.', [
                    'reference' => $offer->reference,
                    'candidate' => $offer->candidateName(),
                    'date'      => OfferLetterRenderer::date($offer->expiry_date),
                ])),
            $offer,
            fn (User $user): string => "job_offer_expired_{$offer->id}_{$user->id}",
        );
    }

    /**
     * Who looks after the offer: the application's recruiter, the job's
     * recruiter, whoever drafted, submitted and sent it -- active members of
     * the offer's company only.
     *
     * @return Collection<int, User>
     */
    public function recruiters(JobOffer $offer): Collection
    {
        $offer->loadMissing(['applicant.recruiter', 'job.recruiter', 'creator', 'sender', 'approvalRequest.requester']);

        return collect([
            $offer->applicant?->recruiter,
            $offer->job?->recruiter,
            $offer->creator,
            $offer->approvalRequest?->requester,
            $offer->sender,
        ])
            ->filter(fn ($user): bool => $user instanceof User && $user->is_active && $this->approvals->userCanAccessCompany($user, (int) $offer->company_id))
            ->unique('id')
            ->values();
    }

    /**
     * @param  iterable<int, User>  $recipients
     * @param  callable(): FilamentNotification  $make
     * @param  callable(User): string  $key
     */
    protected function send(iterable $recipients, callable $make, JobOffer $offer, callable $key): int
    {
        $sent = 0;
        $url = $offer->approvalSubjectUrl();

        foreach ($recipients as $recipient) {
            try {
                $notification = $make();

                if ($url) {
                    $notification->actions([Action::make('open')->button()->label(__('Open offer'))->url($url)]);
                }

                if ($this->notifications->sendIdempotentNotification($recipient, $notification, $key($recipient), [
                    'event'        => 'job_offer',
                    'job_offer_id' => $offer->id,
                    'company_id'   => $offer->company_id,
                ])) {
                    $sent++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}
