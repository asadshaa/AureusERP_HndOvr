<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

class ProvisionAttendanceWorkflows extends Command
{
    protected $signature = 'hr:provision-attendance-workflows';

    protected $description = 'Create the attendance correction request type and line-manager approval workflow for every company that is missing them (idempotent; never changes existing workflows)';

    public function handle(AttendanceWorkflowSeeder $seeder): int
    {
        $added = 0;

        Company::query()->orderBy('id')->each(function (Company $company) use ($seeder, &$added): void {
            $hadWorkflow = ApprovalWorkflow::query()
                ->where('company_id', $company->id)
                ->where('request_type', 'attendance_time_change')
                ->exists();

            $seeder->provisionForCompany($company);

            if (! $hadWorkflow) {
                $added++;
                $this->line("Provisioned: {$company->name} (#{$company->id})");
            }
        });

        $this->info("Done. {$added} company(ies) newly provisioned; existing workflows were left unchanged.");

        return self::SUCCESS;
    }
}
