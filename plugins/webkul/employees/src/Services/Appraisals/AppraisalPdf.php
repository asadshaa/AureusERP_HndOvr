<?php

namespace Webkul\Employee\Services\Appraisals;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;

/**
 * The printable appraisal: company letterhead, the employee, every section
 * with the ratings and comments the viewer may see (AppraisalSheet), the
 * scores and band, next-period goals and the signatures. Rendered on
 * request, never stored.
 */
class AppraisalPdf
{
    public function __construct(
        protected AppraisalSheet $sheet,
    ) {}

    public function output(PerformanceReview $review, User $viewer): string
    {
        $sheet = $this->sheet->build($review, $viewer);

        return Pdf::loadView('employees::appraisals.appraisal-pdf', [
            'sheet'       => $sheet,
            'logo'        => $this->logoDataUri($review->company?->logo),
            'printedOn'   => now(AppraisalKinds::timezone()),
            'printedBy'   => $viewer->name,
        ])->setPaper('a4')->output();
    }

    public function filename(PerformanceReview $review): string
    {
        return Str::slug('appraisal '.($review->employee?->name ?? $review->getKey()).' '.($review->cycle?->name ?? '')).'.pdf';
    }

    /** The company logo inlined for dompdf (no remote fetching). */
    protected function logoDataUri(?string $logo): ?string
    {
        if (blank($logo)) {
            return null;
        }

        foreach (['public', 'local'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($logo)) {
                    $mime = Storage::disk($disk)->mimeType($logo) ?: 'image/png';

                    return str_starts_with($mime, 'image/') ? 'data:'.$mime.';base64,'.base64_encode(Storage::disk($disk)->get($logo)) : null;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
