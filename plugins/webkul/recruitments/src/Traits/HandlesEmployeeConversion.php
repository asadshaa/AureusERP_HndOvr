<?php

namespace Webkul\Recruitment\Traits;

use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Services\CandidateConversionService;

/**
 * Shared behaviour for the "Create employee" / "Go to employee" header
 * actions on the Applicant and Candidate pages. Conversion itself is
 * authorised inside CandidateConversionService; this only decides what
 * the buttons show and turns a refusal into a message instead of an error.
 */
trait HandlesEmployeeConversion
{
    protected static function userCanConvert(): bool
    {
        return CandidateConversionService::canConvert(Auth::user());
    }

    /**
     * @param  callable(): ?Employee  $convert
     */
    protected function convertAndRedirect(callable $convert, string $page)
    {
        try {
            $employee = $convert();
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title('Could not create the employee')->body($e->getMessage())->send();

            return null;
        }

        if (! $employee) {
            Notification::make()
                ->warning()
                ->title('The candidate needs a linked contact before they can become an employee.')
                ->send();

            return null;
        }

        return redirect(EmployeeResource::getUrl($page, ['record' => $employee]));
    }
}
