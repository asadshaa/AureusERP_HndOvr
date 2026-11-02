<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Services\CompanyAccountingProvisioningService;
use Webkul\Employee\Database\Seeders\ClaimsWorkflowSeeder;
use Webkul\Support\Models\Company;

class ProvisionCompanyAccounting extends Command
{
    protected $signature = 'accounting:provision-company
        {company* : Id(s) of the company or companies to provision}
        {--template=1 : Id of the company whose accounting setup is mirrored (Truck It In)}
        {--without-claims : Leave the expense-claim request types and approval workflows alone}';

    protected $description = 'Set a company up for accounting and invoicing from a template company: shared chart of accounts, journal accounts, Exchange Difference journal, FX accounts, payment terms, fiscal positions and FS tags, plus its expense-claim types and company-local approval workflows (idempotent; never overwrites what is already set)';

    public function handle(CompanyAccountingProvisioningService $accounting, ClaimsWorkflowSeeder $claims): int
    {
        $template = Company::query()->find((int) $this->option('template'));

        if (! $template) {
            $this->error('Template company #'.$this->option('template').' was not found.');

            return self::FAILURE;
        }

        $claims->setCommand($this);
        $withClaims = ! $this->option('without-claims')
            && Schema::hasTable('employees_request_types')
            && Schema::hasTable('support_approval_workflows');
        $failed = false;

        foreach ((array) $this->argument('company') as $companyId) {
            $company = Company::query()->find((int) $companyId);

            if (! $company) {
                $this->error("Company #{$companyId} was not found.");
                $failed = true;

                continue;
            }

            $this->info("{$company->name} (#{$company->id}) from {$template->name} (#{$template->id})");

            try {
                $result = $accounting->provision($company, $template);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());
                $failed = true;

                continue;
            }

            $this->table(['Item', 'Count'], [
                ['Accounts linked', $result['accounts_linked']],
                ['Journals created', $result['journals_created']],
                ['Journals given default accounts', $result['journals_updated']],
                ['Payment methods added to journals', $result['payment_method_lines_created']],
                ['FX gain / loss accounts set', $result['fx_accounts_set']],
                ['Payment terms created', $result['payment_terms_created']],
                ['Fiscal positions created', $result['fiscal_positions_created']],
                ['FS tags created', $result['fs_tags_created']],
            ]);

            foreach ($result['warnings'] as $warning) {
                $this->warn($warning);
            }

            if ($withClaims) {
                $summary = DB::transaction(fn (): array => $claims->provisionForCompany($company->fresh()));

                $this->line(sprintf(
                    'Expense claims (%s chain): %d type(s) created, %d given accounting, %d workflow(s) created, %d step(s) created; %s; finance step: %s.',
                    $summary['chain'],
                    $summary['types_created'],
                    $summary['types_updated'],
                    $summary['workflows_created'],
                    $summary['steps_created'],
                    $summary['financial'] ? 'approved claims post a vendor bill' : 'non-financial (no Purchase journal / 600000 / 211000)',
                    $summary['finance_step'] ? ClaimsWorkflowSeeder::FinanceApproverRole : 'none yet (no active '.ClaimsWorkflowSeeder::FinanceApproverRole.' in this company)',
                ));
            }

            $this->reportUserActions($company->fresh());
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function reportUserActions(Company $company): void
    {
        $actions = [];

        if (blank($company->tax_id)) {
            $actions[] = 'Enter the NTN (Tax ID) on the company.';
        }

        if (blank($company->strn) || ! $company->is_sales_tax_registered) {
            $actions[] = 'Enter the STRN and tick "sales-tax registered"; until then invoices with sales tax cannot be posted.';
        }

        $hasRealBank = Journal::query()
            ->where('company_id', $company->id)
            ->where('type', JournalType::BANK)
            ->whereHas('defaultAccount', fn ($query) => $query->whereHas('accountingDetail'))
            ->exists();

        if (! $hasRealBank) {
            $actions[] = 'Create a bank GL account and bank journal for each real bank account (Accounting > Import Bank Statement > new bank account), then the bank mapping rules for those accounts.';
        }

        foreach ($actions as $action) {
            $this->warn("Action needed: {$action}");
        }
    }
}
