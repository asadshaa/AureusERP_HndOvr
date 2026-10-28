<?php

namespace Webkul\Employee\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Security\Models\User;

/**
 * Downloads the letter HR issued for an HR letter request. The link is a
 * short-lived signed URL, and the signed-in user must still be the employee,
 * the requester, or an HR approver of the request's company.
 */
class EmployeeRequestLetterController extends Controller
{
    public function __invoke(Request $request, EmployeeRequest $employeeRequest, EmployeeRequestService $service): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $service->canDownloadLetter($user, $employeeRequest), 403);

        $path = (string) data_get($employeeRequest->payload, 'letter_path');

        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404);

        $label = EmployeeRequestKinds::letterTypeLabel(data_get($employeeRequest->payload, 'letter_type'));
        $name = Str::slug($label.' '.($employeeRequest->employee?->name ?? $employeeRequest->id)).'.'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'pdf');

        return Storage::disk('local')->download($path, $name);
    }
}
