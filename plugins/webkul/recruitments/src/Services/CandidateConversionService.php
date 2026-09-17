<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;

class CandidateConversionService
{
    public function convert(Applicant $application): ?Employee
    {
        return DB::transaction(function () use ($application): ?Employee {
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

            $employee = Employee::query()->create([
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
            ]);

            $candidate->update(['employee_id' => $employee->id]);
            $application->setAsHired();

            return $employee;
        });
    }
}
