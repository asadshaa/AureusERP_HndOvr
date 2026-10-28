<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Database\Seeders\SelfServiceRequestWorkflowSeeder;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Support\Models\Company;

class ProvisionSelfServiceRequestTypes extends Command
{
    protected $signature = 'hr:provision-request-types';

    protected $description = 'Create the Loan, Salary advance and HR letter request types and their approval workflows for every company that is missing them (idempotent; never changes existing types or workflows)';

    public function handle(SelfServiceRequestWorkflowSeeder $seeder): int
    {
        $codes = [EmployeeRequestKinds::LoanCode, EmployeeRequestKinds::SalaryAdvanceCode, EmployeeRequestKinds::HrLetterCode];

        Company::query()->orderBy('id')->each(function (Company $company) use ($seeder, $codes): void {
            $before = EmployeeRequestType::query()->where('company_id', $company->id)->whereIn('code', $codes)->count();

            $seeder->provisionForCompany($company);

            $after = EmployeeRequestType::query()->where('company_id', $company->id)->whereIn('code', $codes)->count();
            $finance = $seeder->financeApproverRole($company)?->name;

            $this->line(sprintf(
                '%s (#%d): %d new request type(s); loan finance approver role: %s',
                $company->name,
                $company->id,
                $after - $before,
                $finance ?? 'none (HR is the final approver)',
            ));
        });

        $this->info('Done. Existing request types and workflows were left unchanged.');

        return self::SUCCESS;
    }
}
