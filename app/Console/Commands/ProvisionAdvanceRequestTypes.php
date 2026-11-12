<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Services\Advances\AdvanceProvisioner;
use Webkul\Support\Models\Company;

class ProvisionAdvanceRequestTypes extends Command
{
    protected $signature = 'hr:provision-advances {--company=* : Company id or name; default every company}';

    protected $description = 'Create the Business Advance and Advance Settlement request types, their approval workflows and the Employee Advances account for every company that is missing them (idempotent; existing types and workflows are left unchanged)';

    public function handle(AdvanceProvisioner $provisioner): int
    {
        $filters = array_filter((array) $this->option('company'));

        $companies = Company::query()->orderBy('id')->get()->filter(
            fn (Company $company): bool => $filters === []
                || in_array((string) $company->id, $filters, true)
                || in_array(mb_strtolower($company->name), array_map('mb_strtolower', $filters), true)
        );

        if ($companies->isEmpty()) {
            $this->error('No company matches.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $summary = DB::transaction(fn (): array => $provisioner->provisionForCompany($company));

            $this->line($summary['skipped'] !== null
                ? "{$company->name} (#{$company->id}): skipped. {$summary['skipped']}"
                : sprintf(
                    '%s (#%d): %d type(s) created, %d given accounting, %d workflow(s) created, %d step(s) created%s.',
                    $company->name,
                    $company->id,
                    $summary['types_created'],
                    $summary['types_updated'],
                    $summary['workflows_created'],
                    $summary['steps_created'],
                    $summary['account_created'] ? '; Employee Advances account created' : ($summary['account_linked'] ? '; Employee Advances account linked' : ''),
                ));
        }

        $this->info('Done. Existing request types and workflows were left unchanged.');

        return self::SUCCESS;
    }
}
