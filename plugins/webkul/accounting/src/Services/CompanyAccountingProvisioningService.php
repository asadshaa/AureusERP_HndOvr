<?php

namespace Webkul\Accounting\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\FiscalPosition;
use Webkul\Account\Models\FiscalPositionAccount;
use Webkul\Account\Models\FiscalPositionTax;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Account\Models\Tax;
use Webkul\Account\Settings\DefaultAccountSettings;
use Webkul\Accounting\Models\FsTag;
use Webkul\Support\Models\Company;

/**
 * Sets a company up for accounting and invoicing from a template company
 * (Truck It In), the way the template itself is set up:
 *
 *  - the template's shared chart of accounts is LINKED to the company through
 *    accounts_account_companies, not duplicated. Accounts carry no company_id
 *    and the global defaults (DefaultAccountSettings), products, product
 *    categories, partner property accounts and the companies' tax partitions
 *    already point at these rows, so one shared row per account is what the
 *    posting code expects. Balances stay per company because every move line
 *    carries company_id and every report filters on it.
 *    Company-owned accounts of the template (a bank GL or any account created
 *    through CanonicalAccountCreationService, which has an accounting detail,
 *    or an account from a chart-of-accounts import batch) are never shared;
 *  - the template's journals that the company lacks (matched by code, e.g. the
 *    Exchange Difference journal) are created, empty default / suspense /
 *    profit / loss accounts are filled from the template's journal of the same
 *    code, and bank / cash journals get the template's payment methods;
 *  - empty FX gain / loss accounts get the template's (or the global exchange
 *    difference accounts when the template has none of its own);
 *  - the template's own payment terms, fiscal positions (taxes mapped to the
 *    company's tax of the same name) and FS tags (by code) are copied.
 *
 * Idempotent: everything is matched by a business key, values already set on
 * the company are never overwritten, and nothing of the template changes.
 * Bank mapping rules are not copied: they belong to the company's own bank
 * accounts.
 */
final class CompanyAccountingProvisioningService
{
    /** @var array<int, string> */
    private const JournalAccountFields = ['default_account_id', 'suspense_account_id', 'profit_account_id', 'loss_account_id'];

    public function __construct(private readonly FsTagService $fsTags) {}

    /**
     * @return array{
     *     accounts_linked: int,
     *     journals_created: int,
     *     journals_updated: int,
     *     payment_method_lines_created: int,
     *     fx_accounts_set: int,
     *     payment_terms_created: int,
     *     fiscal_positions_created: int,
     *     fs_tags_created: int,
     *     warnings: array<int, string>,
     * }
     */
    public function provision(Company $company, Company $template): array
    {
        if ((int) $company->id === (int) $template->id) {
            throw new RuntimeException("{$company->name} is the template company; it cannot be provisioned from itself.");
        }

        if ((int) $company->currency_id !== (int) $template->currency_id) {
            throw new RuntimeException("{$template->name}'s chart of accounts is kept in its base currency; {$company->name} uses a different base currency. Import a chart of accounts for {$company->name} instead.");
        }

        $sharedAccounts = $this->sharedTemplateAccounts($template);

        if ($sharedAccounts->isEmpty()) {
            throw new RuntimeException("{$template->name} has no shared chart of accounts to provision from.");
        }

        return DB::transaction(function () use ($company, $template, $sharedAccounts): array {
            $result = [
                'accounts_linked'              => 0,
                'journals_created'             => 0,
                'journals_updated'             => 0,
                'payment_method_lines_created' => 0,
                'fx_accounts_set'              => 0,
                'payment_terms_created'        => 0,
                'fiscal_positions_created'     => 0,
                'fs_tags_created'              => 0,
                'warnings'                     => [],
            ];

            $result['accounts_linked'] = $this->linkAccounts($company, $sharedAccounts);

            $companyAccountIds = Account::query()
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
                ->pluck('id')
                ->flip();

            $this->provisionJournals($company, $template, $companyAccountIds, $result);
            $this->provisionFxAccounts($company, $template, $companyAccountIds, $result);
            $this->copyPaymentTerms($company, $template, $result);
            $this->copyFiscalPositions($company, $template, $companyAccountIds, $result);
            $this->copyFsTags($company, $template, $companyAccountIds, $result);

            return $result;
        });
    }

    /**
     * The template's accounts that may be shared: no accounting detail (bank
     * GLs and inline-created accounts always have one) and no import batch.
     *
     * @return Collection<int, Account>
     */
    public function sharedTemplateAccounts(Company $template): Collection
    {
        return Account::query()
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $template->id))
            ->whereNull('import_batch_id')
            ->whereDoesntHave('accountingDetail')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Account>  $sharedAccounts
     */
    private function linkAccounts(Company $company, Collection $sharedAccounts): int
    {
        $alreadyLinked = Account::query()
            ->whereKey($sharedAccounts->modelKeys())
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
            ->pluck('id')
            ->flip();

        $linked = 0;

        foreach ($sharedAccounts as $account) {
            if ($alreadyLinked->has($account->id)) {
                continue;
            }

            $account->companies()->attach($company->id);
            $linked++;
        }

        return $linked;
    }

    /**
     * @param  SupportCollection<int, int>  $companyAccountIds
     * @param  array<string, mixed>  $result
     */
    private function provisionJournals(Company $company, Company $template, SupportCollection $companyAccountIds, array &$result): void
    {
        $templateJournals = Journal::query()
            ->where('company_id', $template->id)
            ->whereNotNull('code')
            ->orderBy('id')
            ->get();

        foreach ($templateJournals as $templateJournal) {
            $journal = Journal::query()
                ->where('company_id', $company->id)
                ->where('code', $templateJournal->code)
                ->first();

            if ($journal && $journal->type !== $templateJournal->type) {
                $result['warnings'][] = "Journal {$templateJournal->code} of {$company->name} is a {$journal->type->value} journal, not {$templateJournal->type->value}; its accounts were left unchanged.";

                continue;
            }

            $accounts = [];

            foreach (self::JournalAccountFields as $field) {
                $accountId = $templateJournal->{$field};

                if (! $accountId || ($journal && $journal->{$field})) {
                    continue;
                }

                if (! $companyAccountIds->has($accountId)) {
                    $result['warnings'][] = "Journal {$templateJournal->code}: {$template->name}'s {$field} belongs to {$template->name} only and was not copied.";

                    continue;
                }

                $accounts[$field] = $accountId;
            }

            if (! $journal) {
                $journal = Journal::query()->create([
                    'company_id'               => $company->id,
                    'currency_id'              => $company->currency_id,
                    'code'                     => $templateJournal->code,
                    'name'                     => $templateJournal->name,
                    'type'                     => $templateJournal->type,
                    'color'                    => $templateJournal->color,
                    'invoice_reference_type'   => $templateJournal->invoice_reference_type,
                    'invoice_reference_model'  => $templateJournal->invoice_reference_model,
                    'bank_statements_source'   => $templateJournal->bank_statements_source,
                    'order_override_regex'     => $templateJournal->order_override_regex,
                    'auto_check_on_post'       => $templateJournal->auto_check_on_post,
                    'restrict_mode_hash_table' => $templateJournal->restrict_mode_hash_table,
                    'refund_order'             => $templateJournal->refund_order,
                    'payment_order'            => $templateJournal->payment_order,
                    'show_on_dashboard'        => $templateJournal->show_on_dashboard,
                    ...$accounts,
                ]);
                $result['journals_created']++;
            } elseif ($accounts !== []) {
                $journal->update($accounts);
                $result['journals_updated']++;
            }

            $result['payment_method_lines_created'] += $this->copyPaymentMethodLines($templateJournal, $journal, $companyAccountIds);
        }
    }

    /**
     * @param  SupportCollection<int, int>  $companyAccountIds
     */
    private function copyPaymentMethodLines(Journal $templateJournal, Journal $journal, SupportCollection $companyAccountIds): int
    {
        $existingMethodIds = PaymentMethodLine::query()
            ->where('journal_id', $journal->id)
            ->pluck('payment_method_id')
            ->flip();

        $created = 0;

        $templateLines = PaymentMethodLine::query()
            ->where('journal_id', $templateJournal->id)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        foreach ($templateLines as $templateLine) {
            if ($existingMethodIds->has($templateLine->payment_method_id)) {
                continue;
            }

            PaymentMethodLine::query()->create([
                'journal_id'         => $journal->id,
                'payment_method_id'  => $templateLine->payment_method_id,
                'name'               => $templateLine->name,
                'payment_account_id' => $templateLine->payment_account_id && $companyAccountIds->has($templateLine->payment_account_id)
                    ? $templateLine->payment_account_id
                    : null,
            ]);
            $existingMethodIds->put($templateLine->payment_method_id, true);
            $created++;
        }

        return $created;
    }

    /**
     * @param  SupportCollection<int, int>  $companyAccountIds
     * @param  array<string, mixed>  $result
     */
    private function provisionFxAccounts(Company $company, Company $template, SupportCollection $companyAccountIds, array &$result): void
    {
        $defaults = $this->exchangeDifferenceDefaults();

        $wanted = [
            'fx_gain_account_id' => $template->fx_gain_account_id ?: $defaults['income'],
            'fx_loss_account_id' => $template->fx_loss_account_id ?: $defaults['expense'],
        ];

        $updates = [];

        foreach ($wanted as $field => $accountId) {
            if ($company->{$field} || ! $accountId) {
                continue;
            }

            $usable = $companyAccountIds->has($accountId) && Account::query()
                ->postable()
                ->whereKey($accountId)
                ->where('deprecated', false)
                ->exists();

            if (! $usable) {
                $result['warnings'][] = "The {$field} account #{$accountId} is not an active postable account of {$company->name}; set it under Company Currency Settings.";

                continue;
            }

            $updates[$field] = $accountId;
        }

        if ($updates !== []) {
            $company->update($updates);
            $result['fx_accounts_set'] += count($updates);
        }
    }

    /**
     * @return array{income: int|null, expense: int|null}
     */
    private function exchangeDifferenceDefaults(): array
    {
        try {
            $settings = new DefaultAccountSettings;

            return [
                'income'  => $settings->income_currency_exchange_account_id,
                'expense' => $settings->expense_currency_exchange_account_id,
            ];
        } catch (Throwable) {
            return ['income' => null, 'expense' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function copyPaymentTerms(Company $company, Company $template, array &$result): void
    {
        $templateTerms = PaymentTerm::query()
            ->where('company_id', $template->id)
            ->with('dueTerms')
            ->orderBy('id')
            ->get();

        foreach ($templateTerms as $templateTerm) {
            // A term the company deleted on purpose is not brought back.
            $exists = PaymentTerm::query()
                ->withTrashed()
                ->where('company_id', $company->id)
                ->where('name', $templateTerm->name)
                ->exists();

            if ($exists) {
                continue;
            }

            $term = PaymentTerm::query()->create([
                'company_id'          => $company->id,
                'name'                => $templateTerm->name,
                'note'                => $templateTerm->note,
                'discount_days'       => $templateTerm->discount_days,
                'early_pay_discount'  => $templateTerm->early_pay_discount,
                'display_on_invoice'  => $templateTerm->display_on_invoice,
                'early_discount'      => $templateTerm->early_discount,
                'discount_percentage' => $templateTerm->discount_percentage,
            ]);

            // PaymentTerm::created() adds a default 100% line; replace it with the template's lines.
            $term->dueTerms()->delete();

            foreach ($templateTerm->dueTerms as $dueTerm) {
                $term->dueTerms()->create([
                    'value'           => $dueTerm->value,
                    'value_amount'    => $dueTerm->value_amount,
                    'delay_type'      => $dueTerm->delay_type,
                    'days_next_month' => $dueTerm->days_next_month,
                    'nb_days'         => $dueTerm->nb_days,
                ]);
            }

            $result['payment_terms_created']++;
        }
    }

    /**
     * @param  SupportCollection<int, int>  $companyAccountIds
     * @param  array<string, mixed>  $result
     */
    private function copyFiscalPositions(Company $company, Company $template, SupportCollection $companyAccountIds, array &$result): void
    {
        $templatePositions = FiscalPosition::query()
            ->where('company_id', $template->id)
            ->with(['taxes.taxSource', 'taxes.taxDestination', 'accounts'])
            ->orderBy('id')
            ->get();

        if ($templatePositions->isEmpty()) {
            return;
        }

        $companyTaxesByName = Tax::query()->where('company_id', $company->id)->get()->keyBy('name');

        foreach ($templatePositions as $templatePosition) {
            $exists = FiscalPosition::query()
                ->where('company_id', $company->id)
                ->where('name', $templatePosition->name)
                ->exists();

            if ($exists) {
                continue;
            }

            $position = FiscalPosition::query()->create([
                'company_id'       => $company->id,
                'name'             => $templatePosition->name,
                'country_id'       => $templatePosition->country_id,
                'country_group_id' => $templatePosition->country_group_id,
                'zip_from'         => $templatePosition->zip_from,
                'zip_to'           => $templatePosition->zip_to,
                'foreign_vat'      => $templatePosition->foreign_vat,
                'notes'            => $templatePosition->notes,
                'auto_reply'       => $templatePosition->auto_reply,
                'vat_required'     => $templatePosition->vat_required,
            ]);

            foreach ($templatePosition->taxes as $taxMapping) {
                $source = $companyTaxesByName->get($taxMapping->taxSource?->name);
                $destination = $taxMapping->tax_destination_id ? $companyTaxesByName->get($taxMapping->taxDestination?->name) : null;

                if (! $source || ($taxMapping->tax_destination_id && ! $destination)) {
                    $missingTax = $source ? $taxMapping->taxDestination?->name : $taxMapping->taxSource?->name;
                    $result['warnings'][] = "Fiscal position {$templatePosition->name}: {$company->name} has no tax named \"{$missingTax}\"; that tax mapping was not copied.";

                    continue;
                }

                FiscalPositionTax::query()->create([
                    'fiscal_position_id' => $position->id,
                    'company_id'         => $company->id,
                    'tax_source_id'      => $source->id,
                    'tax_destination_id' => $destination?->id,
                ]);
            }

            foreach ($templatePosition->accounts as $accountMapping) {
                if (! $companyAccountIds->has($accountMapping->account_source_id)
                    || ($accountMapping->account_destination_id && ! $companyAccountIds->has($accountMapping->account_destination_id))) {
                    $result['warnings'][] = "Fiscal position {$templatePosition->name}: an account mapping uses {$template->name}'s own account and was not copied.";

                    continue;
                }

                FiscalPositionAccount::query()->create([
                    'fiscal_position_id'     => $position->id,
                    'company_id'             => $company->id,
                    'account_source_id'      => $accountMapping->account_source_id,
                    'account_destination_id' => $accountMapping->account_destination_id,
                ]);
            }

            $result['fiscal_positions_created']++;
        }
    }

    /**
     * @param  SupportCollection<int, int>  $companyAccountIds
     * @param  array<string, mixed>  $result
     */
    private function copyFsTags(Company $company, Company $template, SupportCollection $companyAccountIds, array &$result): void
    {
        $templateTags = FsTag::query()
            ->where('company_id', $template->id)
            ->orderBy('id')
            ->get();

        foreach ($templateTags as $templateTag) {
            if ($this->fsTags->resolve((int) $company->id, $templateTag->code, activeOnly: false)) {
                continue;
            }

            if (FsTag::query()->where('company_id', $company->id)->where('normalized_name', $templateTag->normalized_name)->exists()) {
                $result['warnings'][] = "FS tag {$templateTag->code}: {$company->name} already has a tag named \"{$templateTag->name}\" under another code; not copied.";

                continue;
            }

            $accountId = $templateTag->account_id && $companyAccountIds->has($templateTag->account_id)
                ? $templateTag->account_id
                : null;

            if ($templateTag->account_id && ! $accountId) {
                $result['warnings'][] = "FS tag {$templateTag->code}: its GL belongs to {$template->name} only; the copy has no GL until one is mapped.";
            }

            $this->fsTags->create($company, [
                'code'               => $templateTag->code,
                'name'               => $templateTag->name,
                'account_id'         => $accountId,
                'cash_flow_category' => $templateTag->cash_flow_category,
                'tax_treatment'      => $templateTag->tax_treatment,
                'is_active'          => $templateTag->is_active,
            ]);

            $result['fs_tags_created']++;
        }
    }
}
