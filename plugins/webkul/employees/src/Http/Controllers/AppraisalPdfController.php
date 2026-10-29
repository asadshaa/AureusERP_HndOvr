<?php

namespace Webkul\Employee\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Services\Appraisals\AppraisalPdf;
use Webkul\Security\Models\User;

/**
 * The printable PDF of a reviewed appraisal, for whoever may read its
 * result: HR of the active company, the reviewer / line manager, and the
 * employee once the result is shared (AppraisalAccess::canDownloadPdf()).
 */
class AppraisalPdfController extends Controller
{
    public function __invoke(Request $request, int $review, AppraisalAccess $access, AppraisalPdf $pdf): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $appraisal = PerformanceReview::query()->with(['cycle', 'employee', 'reviewer.user'])->find($review);

        abort_unless($appraisal instanceof PerformanceReview && $access->canDownloadPdf($user, $appraisal), 403);

        return response($pdf->output($appraisal, $user), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($appraisal).'"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }
}
