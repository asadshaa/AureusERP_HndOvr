<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Support\DefaultAppraisalTemplate;
use Webkul\Support\Models\Company;

class ProvisionAppraisalTemplates extends Command
{
    protected $signature = 'hr:provision-appraisal-templates';

    protected $description = 'Create the default appraisal template for every company that has none yet (idempotent; never changes existing templates)';

    public function handle(): int
    {
        Company::query()->orderBy('id')->each(function (Company $company): void {
            $created = DefaultAppraisalTemplate::provisionForCompany((int) $company->id);

            $this->line(sprintf('%s (#%d): %s', $company->name, $company->id, $created ? 'default template created' : 'already has it'));
        });

        $this->info('Done. Existing templates were left unchanged.');

        return self::SUCCESS;
    }
}
