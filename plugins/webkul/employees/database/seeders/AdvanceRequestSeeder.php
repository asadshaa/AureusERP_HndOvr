<?php

namespace Webkul\Employee\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Employee\Services\Advances\AdvanceProvisioner;
use Webkul\Support\Models\Company;

/**
 * Business Advance and Advance Settlement request types, approval workflows and
 * the Employee Advances account, for every company (see AdvanceProvisioner).
 * Idempotent; run after ClaimsWorkflowSeeder, whose Travel and Entertainment
 * chain the advance workflows copy.
 */
class AdvanceRequestSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(AdvanceProvisioner::class);

        Company::query()->each(function (Company $company) use ($provisioner): void {
            $summary = $provisioner->provisionForCompany($company);

            if ($summary['skipped'] !== null) {
                $this->command?->warn("AdvanceRequestSeeder: {$company->name} skipped. {$summary['skipped']}");
            }
        });
    }
}
