<?php

namespace Webkul\Recruitment\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Recruitment\Services\OfferLetterRenderer;

/**
 * The candidate's offer page (HR parity 3.6): public, reached only through
 * the signed link in the offer email. A tampered link, or one replaced by a
 * re-send, is refused; a link past the offer's expiry date shows the offer
 * as expired. The page shows the offer summary and takes Accept or Decline
 * (with an optional reason) once.
 */
class JobOfferResponseController extends Controller
{
    public function __construct(
        protected JobOfferService $offers,
    ) {}

    public function show(Request $request, int $offer, string $token): Response
    {
        [$record, $state] = $this->resolve($request, $offer, $token);

        return $this->page($record, $state, $request->fullUrl());
    }

    public function respond(Request $request, int $offer, string $token): Response|RedirectResponse
    {
        [$record, $state] = $this->resolve($request, $offer, $token);

        if ($state !== 'open') {
            return $this->page($record, $state, $request->fullUrl());
        }

        $data = $request->validate([
            'decision' => ['required', 'in:accept,decline'],
            'reason'   => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->offers->respond($record, $token, $data['decision'] === 'accept', $data['reason'] ?? null, [
                'ip'         => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        } catch (AuthorizationException) {
            return $this->page($record, 'invalid', null, 403);
        } catch (RuntimeException) {
            return $this->page($record->refresh(), $this->stateOf($record), $request->fullUrl());
        }

        return redirect()->to($request->fullUrl());
    }

    /** The letter PDF for the candidate, through the same signed link. */
    public function letter(Request $request, int $offer, string $token): StreamedResponse|Response
    {
        [$record, $state] = $this->resolve($request, $offer, $token);

        if (in_array($state, ['invalid', 'expired', 'closed'], true) || blank($record?->letter_path) || ! Storage::disk(OfferLetterRenderer::disk())->exists($record->letter_path)) {
            return $this->page($record, $state === 'expired' ? 'expired' : 'invalid', null, $state === 'expired' ? 410 : 403);
        }

        return Storage::disk(OfferLetterRenderer::disk())->download($record->letter_path, Str::slug('Offer letter '.$record->candidateName()).'.pdf');
    }

    /**
     * @return array{0: ?JobOffer, 1: string} the offer and its state: invalid, expired, closed, open, accepted or declined
     */
    protected function resolve(Request $request, int $offerId, string $token): array
    {
        if (! URL::hasCorrectSignature($request)) {
            return [null, 'invalid'];
        }

        $offer = JobOffer::query()->with(['company', 'candidate', 'applicant.candidate', 'job', 'department', 'reportingManager', 'currency'])->find($offerId);

        if (! $offer || ! $this->offers->tokenMatches($offer, $token)) {
            return [null, 'invalid'];
        }

        if (! URL::signatureHasNotExpired($request)) {
            return [$offer, in_array($offer->status, [JobOfferStatus::ACCEPTED, JobOfferStatus::DECLINED], true) ? $offer->status->value : 'expired'];
        }

        return [$offer, $this->stateOf($offer)];
    }

    protected function stateOf(JobOffer $offer): string
    {
        return match (true) {
            $offer->status === JobOfferStatus::ACCEPTED => 'accepted',
            $offer->status === JobOfferStatus::DECLINED => 'declined',
            $offer->status === JobOfferStatus::EXPIRED,
            $offer->status === JobOfferStatus::SENT && $this->offers->isPastExpiry($offer) => 'expired',
            $offer->status === JobOfferStatus::SENT                                        => 'open',
            default                                                                        => 'closed',
        };
    }

    protected function page(?JobOffer $offer, string $state, ?string $action, ?int $status = null): Response
    {
        $status ??= match ($state) {
            'invalid'           => 403,
            'expired', 'closed' => 410,
            default             => 200,
        };

        $offer = $state === 'invalid' ? null : $offer;

        return response()->view('recruitments::offers.respond', [
            'offer'     => $offer,
            'state'     => $state,
            'action'    => $action,
            'currency'  => $offer?->currencyCode(),
            'letterUrl' => $offer && $state === 'open' && filled($offer->letter_path)
                ? URL::temporarySignedRoute('recruitments.offers.letter', $this->offers->linkExpiresAt($offer), ['offer' => $offer->id, 'token' => (string) $offer->response_token])
                : null,
        ], $status);
    }
}
