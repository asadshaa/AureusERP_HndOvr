<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Support\DefaultChecklistTemplates;
use Webkul\Support\Models\Company;

class ProvisionChecklistTemplates extends Command
{
    protected $signature = 'hr:provision-checklists';

    protected $description = 'Create the default onboarding and exit checklist templates for every company that has none yet (idempotent; never changes existing templates)';

    public function handle(): int
    {
        Company::query()->orderBy('id')->each(function (Company $company): void {
            $created = DefaultChecklistTemplates::provisionForCompany((int) $company->id);

            $this->line(sprintf('%s (#%d): %d new template(s)', $company->name, $company->id, $created));
        });

        $this->info('Done. Existing templates were left unchanged.');

        return self::SUCCESS;
    }
}
