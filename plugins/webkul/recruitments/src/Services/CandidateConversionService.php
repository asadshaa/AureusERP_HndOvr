<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Security\Models\User;

class CandidateConversionService
{
    /**
     * Employee fields an accepted job offer (or the caller) may fill in; the
     * department and line manager are checked against the company by
     * JobOfferService before they get here.
     */
    protected const PREFILL_FIELDS = [
        'joining_date',
        'base_salary',
        'salary_currency_id',
        'department_id',
        'parent_id',
        'job_id',
        'job_title',
        'probation_end_date',
    ];

    /** The single rule for who may turn a candidate into an employee. */
    public static function canConvert(?User $user): bool
    {
        return (bool) $user?->can(HrPermissions::ConvertCandidates);
    }

    /**
     * Creates (or returns the already linked) employee for the application.
     * An accepted job offer of the application prefills the joining date,
     * salary, department, line manager and designation (HR parity 3.6), so
     * every "Create employee" button gets the agreed terms.
     *
     * @param  array<string, mixed>  $attributes  prefill (JobOfferService::createEmployee)
     */
    public function convert(Applicant $application, array $attributes = [], ?JobOffer $offer = null): ?Employee
    {
        $offer ??= $this->acceptedOffer($application);
        $attributes = array_intersect_key(array_merge($offer?->employeeAttributes() ?? [], $attributes), array_flip(self::PREFILL_FIELDS));

        return DB::transaction(function () use ($application, $attributes, $offer): ?Employee {
            $application = Applicant::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $candidate = Candidate::query()->whereKey($application->candidate_id)->lockForUpdate()->firstOrFail();
            if (! $candidate->partner_id) {
                return null;
            }
            if ($candidate->employee_id) {
                $employee = Employee::query()->findOrFail($candidate->employee_id);
                if ((int) $employee->company_id !== (int) $application->company_id) {
                    throw new RuntimeException('The candidate is already linked to an employee in another company.');
                }

                if ($offer) {
                    app(JobOfferService::class)->linkEmployee($offer, $employee);
                }

                return $employee;
            }
            if ($application->refuse_reason_id) {
                throw new RuntimeException('This application was rejected and cannot be converted to an employee.');
            }
            if ((int) $candidate->company_id !== (int) $application->company_id) {
                throw new RuntimeException('Candidate and application companies do not match.');
            }
            if ($application->department_id && ! DB::table('employees_departments')
                ->where('id', $application->department_id)
                ->where('company_id', $application->company_id)
                ->exists()) {
                throw new RuntimeException('The application department does not belong to the application company.');
            }
            if ($application->job_id && ! DB::table('employees_job_positions')
                ->where('id', $application->job_id)
                ->where('company_id', $application->company_id)
                ->exists()) {
                throw new RuntimeException('The application job does not belong to the application company.');
            }

            // Creating an employee is a hiring decision (hiring manager / HR), not a
            // recruiter one. Enforced here so that no UI entry point can bypass it.
            // Returning an already-linked employee (above) needs no extra right.
            if (Auth::check() && ! self::canConvert(Auth::user())) {
                throw new AuthorizationException('You are not allowed to convert candidates to employees.');
            }

            $employee = Employee::query()->create(array_merge([
                'name'              => $candidate->name,
                'job_id'            => $application->job_id,
                'department_id'     => $application->department_id,
                'company_id'        => $application->company_id,
                'partner_id'        => $candidate->partner_id,
                'work_email'        => $candidate->email_from,
                'mobile_phone'      => $candidate->phone,
                'joining_date'      => $application->date_closed ?? now()->toDateString(),
                'employment_status' => 'active',
                'is_active'         => true,
            ], $attributes));

            $candidate->update(['employee_id' => $employee->id]);

            if (! $application->date_closed) {
                $application->setAsHired();
            }

            if ($offer) {
                app(JobOfferService::class)->linkEmployee($offer, $employee);
            }

            return $employee;
        });
    }

    /** The application's accepted job offer still waiting for its employee. */
    protected function acceptedOffer(Applicant $application): ?JobOffer
    {
        static $hasTable = null;
        $hasTable ??= Schema::hasTable('recruitments_job_offers');

        if (! $hasTable) {
            return null;
        }

        return JobOffer::query()
            ->where('applicant_id', $application->id)
            ->where('company_id', $application->company_id)
            ->where('status', JobOfferStatus::ACCEPTED->value)
            ->latest('id')
            ->first();
    }
}
