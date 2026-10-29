<?php

namespace Webkul\Recruitment\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Recruitment\Services\OfferLetterRenderer;
use Webkul\Security\Models\User;

/**
 * HR's download of an offer letter. The link is a short-lived signed URL
 * (JobOfferService::letterDownloadUrl) and the signed-in user must still be
 * allowed to see the offer's salary (HR, hiring managers, its approvers).
 */
class JobOfferLetterController extends Controller
{
    public function __invoke(Request $request, JobOffer $offer, JobOfferService $offers): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $offers->canView($user, $offer) && $offers->canSeeSalary($user, $offer), 403);
        abort_unless(filled($offer->letter_path) && Storage::disk(OfferLetterRenderer::disk())->exists($offer->letter_path), 404);

        return Storage::disk(OfferLetterRenderer::disk())->download($offer->letter_path, Str::slug('Offer letter '.$offer->candidateName().' '.$offer->reference).'.pdf');
    }
}
