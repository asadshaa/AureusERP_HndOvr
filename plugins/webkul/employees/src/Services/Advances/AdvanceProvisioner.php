<?php

namespace Webkul\Employee\Services\Advances;

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Employee\Database\Seeders\ClaimsWorkflowSeeder;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Support\AdvanceKinds;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

/**
 * Creates, for one company, what Business Advances need: the "Employee
 * Advances" asset account (one shared account, linked to the company like the
 * rest of the chart), the Business Advance and Advance Settlement request types
 * and their approval workflows.
 *
 * Idempotent and conservative: an existing type or workflow is never changed
 * apart from filling in accounting that is still empty, so anything HR or an
 * accountant edited stays. The approval chain is a copy of the company's own
 * Travel and Entertainment claim chain (so Truck It In keeps its named
 * approvers and the other companies keep line manager -> HR -> finance); a
 * company without claim workflows gets that company chain. A company that has
 * no purchase or general journal, or no expense / payable account yet, is
 * skipped and reported, because the types could not post.
 */
class AdvanceProvisioner
{
    public const ExpenseAccountCode = '600000';

    public const PayableAccountCode = '211000';

    /** Claim workflow whose steps the advance workflows copy. */
    private const ChainSource = 'claim_travel_entertainment';

    /** @var array<string, string> */
    private const AdvanceNatures = ['expense_natures' => 'Travel, Accommodation, Daily Allowance, Fuel, Food, Event, Purchase for office, Other'];

    public function __construct(
        protected ClaimsWorkflowSeeder $claims,
    ) {}

    /**
     * @return array{skipped: ?string, account_created: bool, account_linked: bool, types_created: int, types_updated: int, workflows_created: int, steps_created: int}
     */
    public function provisionForCompany(Company $company): array
    {
        $summary = ['skipped' => null, 'account_created' => false, 'account_linked' => false, 'types_created' => 0, 'types_updated' => 0, 'workflows_created' => 0, 'steps_created' => 0];

        $purchase = Journal::query()->where('company_id', $company->id)->where('type', JournalType::PURCHASE)->first();
        $general = Journal::query()->where('company_id', $company->id)->where('type', JournalType::GENERAL)->first();
        $expense = $this->linkedAccount(self::ExpenseAccountCode, $company);
        $payable = $this->linkedAccount(self::PayableAccountCode, $company);

        if (! $purchase || ! $general || ! $expense || ! $payable) {
            $summary['skipped'] = 'No purchase journal, general journal, expense account ('.self::ExpenseAccountCode.') or payable account ('.self::PayableAccountCode.') yet.';

            return $summary;
        }

        $advances = $this->advancesAccount($company, $summary);

        $this->provisionType($company, $summary, AdvanceKinds::AdvanceCode, [
            'name'              => 'Business Advance',
            'category'          => AdvanceKinds::AdvanceCategory,
            'requires_document' => false,
            'journal_id'        => $purchase->id,
            'debit_account_id'  => $advances->id,
            'credit_account_id' => $payable->id,
            'configuration'     => self::AdvanceNatures + [AdvanceKinds::SettleWithinDaysKey => (string) AdvanceKinds::DefaultSettleWithinDays],
        ], 'Business Advance Approval');

        $this->provisionType($company, $summary, AdvanceKinds::SettlementCode, [
            'name'              => 'Advance Settlement',
            'category'          => AdvanceKinds::SettlementCategory,
            'requires_document' => true,
            'journal_id'        => $general->id,
            'debit_account_id'  => $expense->id,
            'credit_account_id' => $advances->id,
            'configuration'     => null,
        ], 'Advance Settlement Approval', financial: false);

        return $summary;
    }

    private function linkedAccount(string $code, Company $company): ?Account
    {
        return Account::query()->where('code', $code)->where('is_group', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
            ->first();
    }

    /** @param  array<string, mixed>  $summary */
    private function advancesAccount(Company $company, array &$summary): Account
    {
        $account = Account::query()->where('code', AdvanceKinds::AdvancesAccountCode)->first();

        if (! $account) {
            $account = Account::query()->create([
                'currency_id'  => Account::query()->where('code', '128000')->value('currency_id') ?? $company->currency_id,
                'account_type' => AccountType::ASSET_CURRENT,
                'name'         => AdvanceKinds::AdvancesAccountName,
                'code'         => AdvanceKinds::AdvancesAccountCode,
                'note'         => 'Money paid to employees before they spend it on company work. Cleared by approved advance settlements and by cash handed back.',
                'reconcile'    => true,
                'non_trade'    => false,
                'is_group'     => false,
                'deprecated'   => false,
            ]);
            $summary['account_created'] = true;
        }

        if (! $account->companies()->where('companies.id', $company->id)->exists()) {
            $account->companies()->attach($company->id);
            $summary['account_linked'] = true;
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $type
     */
    private function provisionType(Company $company, array &$summary, string $code, array $type, string $workflowName, bool $financial = true): void
    {
        $existing = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', $code)->first();
        $accounting = ['journal_id' => $type['journal_id'], 'debit_account_id' => $type['debit_account_id'], 'credit_account_id' => $type['credit_account_id']];

        if (! $existing) {
            EmployeeRequestType::query()->create([
                'company_id'            => $company->id,
                'code'                  => $code,
                'name'                  => $type['name'],
                'category'              => $type['category'],
                'approval_request_type' => $code,
                'is_financial'          => $financial,
                'requires_amount'       => true,
                'requires_document'     => $type['requires_document'],
                'is_active'             => true,
                'configuration'         => $type['configuration'],
                ...$accounting,
            ]);
            $summary['types_created']++;
        } elseif (! $existing->journal_id && ! $existing->debit_account_id) {
            $existing->update($accounting);
            $summary['types_updated']++;
        }

        $workflow = ApprovalWorkflow::query()->firstOrCreate(
            ['company_id' => $company->id, 'request_type' => $code],
            ['name' => $workflowName, 'is_active' => true]
        );
        $summary['workflows_created'] += $workflow->wasRecentlyCreated ? 1 : 0;

        if ($workflow->steps()->exists()) {
            return;
        }

        $summary['steps_created'] += $this->copyChain($company, $workflow);
    }

    private function copyChain(Company $company, ApprovalWorkflow $workflow): int
    {
        $source = ApprovalWorkflow::query()->where('company_id', $company->id)->where('request_type', self::ChainSource)->first();
        $steps = $source ? $source->steps()->orderBy('sequence')->get() : collect();

        if ($steps->isNotEmpty()) {
            foreach ($steps as $step) {
                $copy = $step->replicate();
                $copy->workflow_id = $workflow->id;
                $copy->save();
            }

            return $steps->count();
        }

        $sequence = 1;
        foreach ([['Line Manager Review', 'requester_manager'], ['HR Review', 'hr']] as [$name, $route]) {
            ApprovalStep::query()->create(['workflow_id' => $workflow->id, 'sequence' => $sequence++, 'name' => $name, 'hierarchy_route' => $route, 'required_approvals' => 1]);
        }

        $financeRole = $this->claims->financeApproverRole($company);
        if ($financeRole) {
            ApprovalStep::query()->create(['workflow_id' => $workflow->id, 'sequence' => $sequence++, 'name' => 'Finance Review', 'approver_role_id' => $financeRole->id, 'required_approvals' => 1]);
        }

        return $sequence - 1;
    }
}
