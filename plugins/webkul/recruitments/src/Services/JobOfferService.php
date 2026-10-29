<?php

namespace Webkul\Recruitment\Services;

use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use WeakMap;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Mail\JobOfferMail;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Models\JobOfferEvent;
use Webkul\Recruitment\Models\OfferLetterTemplate;
use Webkul\Recruitment\Models\OfferSetting;
use Webkul\Recruitment\Models\Stage;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\ApprovalRequestStatus;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Job offers (HR parity 3.6): draft -> approval -> letter -> send ->
 * accepted / declined / expired, or withdrawn. Every action is checked
 * here, whatever screen or route calls it:
 *
 *  - recruiters and HR of the company (they may update the application, in
 *    their active company) draft, edit, submit, withdraw and send offers;
 *  - approval is the shared ApprovalEngine, request type "job_offer":
 *    hiring manager -> HR -> CEO when the monthly gross exceeds the
 *    company's threshold; approvers decide on the offer page or in the
 *    Approval Queue, can return it for changes, and the requester can
 *    resubmit or withdraw it;
 *  - the salary is only shown to (and only set by) HR, hiring managers and
 *    the offer's approvers (canSeeSalary()); it never goes into the
 *    approval request, the chatter or the history;
 *  - the candidate answers on a public page through a signed, tokenised
 *    link that stops working at the end of the expiry date, when the offer
 *    is re-sent (new token) or when it is no longer open.
 */
class JobOfferService
{
    /**
     * isApprover() answers per offer instance, user and approval state: list
     * rows ask it for the salary column and several buttons.
     *
     * @var WeakMap<JobOffer, array<string, bool>>|null
     */
    private static ?WeakMap $approverMemo = null;

    public function __construct(
        protected ApprovalEngine $approvals,
        protected OfferLetterRenderer $letters,
        protected JobOfferNotifier $notifier,
    ) {}

    // ---------------------------------------------------------------------
    // Who may do what
    // ---------------------------------------------------------------------

    /** Draft, edit, submit, withdraw and send offers: recruiters / HR who may update the application, in its company. */
    public function canManage(?User $user, Applicant|JobOffer|null $subject): bool
    {
        $applicant = $subject instanceof JobOffer ? $subject->applicant : $subject;
        $companyId = $subject instanceof JobOffer ? (int) $subject->company_id : (int) $applicant?->company_id;

        return $user !== null
            && $applicant !== null
            && $companyId > 0
            && CurrentCompany::id($user) === $companyId
            && Gate::forUser($user)->allows('update', $applicant);
    }

    /** Recruitment users see every offer of their companies; anyone else only offers they approve. */
    public function seesAllOffers(?User $user): bool
    {
        return $user !== null && $user->can('view_any_recruitment_applicant');
    }

    public function canView(?User $user, JobOffer $offer): bool
    {
        if (! $user || ! CurrentCompany::canAccess((int) $offer->company_id, $user)) {
            return false;
        }

        return $this->seesAllOffers($user) || $this->isApprover($user, $offer);
    }

    /** HR of the company and hiring managers, before an offer exists (the "Make offer" form). */
    public function canSeeSalaryFor(?User $user, int $companyId): bool
    {
        return $user !== null
            && CurrentCompany::canAccess($companyId, $user)
            && ($this->approvals->isCompanyHrApprover($user, $companyId) || CandidateConversionService::canConvert($user));
    }

    /** HR of the company, hiring managers and the offer's approvers. */
    public function canSeeSalary(?User $user, JobOffer $offer): bool
    {
        return $this->canSeeSalaryFor($user, (int) $offer->company_id)
            || ($user !== null && CurrentCompany::canAccess((int) $offer->company_id, $user) && $this->isApprover($user, $offer));
    }

    /** The offer's hiring manager, a current approver, or someone who already decided on it. */
    public function isApprover(User $user, JobOffer $offer): bool
    {
        self::$approverMemo ??= new WeakMap;
        $key = implode(':', [(int) $user->id, $offer->status->value, (int) $offer->approval_request_id, (int) $offer->approvalRequest?->current_step_sequence, (int) $offer->approvalRequest?->submission_round]);
        $memo = self::$approverMemo[$offer] ?? [];

        if (! array_key_exists($key, $memo)) {
            $memo[$key] = $this->resolveIsApprover($user, $offer);
            self::$approverMemo[$offer] = $memo;
        }

        return $memo[$key];
    }

    protected function resolveIsApprover(User $user, JobOffer $offer): bool
    {
        if ($offer->hiring_manager_id !== null && (int) $offer->hiring_manager_id === (int) $user->id) {
            return true;
        }

        $approval = $offer->approvalRequest;

        if (! $approval) {
            return false;
        }

        $this->withSubject($approval, $offer);

        if ($approval->status === ApprovalRequestStatus::Pending->value && $this->approvals->canAct($approval, $user)) {
            return true;
        }

        return $approval->decisions()->where('actor_id', $user->id)->whereIn('decision', ['approved', 'rejected', 'returned'])->exists();
    }

    /** A current-step approver of the offer's pending approval. */
    public function canDecide(?User $user, JobOffer $offer): bool
    {
        return $user !== null
            && $offer->status === JobOfferStatus::PENDING_APPROVAL
            && $offer->approvalRequest !== null
            && $this->approvals->canAct($this->withSubject($offer->approvalRequest, $offer), $user);
    }

    /** The engine reads the request's subject: hand it the offer already in memory. */
    protected function withSubject(ApprovalRequest $approval, JobOffer $offer): ApprovalRequest
    {
        if (! $approval->relationLoaded('subject')) {
            $approval->setRelation('subject', $offer);
        }

        return $approval;
    }

    public function canEdit(?User $user, JobOffer $offer): bool
    {
        return $offer->status->isEditable() && $this->canManage($user, $offer);
    }

    public function canSubmit(?User $user, JobOffer $offer): bool
    {
        if (! $this->canEdit($user, $offer)) {
            return false;
        }

        // A returned offer goes back into the same approval: only its requester may resubmit it.
        return $offer->status !== JobOfferStatus::RETURNED
            || ($offer->approvalRequest && $this->approvals->canResubmit($offer->approvalRequest, $user));
    }

    public function canWithdraw(?User $user, JobOffer $offer): bool
    {
        if (! $offer->status->isOpen() || ! $this->canManage($user, $offer)) {
            return false;
        }

        if (in_array($offer->status, [JobOfferStatus::PENDING_APPROVAL, JobOfferStatus::RETURNED], true)) {
            return $offer->approvalRequest !== null && $this->approvals->canWithdraw($offer->approvalRequest, $user);
        }

        return true;
    }

    public function canSend(?User $user, JobOffer $offer): bool
    {
        return in_array($offer->status, [JobOfferStatus::APPROVED, JobOfferStatus::SENT], true)
            && $this->canManage($user, $offer)
            && $this->canSeeSalary($user, $offer);
    }

    public function canCreateEmployee(?User $user, JobOffer $offer): bool
    {
        return $offer->status === JobOfferStatus::ACCEPTED
            && $offer->employee_id === null
            && $user !== null
            && CurrentCompany::id($user) === (int) $offer->company_id
            && CandidateConversionService::canConvert($user);
    }

    /** HR edits the company's offer letter templates and settings. */
    public function canManageTemplates(?User $user, ?int $companyId = null): bool
    {
        $companyId ??= CurrentCompany::id($user);

        return $user !== null
            && $companyId !== null
            && CurrentCompany::id($user) === (int) $companyId
            && $this->approvals->isCompanyHrApprover($user, (int) $companyId);
    }

    // ---------------------------------------------------------------------
    // Drafting
    // ---------------------------------------------------------------------

    /**
     * Form defaults for a new offer on this application.
     *
     * @return array<string, mixed>
     */
    public function defaults(Applicant $applicant, ?User $user = null): array
    {
        $applicant->loadMissing(['job', 'candidate']);
        $today = $this->today();
        $previous = JobOffer::query()->where('applicant_id', $applicant->id)->latest('id')->first();
        $availability = $applicant->candidate?->availability_date;
        $joining = $availability && Carbon::parse($availability)->toDateString() > $today ? Carbon::parse($availability) : Carbon::parse($today)->addDays(30);
        $showSalary = $this->canSeeSalaryFor($user, (int) $applicant->company_id);

        return array_filter([
            'job_id'               => $applicant->job_id,
            'department_id'        => $applicant->department_id ?? $applicant->job?->department_id,
            'designation'          => $previous?->designation ?? $applicant->job?->name,
            'reporting_manager_id' => $previous?->reporting_manager_id ?? $applicant->job?->manager_id,
            'joining_date'         => $joining->toDateString(),
            'expiry_date'          => Carbon::parse($today)->addDays((int) config('recruitment.offers.default_valid_days', 7))->toDateString(),
            'salary_gross'         => $showSalary ? ($previous?->salary_gross ?? ($applicant->salary_proposed ?: null)) : null,
            'salary_breakdown'     => $showSalary ? $previous?->salary_breakdown : null,
            'probation_months'     => $previous?->probation_months ?? (int) config('recruitment.offers.default_probation', 3),
            'working_hours'        => $previous?->working_hours ?? config('recruitment.offers.default_working_hours'),
            'benefits'             => $previous?->benefits,
            'template_id'          => OfferLetterTemplate::preferredFor((int) $applicant->company_id)?->id,
        ], fn ($value): bool => $value !== null);
    }

    /** @param  array<string, mixed>  $data */
    public function createDraft(Applicant $applicant, array $data, User $actor): JobOffer
    {
        $this->assertCanManage($actor, $applicant);

        if ($applicant->refuse_reason_id || ! $applicant->is_active || $applicant->trashed()) {
            throw new RuntimeException(__('This application is refused or archived. Reopen it before making an offer.'));
        }

        $companyId = (int) $applicant->company_id;
        $attributes = $this->validated($data, $companyId, $this->canSeeSalaryFor($actor, $companyId));

        return DB::transaction(function () use ($applicant, $attributes, $actor, $companyId): JobOffer {
            Applicant::query()->whereKey($applicant->id)->lockForUpdate()->first();

            if (JobOffer::query()->where('applicant_id', $applicant->id)->open()->exists()) {
                throw new RuntimeException(__('This application already has an open offer. Withdraw it before making a new one.'));
            }

            $offer = JobOffer::query()->create($attributes + [
                'company_id'   => $companyId,
                'applicant_id' => $applicant->id,
                'candidate_id' => $applicant->candidate_id,
                'job_id'       => $attributes['job_id'] ?? $applicant->job_id,
                'currency_id'  => $applicant->company?->currency_id,
                'status'       => JobOfferStatus::DRAFT,
                'creator_id'   => $actor->id,
            ]);

            $offer->forceFill(['reference' => sprintf('OFR-%d-%05d', $companyId, $offer->id)])->save();

            $this->record($offer, 'created', null, JobOfferStatus::DRAFT, $actor);
            $this->logToApplicant($offer, $actor, __('Offer :reference drafted (:position).', ['reference' => $offer->reference, 'position' => $offer->positionLabel()]));

            return $offer->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(JobOffer $offer, array $data, User $actor): JobOffer
    {
        $this->assertCanManage($actor, $offer);

        if (! $offer->status->isEditable()) {
            throw new RuntimeException(__('Only a draft offer, or one returned for changes, can be edited.'));
        }

        $attributes = $this->validated($data, (int) $offer->company_id, $this->canSeeSalary($actor, $offer), $offer);

        return DB::transaction(function () use ($offer, $attributes, $actor): JobOffer {
            $locked = JobOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw new RuntimeException(__('Only a draft offer, or one returned for changes, can be edited.'));
            }

            $locked->fill($attributes);
            $changed = $this->changedTerms($locked);
            $locked->save();

            if ($changed !== []) {
                $this->record($locked, 'updated', $locked->status, $locked->status, $actor, implode(', ', $changed), ['changed' => $changed]);
            }

            return $offer->refresh();
        });
    }

    // ---------------------------------------------------------------------
    // Approval
    // ---------------------------------------------------------------------

    public function submit(JobOffer $offer, User $actor, ?string $note = null): JobOffer
    {
        $this->assertCanManage($actor, $offer);
        $offer->loadMissing(['applicant.candidate', 'job', 'approvalRequest']);

        if (! $offer->status->isEditable()) {
            throw new RuntimeException(__('Only a draft offer, or one returned for changes, can be submitted for approval.'));
        }

        $this->assertReadyForApproval($offer);

        $companyId = (int) $offer->company_id;
        $requiresCeo = BigDecimal::of((string) $offer->salary_gross)->isGreaterThan(OfferSetting::ceoThresholdFor($companyId));
        $context = [
            'company_id'   => $companyId,
            'offer_id'     => $offer->id,
            'applicant_id' => $offer->applicant_id,
            'job_id'       => $offer->job_id,
            'summary'      => __('Job offer :reference: :candidate as :position, joining :date', [
                'reference' => $offer->reference,
                'candidate' => $offer->candidateName(),
                'position'  => $offer->positionLabel(),
                'date'      => OfferLetterRenderer::date($offer->joining_date),
            ]),
            // Read by the CEO step's condition; the salary itself stays on the offer.
            'ceo_approval' => $requiresCeo ? '1' : '0',
        ];

        $approval = DB::transaction(function () use ($offer, $actor, $note, $context, $requiresCeo): ApprovalRequest {
            $locked = JobOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw new RuntimeException(__('Only a draft offer, or one returned for changes, can be submitted for approval.'));
            }

            $returned = $locked->status === JobOfferStatus::RETURNED ? $locked->approvalRequest : null;

            if ($returned && $returned->status === ApprovalRequestStatus::Returned->value) {
                $changed = $this->changedSinceReturn($locked);
                $approval = $this->approvals->resubmit($returned, $actor, null, $context, $note, ['status' => JobOfferStatus::RETURNED->value], ['status' => JobOfferStatus::PENDING_APPROVAL->value, 'changed' => $changed === [] ? '—' : implode(', ', $changed)]);
                $event = 'resubmitted';
            } else {
                $approval = $this->approvals->submit($locked, $actor, JobOffer::APPROVAL_REQUEST_TYPE, null, $context);
                $event = 'submitted';
            }

            $from = $locked->status;
            $locked->forceFill([
                'status'                => JobOfferStatus::PENDING_APPROVAL,
                'approval_request_id'   => $approval->id,
                'submitted_at'          => now(),
                'requires_ceo_approval' => $requiresCeo,
                'rejected_at'           => null,
                'rejection_reason'      => null,
            ])->save();

            $locked->setRelation('approvalRequest', $approval);
            $locked->forceFill(['hiring_manager_id' => $locked->resolveHiringManager()?->id])->save();

            $this->record($locked, $event, $from, JobOfferStatus::PENDING_APPROVAL, $actor, $note, ['ceo_approval' => $requiresCeo]);
            $this->logToApplicant($locked, $actor, __('Offer :reference submitted for approval.', ['reference' => $locked->reference]));

            return $approval;
        });

        $fresh = $offer->refresh();
        DB::afterCommit(fn () => $this->notifier->awaitingApproval($fresh, $approval->fresh(['workflow.steps'])));

        return $fresh;
    }

    public function approve(JobOffer $offer, User $actor, ?string $comment = null): JobOffer
    {
        $approval = $this->pendingApproval($offer);
        $this->approvals->approve($approval, $actor, $comment, ['status' => $offer->status->value], ['status' => 'approved']);

        return $offer->refresh();
    }

    public function reject(JobOffer $offer, User $actor, string $reason): JobOffer
    {
        $approval = $this->pendingApproval($offer);
        $this->approvals->reject($approval, $actor, $reason, ['status' => $offer->status->value], ['status' => 'rejected']);

        return $offer->refresh();
    }

    public function returnForChanges(JobOffer $offer, User $actor, string $comment): JobOffer
    {
        $approval = $this->pendingApproval($offer);
        $this->approvals->returnToRequester($approval, $actor, $comment, ['status' => $offer->status->value, 'terms' => $this->termsSnapshot($offer)], ['status' => 'returned']);

        return $offer->refresh();
    }

    /**
     * Writes an approval decision back to the offer. Called by the engine
     * (JobOffer::synchronizeApprovalState) inside the decision transaction,
     * whichever screen the decision came from.
     */
    public function synchronize(JobOffer $offer, ApprovalRequest $request): void
    {
        if ((int) $offer->approval_request_id !== (int) $request->id) {
            return;
        }

        $locked = JobOffer::query()->whereKey($offer->id)->lockForUpdate()->first();

        if (! $locked) {
            return;
        }

        $request->loadMissing('decisions');
        $last = $request->decisions->sortBy('id')->last();
        $actor = $last?->actor_id ? User::query()->find($last->actor_id) : null;
        $from = $locked->status;

        switch ($request->status) {
            case ApprovalRequestStatus::Pending->value:
                if ($from !== JobOfferStatus::PENDING_APPROVAL || $last?->decision !== 'approved') {
                    return;
                }

                $this->record($locked, 'step_approved', $from, $from, $actor, $last->reason);
                DB::afterCommit(fn () => $this->notifier->awaitingApproval($locked->fresh(), $request->fresh(['workflow.steps'])));

                return;

            case 'approved':
                if ($from !== JobOfferStatus::PENDING_APPROVAL) {
                    return;
                }

                $locked->forceFill(['status' => JobOfferStatus::APPROVED, 'approved_at' => $request->completed_at ?? now()])->save();
                $this->record($locked, 'approved', $from, JobOfferStatus::APPROVED, $actor, $last?->reason);
                $this->logToApplicant($locked, $actor, __('Offer :reference approved.', ['reference' => $locked->reference]));

                // The letter is generated after the decision commits: a PDF problem never undoes an approval.
                DB::afterCommit(function () use ($locked, $actor): void {
                    try {
                        $this->generateLetter($locked->fresh(), $actor, authorize: false);
                    } catch (Throwable $e) {
                        report($e);
                    }

                    $this->notifier->approved($locked->fresh());
                });

                return;

            case 'rejected':
                if ($from !== JobOfferStatus::PENDING_APPROVAL) {
                    return;
                }

                $locked->forceFill([
                    'status'           => JobOfferStatus::REJECTED,
                    'rejected_at'      => $request->completed_at ?? now(),
                    'rejection_reason' => $last?->reason,
                ])->save();
                $this->record($locked, 'rejected', $from, JobOfferStatus::REJECTED, $actor, $last?->reason);
                $this->logToApplicant($locked, $actor, __('Offer :reference was rejected in approval.', ['reference' => $locked->reference]));

                return;

            case ApprovalRequestStatus::Returned->value:
                if ($from !== JobOfferStatus::PENDING_APPROVAL) {
                    return;
                }

                $locked->forceFill(['status' => JobOfferStatus::RETURNED])->save();
                $this->record($locked, 'returned', $from, JobOfferStatus::RETURNED, $actor, $last?->reason);

                return;

            case ApprovalRequestStatus::Withdrawn->value:
                if ($from === JobOfferStatus::WITHDRAWN) {
                    return;
                }

                $locked->forceFill([
                    'status'          => JobOfferStatus::WITHDRAWN,
                    'withdrawn_at'    => now(),
                    'withdrawn_by'    => $actor?->id,
                    'withdraw_reason' => $last?->reason,
                ])->save();
                $this->record($locked, 'withdrawn', $from, JobOfferStatus::WITHDRAWN, $actor, $last?->reason);
                $this->logToApplicant($locked, $actor, __('Offer :reference withdrawn.', ['reference' => $locked->reference]));

                return;
        }
    }

    /**
     * Takes the offer back. While in approval this withdraws the approval
     * request (the requester only, engine rule); an approved or sent offer is
     * closed: its emailed link then only says the offer was withdrawn.
     */
    public function withdraw(JobOffer $offer, User $actor, ?string $reason = null): JobOffer
    {
        $this->assertCanManage($actor, $offer);
        $offer->loadMissing('approvalRequest');

        if (! $offer->status->isOpen()) {
            throw new RuntimeException(__('This offer is already closed.'));
        }

        if (in_array($offer->status, [JobOfferStatus::PENDING_APPROVAL, JobOfferStatus::RETURNED], true) && $offer->approvalRequest) {
            if (! $this->approvals->canWithdraw($offer->approvalRequest, $actor)) {
                throw new RuntimeException(__('Only :name, who submitted this offer, can withdraw it while it is in approval.', ['name' => $offer->approvalRequest->requester?->name ?? __('the requester')]));
            }

            $this->approvals->withdraw($offer->approvalRequest, $actor, $reason);

            return $offer->refresh();
        }

        DB::transaction(function () use ($offer, $actor, $reason): void {
            $locked = JobOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw new RuntimeException(__('This offer is already closed.'));
            }

            $from = $locked->status;
            $locked->forceFill([
                'status'          => JobOfferStatus::WITHDRAWN,
                'withdrawn_at'    => now(),
                'withdrawn_by'    => $actor->id,
                'withdraw_reason' => filled($reason) ? trim((string) $reason) : null,
            ])->save();

            $this->record($locked, 'withdrawn', $from, JobOfferStatus::WITHDRAWN, $actor, $reason);
            $this->logToApplicant($locked, $actor, __('Offer :reference withdrawn.', ['reference' => $locked->reference]));
        });

        return $offer->refresh();
    }

    // ---------------------------------------------------------------------
    // Letter and sending
    // ---------------------------------------------------------------------

    /** Renders the approved offer into the company's letter (PDF on the private disk). */
    public function generateLetter(JobOffer $offer, ?User $actor = null, bool $authorize = true): JobOffer
    {
        if ($authorize && (! $actor || ! $this->canManage($actor, $offer) || ! $this->canSeeSalary($actor, $offer))) {
            throw new AuthorizationException(__('Only HR or the hiring manager of this company can generate the offer letter.'));
        }

        if (! in_array($offer->status, [JobOfferStatus::APPROVED, JobOfferStatus::SENT], true)) {
            throw new RuntimeException(__('The offer letter is generated once the offer is approved.'));
        }

        $path = $this->letters->store($offer, $actor ?? $offer->sender ?? $offer->creator);

        $offer->forceFill(['letter_path' => $path, 'letter_generated_at' => now()])->save();
        $this->record($offer, 'letter_generated', $offer->status, $offer->status, $actor);

        return $offer->refresh();
    }

    /** Short-lived signed link for HR to download the letter (the route re-checks the signed-in user). */
    public function letterDownloadUrl(JobOffer $offer, int $minutes = 30): ?string
    {
        return blank($offer->letter_path) ? null : URL::temporarySignedRoute('recruitments.offers.letter.download', now()->addMinutes($minutes), ['offer' => $offer->id]);
    }

    /**
     * Emails the approved offer to the candidate (queued, from the company)
     * with the letter attached and a new accept / decline link; re-sending
     * replaces the link. The application moves to the offer stage.
     */
    public function send(JobOffer $offer, User $actor, ?string $email = null): JobOffer
    {
        if (! $this->canManage($actor, $offer) || ! $this->canSeeSalary($actor, $offer)) {
            throw new AuthorizationException(__('Only HR or the hiring manager of this company can send the offer.'));
        }

        if (! in_array($offer->status, [JobOfferStatus::APPROVED, JobOfferStatus::SENT], true)) {
            throw new RuntimeException(__('Only an approved offer can be sent to the candidate.'));
        }

        if ($offer->expiry_date->toDateString() < $this->today()) {
            throw new RuntimeException(__('This offer expired on :date. Withdraw it and make a new offer.', ['date' => OfferLetterRenderer::date($offer->expiry_date)]));
        }

        $offer->loadMissing(['candidate', 'applicant.candidate', 'company']);
        $address = trim((string) ($email ?: $offer->candidate?->email_from ?: $offer->applicant?->candidate?->email_from));

        if (Validator::make(['email' => $address], ['email' => ['required', 'email']])->fails()) {
            throw ValidationException::withMessages(['email' => __('The candidate has no valid email address.')]);
        }

        if (blank($offer->letter_path) || ! Storage::disk(OfferLetterRenderer::disk())->exists($offer->letter_path)) {
            $offer = $this->generateLetter($offer, $actor);
        }

        $offer = DB::transaction(function () use ($offer, $actor, $address): JobOffer {
            $locked = JobOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [JobOfferStatus::APPROVED, JobOfferStatus::SENT], true)) {
                throw new RuntimeException(__('Only an approved offer can be sent to the candidate.'));
            }

            $from = $locked->status;
            $locked->forceFill([
                'status'         => JobOfferStatus::SENT,
                'sent_at'        => now(),
                'sent_by'        => $actor->id,
                'sent_to'        => $address,
                'response_token' => Str::random(48),
            ])->save();

            $this->record($locked, 'sent', $from, JobOfferStatus::SENT, $actor, __('To :email', ['email' => $address]));
            $this->moveApplicantToOfferStage($locked);
            $this->logToApplicant($locked, $actor, __('Offer :reference sent to :email.', ['reference' => $locked->reference, 'email' => $address]));

            app(RecruitmentMailer::class)->queue(JobOfferMail::class, 'recruitments::mails.job-offer', [
                'kind'           => 'offer',
                'candidate_name' => $locked->candidateName(),
                'position'       => $locked->positionLabel(),
                'company_name'   => (string) $locked->company?->name,
                'reference'      => $locked->reference,
                'joining_date'   => OfferLetterRenderer::date($locked->joining_date),
                'expiry_date'    => OfferLetterRenderer::date($locked->expiry_date),
                'respond_url'    => $this->respondUrl($locked),
                'sender_name'    => $actor->name,
                'subject'        => $this->letters->emailSubject($locked),
                'to'             => ['address' => $address, 'name' => $locked->candidateName()],
            ], $locked->company ?? $locked->company_id, $actor, [[
                'path' => Storage::disk(OfferLetterRenderer::disk())->path($locked->letter_path),
                'name' => Str::slug('Offer letter '.$locked->candidateName()).'.pdf',
                'mime' => 'application/pdf',
            ]]);

            return $locked;
        });

        return $offer->refresh();
    }

    /** The candidate's signed accept / decline link; valid until the end of the expiry date. */
    public function respondUrl(JobOffer $offer): string
    {
        return URL::temporarySignedRoute('recruitments.offers.respond', $this->linkExpiresAt($offer), [
            'offer' => $offer->id,
            'token' => (string) $offer->response_token,
        ]);
    }

    public function linkExpiresAt(JobOffer $offer): Carbon
    {
        return Carbon::parse($offer->expiry_date->toDateString(), OfferLetterRenderer::timezone())->endOfDay();
    }

    /** Whether the token in the link is the offer's current one (a re-send replaces it). */
    public function tokenMatches(JobOffer $offer, string $token): bool
    {
        return filled($offer->response_token) && hash_equals((string) $offer->response_token, $token);
    }

    public function isPastExpiry(JobOffer $offer): bool
    {
        return $offer->expiry_date->toDateString() < $this->today();
    }

    /**
     * The candidate's answer from the public page. Accepting moves the
     * application to the hired stage; recruiters are told either way.
     *
     * @param  array{ip?: ?string, user_agent?: ?string}  $meta
     */
    public function respond(JobOffer $offer, string $token, bool $accept, ?string $reason = null, array $meta = []): JobOffer
    {
        $reason = filled($reason) ? Str::limit(trim((string) $reason), 2000, '') : null;

        $offer = DB::transaction(function () use ($offer, $token, $accept, $reason, $meta): JobOffer {
            $locked = JobOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! $this->tokenMatches($locked, $token)) {
                throw new AuthorizationException(__('This link is no longer valid.'));
            }

            if ($locked->status !== JobOfferStatus::SENT) {
                throw new RuntimeException(__('This offer is no longer open.'));
            }

            if ($this->isPastExpiry($locked)) {
                throw new RuntimeException(__('This offer has expired.'));
            }

            $to = $accept ? JobOfferStatus::ACCEPTED : JobOfferStatus::DECLINED;
            $locked->forceFill([
                'status'              => $to,
                'responded_at'        => now(),
                'response_reason'     => $reason,
                'response_ip'         => Str::limit((string) ($meta['ip'] ?? ''), 45, '') ?: null,
                'response_user_agent' => Str::limit((string) ($meta['user_agent'] ?? ''), 500, '') ?: null,
            ])->save();

            $this->record($locked, $accept ? 'accepted' : 'declined', JobOfferStatus::SENT, $to, null, $reason);

            if ($accept) {
                $this->moveApplicantToHiredStage($locked);
            }

            $this->logToApplicant($locked, null, $accept
                ? __('The candidate accepted offer :reference.', ['reference' => $locked->reference])
                : __('The candidate declined offer :reference.', ['reference' => $locked->reference]).($reason ? ' '.__('Reason: :reason', ['reason' => $reason]) : ''));

            return $locked;
        });

        DB::afterCommit(fn () => $this->notifier->responded($offer->fresh(), $accept));

        return $offer->refresh();
    }

    /**
     * Daily: offers whose expiry date has passed without an answer (sent, or
     * approved and never sent) become expired, and the recruiter is told.
     * Each offer is claimed with one conditional update, so an overlapping
     * run or a re-run never expires (or notifies) twice.
     */
    public function expireDue(?string $today = null): int
    {
        $today ??= $this->today();
        $open = [JobOfferStatus::APPROVED->value, JobOfferStatus::SENT->value];
        $expired = 0;

        $ids = JobOffer::query()->whereIn('status', $open)->where('expiry_date', '<', $today)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $from = JobOfferStatus::tryFrom((string) JobOffer::query()->whereKey($id)->toBase()->value('status'));
            $claimed = JobOffer::query()->whereKey($id)->whereIn('status', $open)->where('expiry_date', '<', $today)
                ->update(['status' => JobOfferStatus::EXPIRED->value, 'expired_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            $offer = JobOffer::query()->findOrFail($id);
            $this->record($offer, 'expired', $from, JobOfferStatus::EXPIRED, null);
            $this->logToApplicant($offer, null, __('Offer :reference expired without an answer.', ['reference' => $offer->reference]));

            try {
                $this->notifier->expired($offer);
            } catch (Throwable $e) {
                report($e);
            }

            $expired++;
        }

        return $expired;
    }

    // ---------------------------------------------------------------------
    // Hire
    // ---------------------------------------------------------------------

    /**
     * The fields "Create employee" fills from the accepted offer, for the
     * confirmation form.
     *
     * @return array<string, mixed>
     */
    public function employeeDefaults(JobOffer $offer, ?User $user = null): array
    {
        $attributes = $offer->employeeAttributes();

        if (! $this->canSeeSalary($user, $offer)) {
            unset($attributes['base_salary']);
        }

        return $attributes;
    }

    /**
     * One click from the accepted offer to the employee, through the
     * existing applicant -> employee conversion (which starts onboarding):
     * joining date, salary, department, line manager and designation come
     * from the offer; HR may adjust the date, department and manager.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function createEmployee(JobOffer $offer, User $actor, array $overrides = []): Employee
    {
        if ($offer->status !== JobOfferStatus::ACCEPTED) {
            throw new RuntimeException(__('Create the employee once the candidate has accepted the offer.'));
        }

        if (! $this->canCreateEmployee($actor, $offer)) {
            throw new AuthorizationException(__('You are not allowed to create employees from offers of this company.'));
        }

        $companyId = (int) $offer->company_id;
        $data = Validator::make($overrides, [
            'joining_date'  => ['nullable', 'date'],
            'department_id' => ['nullable', 'integer', Rule::exists('employees_departments', 'id')->where('company_id', $companyId)],
            'parent_id'     => ['nullable', 'integer', Rule::exists('employees_employees', 'id')->where('company_id', $companyId)],
        ])->validate();

        $attributes = array_merge($offer->employeeAttributes(), array_filter([
            'joining_date'  => isset($data['joining_date']) ? Carbon::parse($data['joining_date'])->toDateString() : null,
            'department_id' => $data['department_id'] ?? null,
            'parent_id'     => $data['parent_id'] ?? null,
        ]));

        $employee = app(CandidateConversionService::class)->convert($offer->applicant, $attributes, $offer);

        if (! $employee) {
            throw new RuntimeException(__('The candidate needs a linked contact before they can become an employee.'));
        }

        return $employee;
    }

    /** Called by CandidateConversionService once the employee exists. */
    public function linkEmployee(JobOffer $offer, Employee $employee): void
    {
        if ((int) $offer->employee_id === (int) $employee->id) {
            return;
        }

        $offer->forceFill(['employee_id' => $employee->id])->save();
        $actor = CurrentCompany::user();
        $this->record($offer, 'employee_created', $offer->status, $offer->status, $actor, $employee->name, ['employee_id' => $employee->id]);
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    public function ceoThreshold(int $companyId): string
    {
        return OfferSetting::ceoThresholdFor($companyId);
    }

    public function saveSettings(int $companyId, ?string $threshold, User $actor): OfferSetting
    {
        if (! $this->canManageTemplates($actor, $companyId)) {
            throw new AuthorizationException(__('Only HR of this company can change the offer settings.'));
        }

        $data = Validator::make(['ceo_approval_threshold' => $threshold], [
            'ceo_approval_threshold' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ])->validate();

        return OfferSetting::query()->updateOrCreate(
            ['company_id' => $companyId],
            ['ceo_approval_threshold' => $data['ceo_approval_threshold'] ?? null, 'updated_by' => $actor->id],
        );
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** Today in the offers' timezone (Y-m-d). */
    public function today(): string
    {
        return now(OfferLetterRenderer::timezone())->toDateString();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validated(array $data, int $companyId, bool $withSalary, ?JobOffer $offer = null): array
    {
        $today = $this->today();
        $rules = [
            'job_id'               => ['nullable', 'integer', Rule::exists('employees_job_positions', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'department_id'        => ['nullable', 'integer', Rule::exists('employees_departments', 'id')->where('company_id', $companyId)],
            'reporting_manager_id' => ['nullable', 'integer', Rule::exists('employees_employees', 'id')->where('company_id', $companyId)],
            'template_id'          => ['nullable', 'integer', Rule::exists('recruitments_offer_letter_templates', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'designation'          => ['nullable', 'string', 'max:255'],
            'joining_date'         => ['required', 'date', 'after_or_equal:'.$today],
            'expiry_date'          => ['required', 'date', 'after_or_equal:'.$today, 'before_or_equal:joining_date'],
            'probation_months'     => ['nullable', 'integer', 'min:0', 'max:24'],
            'working_hours'        => ['nullable', 'string', 'max:255'],
            'benefits'             => ['nullable', 'string', 'max:5000'],
            'internal_notes'       => ['nullable', 'string', 'max:5000'],
        ];

        if ($withSalary) {
            $rules['salary_gross'] = ['nullable', 'numeric', 'gt:0', 'max:9999999999'];

            foreach (array_keys(JobOffer::BREAKDOWN_COMPONENTS) as $component) {
                $rules["salary_breakdown.{$component}"] = ['nullable', 'numeric', 'min:0', 'max:9999999999'];
            }
        }

        $validated = Validator::make($data, $rules, [
            'expiry_date.before_or_equal' => __('The offer must expire on or before the joining date.'),
            'joining_date.after_or_equal' => __('The joining date cannot be in the past.'),
            'expiry_date.after_or_equal'  => __('The expiry date cannot be in the past.'),
        ])->validate();

        $attributes = collect($validated)->except(['salary_gross', 'salary_breakdown'])->all();
        $attributes['joining_date'] = Carbon::parse($validated['joining_date'])->toDateString();
        $attributes['expiry_date'] = Carbon::parse($validated['expiry_date'])->toDateString();

        foreach (['designation', 'working_hours', 'benefits', 'internal_notes'] as $text) {
            if (array_key_exists($text, $attributes)) {
                $attributes[$text] = filled($attributes[$text]) ? trim((string) $attributes[$text]) : null;
            }
        }

        if ($withSalary) {
            $gross = $validated['salary_gross'] ?? null;
            $breakdown = collect((array) ($validated['salary_breakdown'] ?? []))
                ->only(array_keys(JobOffer::BREAKDOWN_COMPONENTS))
                ->filter(fn ($amount): bool => $amount !== null && $amount !== '')
                ->map(fn ($amount): string => (string) BigDecimal::of((string) $amount)->toScale(2))
                ->all();

            if ($breakdown !== [] && $gross !== null) {
                $sum = array_reduce($breakdown, fn (BigDecimal $carry, string $amount): BigDecimal => $carry->plus($amount), BigDecimal::zero());

                if (! $sum->isEqualTo(BigDecimal::of((string) $gross)->toScale(2))) {
                    throw ValidationException::withMessages([
                        'salary_breakdown' => __('The breakdown adds up to :sum but the monthly gross is :gross.', [
                            'sum'   => number_format((float) (string) $sum, 2),
                            'gross' => number_format((float) $gross, 2),
                        ]),
                    ]);
                }
            }

            $attributes['salary_gross'] = $gross !== null ? (string) BigDecimal::of((string) $gross)->toScale(2) : null;
            $attributes['salary_breakdown'] = $breakdown === [] ? null : $breakdown;
        }

        return $attributes;
    }

    protected function assertReadyForApproval(JobOffer $offer): void
    {
        if ($offer->salary_gross === null || (float) $offer->salary_gross <= 0) {
            throw new RuntimeException(__('Enter the monthly gross salary before submitting (HR or the hiring manager).'));
        }

        $today = $this->today();

        if ($offer->joining_date->toDateString() < $today || $offer->expiry_date->toDateString() < $today) {
            throw new RuntimeException(__('The joining and expiry dates must not be in the past. Edit the offer first.'));
        }

        if (! $this->letters->template($offer)) {
            throw new RuntimeException(__('This company has no active offer letter template (Recruitment > Configurations > Offer letter templates).'));
        }
    }

    protected function pendingApproval(JobOffer $offer): ApprovalRequest
    {
        $offer->loadMissing('approvalRequest');

        if ($offer->status !== JobOfferStatus::PENDING_APPROVAL || ! $offer->approvalRequest) {
            throw new RuntimeException(__('This offer is not waiting for approval.'));
        }

        return $offer->approvalRequest;
    }

    /** @return array<int, string> labels of the terms that changed (no values: the salary stays private) */
    protected function changedTerms(JobOffer $offer): array
    {
        $labels = [
            'job_id'               => __('Job'),
            'designation'          => __('Designation'),
            'department_id'        => __('Department'),
            'reporting_manager_id' => __('Reporting manager'),
            'joining_date'         => __('Joining date'),
            'expiry_date'          => __('Expiry date'),
            'salary_gross'         => __('Salary'),
            'salary_breakdown'     => __('Salary breakdown'),
            'probation_months'     => __('Probation'),
            'working_hours'        => __('Working hours'),
            'benefits'             => __('Benefits'),
            'template_id'          => __('Letter template'),
        ];

        $sorted = function (mixed $value): array {
            $value = is_string($value) ? (array) json_decode($value, true) : (array) $value;
            ksort($value);

            return $value;
        };

        return collect($labels)
            ->filter(fn (string $label, string $field): bool => $field === 'salary_breakdown'
                // The JSON column may store the keys in another order: compare the amounts, not the text.
                ? $sorted($offer->getRawOriginal('salary_breakdown')) !== $sorted($offer->salary_breakdown)
                : $offer->isDirty($field))
            ->values()
            ->all();
    }

    /** @return array<int, string> the terms changed since the offer was last returned */
    protected function changedSinceReturn(JobOffer $offer): array
    {
        $returnedAt = JobOfferEvent::query()->where('job_offer_id', $offer->id)->where('event', 'returned')->max('id');

        return JobOfferEvent::query()
            ->where('job_offer_id', $offer->id)
            ->where('event', 'updated')
            ->when($returnedAt, fn ($query) => $query->where('id', '>', $returnedAt))
            ->get()
            ->flatMap(fn (JobOfferEvent $event): array => (array) ($event->meta['changed'] ?? []))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> the offer's terms without the salary, for the approval audit trail */
    protected function termsSnapshot(JobOffer $offer): array
    {
        return [
            'designation'   => $offer->designation,
            'department_id' => $offer->department_id,
            'joining_date'  => $offer->joining_date?->toDateString(),
            'expiry_date'   => $offer->expiry_date?->toDateString(),
        ];
    }

    protected function assertCanManage(User $actor, Applicant|JobOffer $subject): void
    {
        if (! $this->canManage($actor, $subject)) {
            throw new AuthorizationException(__('Only recruiters and HR of this company (in their active company) can manage its offers.'));
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function record(JobOffer $offer, string $event, ?JobOfferStatus $from, ?JobOfferStatus $to, ?User $actor, ?string $note = null, array $meta = []): void
    {
        JobOfferEvent::query()->create([
            'job_offer_id' => $offer->id,
            'event'        => $event,
            'from_status'  => $from?->value,
            'to_status'    => $to?->value,
            'actor_id'     => $actor?->id,
            'note'         => filled($note) ? Str::limit(trim((string) $note), 2000, '') : null,
            'meta'         => $meta === [] ? null : $meta,
        ]);
    }

    /** An internal note on the application's chatter (never with the salary). */
    protected function logToApplicant(JobOffer $offer, ?User $actor, string $text): void
    {
        try {
            $applicant = $offer->applicant;

            $applicant?->addMessage(array_filter([
                'type'        => 'note',
                'is_internal' => true,
                'body'        => '<p>'.e($text).'</p>',
                'company_id'  => $offer->company_id,
                'causer_type' => $actor?->getMorphClass(),
                'causer_id'   => $actor?->id,
            ], fn ($value): bool => $value !== null));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Sending the offer puts the application in the offer stage ("Contract Proposal" / "Offer"), never backwards. */
    protected function moveApplicantToOfferStage(JobOffer $offer): void
    {
        $stage = $this->stageQuery((int) $offer->company_id)
            ->where('hired_stage', false)
            ->where(fn ($query) => $query->where('pipeline_code', 'offer')->orWhereIn('name', ['Contract Proposal', 'Offer']))
            ->first();

        $this->moveApplicant($offer, $stage, false);
    }

    /** Accepting puts the application in the hired stage and marks it hired. */
    protected function moveApplicantToHiredStage(JobOffer $offer): void
    {
        $this->moveApplicant($offer, $this->stageQuery((int) $offer->company_id)->where('hired_stage', true)->first(), true);
    }

    protected function stageQuery(int $companyId)
    {
        return Stage::query()
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort')
            ->orderBy('id');
    }

    protected function moveApplicant(JobOffer $offer, ?Stage $stage, bool $hired): void
    {
        try {
            $applicant = Applicant::query()->withTrashed()->with('stage')->find($offer->applicant_id);

            if (! $applicant || $applicant->refuse_reason_id) {
                return;
            }

            if ($hired && ! $applicant->date_closed) {
                $applicant->setAsHired();
            }

            if ($stage && (int) $applicant->stage_id !== (int) $stage->id
                && ($hired || ! $applicant->stage || (int) $applicant->stage->sort < (int) $stage->sort)) {
                $applicant->update(['stage_id' => $stage->id]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
