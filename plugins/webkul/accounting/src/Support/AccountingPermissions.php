<?php

namespace Webkul\Accounting\Support;

final class AccountingPermissions
{
    public const ImportBankStatementPage = 'page_accounting_import_bank_statement';

    public const BankStatements = 'accounting_view_bank_statements';

    public const BankTransactions = 'accounting_view_bank_transactions';

    public const ReviewBankTransactions = 'accounting_review_bank_transactions';

    public const ManageBankMappingRules = 'accounting_manage_bank_mapping_rules';

    public const ManageManualAdjustments = 'accounting_manage_manual_adjustments';

    public const ManageImportProfiles = 'accounting_manage_import_profiles';

    public const RunConfiguredImports = 'accounting_run_configured_imports';

    public const ManageFsTags = 'accounting_manage_fs_tags';

    public const ManageBusinessRules = 'accounting_manage_business_rules';

    public const ManagePartyClassifications = 'accounting_manage_party_classifications';

    public const ManageCurrencies = 'accounting_manage_currencies';

    public const ManageCompanyCurrencies = 'accounting_manage_company_currencies';

    public const ManageExchangeRates = 'accounting_manage_exchange_rates';

    public const ApproveExchangeRates = 'accounting_approve_exchange_rates';

    public const ViewMissingRates = 'accounting_view_missing_rates';

    public const CreateBankAccount = 'accounting_create_bank_gl';

    public const CreateBankJournal = 'accounting_create_bank_journal';

    public const CreateOffsetAccount = 'accounting_create_offset_gl';

    public const GenerateJournal = 'accounting_generate_journal';

    public const ApproveJournal = 'accounting_approve_journal';

    public const PostJournal = 'accounting_post_journal';

    public const RunFxRevaluation = 'accounting_run_fx_revaluation';

    public const ViewMultiCurrencyReports = 'accounting_view_multi_currency_reports';

    public const ViewAccountingChecks = 'page_accounting_accounting_checks';

    public const CompanyCurrencySettingsPage = 'page_accounting_company_currency_settings';

    public const ExchangeRatesPage = 'page_accounting_exchange_rates';

    public const MissingRatesPage = 'page_accounting_missing_exchange_rates';

    public const FxRevaluationPage = 'page_accounting_fx_revaluation';

    public const ViewDocuments = 'accounting_view_documents';

    public const ManageDocuments = 'accounting_manage_documents';

    public const DownloadDocuments = 'accounting_download_documents';

    public const DeleteDocuments = 'accounting_delete_documents';

    /**
     * Sending a document to another user's device is a distinct act from
     * downloading it yourself: it moves a copy to a machine the sender
     * does not control. Read-only oversight roles (Internal Auditor, VP
     * Finance, FP&A) deliberately do NOT get this -- their permission
     * bundles are explicit allowlists, so they are excluded by omission.
     */
    public const TransferDocuments = 'accounting_transfer_documents';

    /**
     * Distinguishes "a payment was prepared/recorded" (the generic
     * create/update Shield permission on the Payment resource) from "a
     * payment was authorized to actually leave the bank" -- the codebase
     * had no such distinction before the finance-roles work: Treasury
     * Officer prepares, but only Finance Manager/Controller/VP
     * Finance/CFO may release, per the segregation-of-duties chain in the
     * finance role spec (AP -> reviewer -> Treasury -> VP Finance release
     * -> reconciliation).
     */
    public const ReleasePayment = 'accounting_release_payment';

    /**
     * ManualAdjustmentResource::canViewAny() previously accepted ONLY
     * ManageManualAdjustments -- there was no way to grant a role
     * read-only visibility into manual adjustments without also handing
     * it Edit/Submit-for-approval access. Found live, during manual
     * testing, when Internal Auditor got a 403 despite holding the
     * generic Shield `view_any_accounting_manual_adjustment` permission
     * (which this resource's canViewAny() ignores entirely).
     */
    public const ViewManualAdjustments = 'accounting_view_manual_adjustments';

    /**
     * Same gap, same fix, for ExchangeRateResource::canViewAny() (which
     * previously accepted only ManageExchangeRates).
     */
    public const ViewExchangeRates = 'accounting_view_exchange_rates_list';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ImportBankStatementPage,
            self::BankStatements,
            self::BankTransactions,
            self::ReviewBankTransactions,
            self::ManageBankMappingRules,
            self::ManageManualAdjustments,
            self::ManageImportProfiles,
            self::RunConfiguredImports,
            self::ManageFsTags,
            self::ManageBusinessRules,
            self::ManagePartyClassifications,
            self::ManageCurrencies,
            self::ManageCompanyCurrencies,
            self::ManageExchangeRates,
            self::ApproveExchangeRates,
            self::ViewMissingRates,
            self::CreateBankAccount,
            self::CreateBankJournal,
            self::CreateOffsetAccount,
            self::GenerateJournal,
            self::ApproveJournal,
            self::PostJournal,
            self::RunFxRevaluation,
            self::ViewMultiCurrencyReports,
            self::ViewAccountingChecks,
            self::CompanyCurrencySettingsPage,
            self::ExchangeRatesPage,
            self::MissingRatesPage,
            self::FxRevaluationPage,
            self::ViewDocuments,
            self::ManageDocuments,
            self::DownloadDocuments,
            self::DeleteDocuments,
            self::TransferDocuments,
            self::ReleasePayment,
            self::ViewManualAdjustments,
            self::ViewExchangeRates,
            'page_accounting_overview',
            'page_accounting_manage_taxes',
            'page_accounting_manage_products',
            'page_accounting_manage_default_accounts',
            'page_accounting_manage_customer_invoice',
            'page_accounting_import_chart_of_accounts',
            'page_accounting_imported_chart_of_accounts',
            'page_accounting_aged_payable',
            'page_accounting_aged_receivable',
            'page_accounting_direct_cash_flow',
            'page_accounting_trial_balance',
            'page_accounting_balance_sheet',
            'page_accounting_profit_loss',
            'page_accounting_general_ledger',
            'page_accounting_financial_reports',
            'page_accounting_external_providers',
            'page_accounting_partner_ledger',
            'page_accounting_partner_analytics',
            'page_accounting_report_mapping_review',
            'view_any_accounting_bank_statement',
            'view_accounting_bank_statement',
            'view_any_accounting_bank_transaction_mapping',
            'view_accounting_bank_transaction_mapping',
            'update_accounting_bank_transaction_mapping',
            'view_any_accounting_bank_mapping_rule',
            'view_accounting_bank_mapping_rule',
            'create_accounting_bank_mapping_rule',
            'update_accounting_bank_mapping_rule',
            'delete_accounting_bank_mapping_rule',
            'view_any_accounting_manual_adjustment',
            'view_accounting_manual_adjustment',
            'create_accounting_manual_adjustment',
            'update_accounting_manual_adjustment',
            'view_any_accounting_exchange_rate',
            'view_accounting_exchange_rate',
            'create_accounting_exchange_rate',
            'update_accounting_exchange_rate',
            'view_any_accounting_invoice',
            'view_accounting_invoice',
            'create_accounting_invoice',
            'update_accounting_invoice',
            'delete_accounting_invoice',
            'view_any_accounting_bill',
            'view_accounting_bill',
            'create_accounting_bill',
            'update_accounting_bill',
            'delete_accounting_bill',
            // Added for the finance-roles work: AP/AR/Treasury/Tax/Controller/
            // Auditor all need slices of these, and the registrar can only
            // grant a permission name that's present in this master list (see
            // AccountingPermissionRegistrar::synchronize()) -- these were
            // already Shield-generated on the `permissions` table (the
            // accounting plugin's Vendors/Customers Filament clusters use
            // these resources), just never previously curated here.
            'view_any_accounting_payment',
            'view_accounting_payment',
            'create_accounting_payment',
            'update_accounting_payment',
            'view_any_accounting_vendor',
            'view_accounting_vendor',
            'create_accounting_vendor',
            'update_accounting_vendor',
            'view_any_accounting_customer',
            'view_accounting_customer',
            'create_accounting_customer',
            'update_accounting_customer',
            'view_any_accounting_refund',
            'view_accounting_refund',
            'create_accounting_refund',
            'update_accounting_refund',
            'view_any_accounting_credit::note',
            'view_accounting_credit::note',
            'create_accounting_credit::note',
            'update_accounting_credit::note',
            'view_any_accounting_tax',
            'view_accounting_tax',
            'create_accounting_tax',
            'update_accounting_tax',
            'view_any_accounting_tax::group',
            'view_accounting_tax::group',
            'create_accounting_tax::group',
            'update_accounting_tax::group',
            'view_any_accounting_journal::entry',
            'view_accounting_journal::entry',
            'view_any_accounting_journal::item',
            'view_accounting_journal::item',
            'view_any_support_approval::request',
            'view_support_approval::request',
            'view_any_support_approval::workflow',
            'view_support_approval::workflow',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function accountant(): array
    {
        return array_values(array_diff(self::all(), [
            self::ManageCurrencies,
            self::ManageCompanyCurrencies,
            self::ApproveExchangeRates,
            self::ApproveJournal,
            self::PostJournal,
            self::RunFxRevaluation,
            self::FxRevaluationPage,
            self::DeleteDocuments,
            self::ReleasePayment,
            // The Accountant role's spec is explicitly "Review customer/vendor
            // accounting" / "Review invoices" -- view-only. Creating/updating
            // vendor, customer, payment, refund and credit-note records
            // belongs to AP Officer / AR Officer; tax configuration belongs
            // to Tax Officer. Everything else new in all() is view-only and
            // stays included above (GL/journal-entry view, approval-queue
            // visibility for the accountant's own submissions).
            'create_accounting_vendor',
            'update_accounting_vendor',
            'create_accounting_customer',
            'update_accounting_customer',
            'create_accounting_payment',
            'update_accounting_payment',
            'create_accounting_refund',
            'update_accounting_refund',
            'create_accounting_credit::note',
            'update_accounting_credit::note',
            'view_any_accounting_tax',
            'view_accounting_tax',
            'create_accounting_tax',
            'update_accounting_tax',
            'view_any_accounting_tax::group',
            'view_accounting_tax::group',
            'create_accounting_tax::group',
            'update_accounting_tax::group',
            // Approval-queue visibility (the raw ApprovalRequest/
            // ApprovalWorkflow Filament resources, which are not
            // row-scoped to "requests I submitted") is deliberately kept
            // out of the existing Accountant bundle -- all() gained these
            // names for the new roles below, but Accountant's own grant
            // footprint must stay exactly what it was before this work.
            'view_any_support_approval::request',
            'view_support_approval::request',
            'view_any_support_approval::workflow',
            'view_support_approval::workflow',
        ]));
    }

    /**
     * Finance Operator -- daily finance data-entry and preparation.
     * Can view/create permitted finance records, run configured imports,
     * prepare bank transaction mappings, attach supporting documents and
     * submit for review. Deliberately excludes ManageBankMappingRules
     * (mapping-rule *configuration*, not day-to-day mapping), manual
     * adjustments (Accountant's job), and every approve/post/release
     * permission.
     *
     * @return array<int, string>
     */
    public static function financeOperator(): array
    {
        return [
            self::ImportBankStatementPage,
            self::BankStatements,
            self::BankTransactions,
            self::ManageImportProfiles,
            self::RunConfiguredImports,
            self::ViewDocuments,
            self::ManageDocuments,
            self::DownloadDocuments,
            'page_accounting_overview',
            'view_any_accounting_bank_statement',
            'view_accounting_bank_statement',
            'view_any_accounting_bank_transaction_mapping',
            'view_accounting_bank_transaction_mapping',
            'update_accounting_bank_transaction_mapping',
            'view_any_accounting_bill',
            'view_accounting_bill',
            'create_accounting_bill',
            'view_any_accounting_invoice',
            'view_accounting_invoice',
            'create_accounting_invoice',
            // Deliberately no approval-queue visibility here: the raw
            // ApprovalRequest resource is not scoped to "requests I
            // submitted" (it would show every request company-wide), and
            // the spec calls out approval-request/decision visibility
            // specifically for Internal Auditor, Controller and above --
            // not for the officer tier that merely submits.
        ];
    }

    /**
     * AP Officer -- Accounts Payable. Prepares vendor bills and payment
     * requests; never approves its own work and never releases a payment
     * (see ReleasePayment, granted only to Finance Manager/Controller/VP
     * Finance/CFO).
     *
     * @return array<int, string>
     */
    public static function apOfficer(): array
    {
        return [
            'view_any_accounting_vendor',
            'view_accounting_vendor',
            'create_accounting_vendor',
            'update_accounting_vendor',
            'view_any_accounting_bill',
            'view_accounting_bill',
            'create_accounting_bill',
            'update_accounting_bill',
            'view_any_accounting_payment',
            'view_accounting_payment',
            'create_accounting_payment',
            'update_accounting_payment',
            'page_accounting_aged_payable',
            self::ViewDocuments,
            self::ManageDocuments,
            self::DownloadDocuments,
            // Deliberately no approval-queue visibility here: the raw
            // ApprovalRequest resource is not scoped to "requests I
            // submitted" (it would show every request company-wide), and
            // the spec calls out approval-request/decision visibility
            // specifically for Internal Auditor, Controller and above --
            // not for the officer tier that merely submits.
        ];
    }

    /**
     * AR Officer -- Accounts Receivable. Mirrors AP Officer for the
     * customer/invoice/credit-note side; never approves its own work.
     *
     * @return array<int, string>
     */
    public static function arOfficer(): array
    {
        return [
            'view_any_accounting_customer',
            'view_accounting_customer',
            'create_accounting_customer',
            'update_accounting_customer',
            'view_any_accounting_invoice',
            'view_accounting_invoice',
            'create_accounting_invoice',
            'update_accounting_invoice',
            'view_any_accounting_credit::note',
            'view_accounting_credit::note',
            'create_accounting_credit::note',
            'update_accounting_credit::note',
            // Matching an incoming customer payment to an invoice updates
            // the Payment record but never creates a new outgoing payment
            // (that's AP's domain) -- view + update only, no create.
            'view_any_accounting_payment',
            'view_accounting_payment',
            'update_accounting_payment',
            'page_accounting_aged_receivable',
            self::ViewDocuments,
            self::ManageDocuments,
            self::DownloadDocuments,
            // Deliberately no approval-queue visibility here: the raw
            // ApprovalRequest resource is not scoped to "requests I
            // submitted" (it would show every request company-wide), and
            // the spec calls out approval-request/decision visibility
            // specifically for Internal Auditor, Controller and above --
            // not for the officer tier that merely submits.
        ];
    }

    /**
     * Treasury Officer -- cash and banking operations. Prepares bank
     * transaction mappings and payment batches but explicitly does NOT get
     * ReviewBankTransactions (that's Reconciliation Officer's independent
     * check) or PostJournal/ReleasePayment.
     *
     * @return array<int, string>
     */
    public static function treasuryOfficer(): array
    {
        return [
            self::ImportBankStatementPage,
            self::BankStatements,
            self::BankTransactions,
            'view_any_accounting_bank_statement',
            'view_accounting_bank_statement',
            'view_any_accounting_bank_transaction_mapping',
            'view_accounting_bank_transaction_mapping',
            'update_accounting_bank_transaction_mapping',
            'view_any_accounting_payment',
            'view_accounting_payment',
            'create_accounting_payment',
            'update_accounting_payment',
            'page_accounting_direct_cash_flow',
            self::ViewDocuments,
            self::ManageDocuments,
            self::DownloadDocuments,
            // Deliberately no approval-queue visibility here: the raw
            // ApprovalRequest resource is not scoped to "requests I
            // submitted" (it would show every request company-wide), and
            // the spec calls out approval-request/decision visibility
            // specifically for Internal Auditor, Controller and above --
            // not for the officer tier that merely submits.
        ];
    }

    /**
     * Reconciliation Officer -- independent reconciliation and exception
     * handling, deliberately separate from Treasury Officer (who prepares
     * the mapping) and from Controller (who posts it): gets
     * ReviewBankTransactions (the existing "reconcile/approve mapping"
     * permission) but not PostJournal.
     *
     * @return array<int, string>
     */
    public static function reconciliationOfficer(): array
    {
        return [
            self::BankStatements,
            self::BankTransactions,
            self::ReviewBankTransactions,
            'view_any_accounting_bank_statement',
            'view_accounting_bank_statement',
            'view_any_accounting_bank_transaction_mapping',
            'view_accounting_bank_transaction_mapping',
            'view_any_accounting_journal::entry',
            'view_accounting_journal::entry',
            'view_any_accounting_journal::item',
            'view_accounting_journal::item',
            self::ViewDocuments,
            self::DownloadDocuments,
            // Deliberately no approval-queue visibility here: the raw
            // ApprovalRequest resource is not scoped to "requests I
            // submitted" (it would show every request company-wide), and
            // the spec calls out approval-request/decision visibility
            // specifically for Internal Auditor, Controller and above --
            // not for the officer tier that merely submits.
        ];
    }

    /**
     * Tax Officer -- tax and compliance accounting, scoped to what
     * actually exists in Aureus today (tax code/rate/group configuration
     * and manual adjustments tagged for tax purposes). There is no
     * dedicated tax-filing/return workflow in the codebase to grant
     * permissions for.
     *
     * @return array<int, string>
     */
    public static function taxOfficer(): array
    {
        return [
            'page_accounting_manage_taxes',
            'view_any_accounting_tax',
            'view_accounting_tax',
            'create_accounting_tax',
            'update_accounting_tax',
            'view_any_accounting_tax::group',
            'view_accounting_tax::group',
            'create_accounting_tax::group',
            'update_accounting_tax::group',
            // ManualAdjustmentResource gates view AND create on this one
            // permission -- the plain Shield view_any_/create_ strings
            // below are inert against that resource (see
            // ManualAdjustmentResource::canViewAny()); kept for
            // completeness/API consistency but ManageManualAdjustments is
            // what actually lets Tax Officer prepare tax adjustments.
            self::ManageManualAdjustments,
            'view_any_accounting_manual_adjustment',
            'view_accounting_manual_adjustment',
            'create_accounting_manual_adjustment',
            'update_accounting_manual_adjustment',
            self::ViewMultiCurrencyReports,
            self::ViewDocuments,
            self::ManageDocuments,
            self::DownloadDocuments,
            // Deliberately no approval-queue visibility here: the raw
            // ApprovalRequest resource is not scoped to "requests I
            // submitted" (it would show every request company-wide), and
            // the spec calls out approval-request/decision visibility
            // specifically for Internal Auditor, Controller and above --
            // not for the officer tier that merely submits.
        ];
    }

    /**
     * Controller -- accounting integrity, financial controls and close.
     * The "authorized poster" tier from the existing Journal workflow
     * (Accountant -> Controller/Finance Manager -> Approval -> Authorized
     * Poster -> Posted GL): full accounting read plus approve/post, but no
     * system/user/permission administration and no unrestricted payment
     * release beyond the same ReleasePayment tier Finance Manager/VP
     * Finance/CFO also get.
     *
     * @return array<int, string>
     */
    public static function controller(): array
    {
        return array_values(array_unique(array_merge(self::accountant(), [
            self::ApproveJournal,
            self::PostJournal,
            self::ApproveExchangeRates,
            self::RunFxRevaluation,
            self::FxRevaluationPage,
            self::ReviewBankTransactions,
            self::ReleasePayment,
            'view_any_accounting_tax',
            'view_accounting_tax',
            'view_any_accounting_tax::group',
            'view_accounting_tax::group',
            'view_any_support_approval::request',
            'view_support_approval::request',
            'view_any_support_approval::workflow',
            'view_support_approval::workflow',
        ])));
    }

    /**
     * FP&A Analyst -- analytical role. Read-only across every report the
     * codebase currently has; NO posting/approval/release permission of
     * any kind, per the spec's explicit restriction. There is no
     * Budget/forecast model in Aureus to grant permissions for.
     *
     * @return array<int, string>
     */
    public static function fpaAnalyst(): array
    {
        return [
            self::ViewMultiCurrencyReports,
            'page_accounting_overview',
            'page_accounting_trial_balance',
            'page_accounting_balance_sheet',
            'page_accounting_profit_loss',
            'page_accounting_general_ledger',
            'page_accounting_financial_reports',
            'page_accounting_partner_ledger',
            'page_accounting_partner_analytics',
            'page_accounting_aged_payable',
            'page_accounting_aged_receivable',
            'page_accounting_direct_cash_flow',
        ];
    }

    /**
     * VP Finance -- senior oversight, not a "super accountant". Broad
     * read + exception/queue visibility only; zero create/edit/post
     * permissions of its own. Approval authority comes entirely from being
     * assignable as an ApprovalStep.approver_role_id, never from a
     * permission string (see ApprovalEngine::canAct()).
     *
     * @return array<int, string>
     */
    public static function vpFinance(): array
    {
        return array_values(array_unique(array_merge(self::fpaAnalyst(), [
            self::ReleasePayment,
            self::ViewManualAdjustments,
            self::ViewExchangeRates,
            self::BankStatements,
            self::BankTransactions,
            'view_any_accounting_bank_statement',
            'view_accounting_bank_statement',
            'view_any_accounting_bank_transaction_mapping',
            'view_accounting_bank_transaction_mapping',
            'view_any_accounting_manual_adjustment',
            'view_accounting_manual_adjustment',
            'view_any_accounting_exchange_rate',
            'view_accounting_exchange_rate',
            'view_any_accounting_journal::entry',
            'view_accounting_journal::entry',
            'view_any_accounting_bill',
            'view_accounting_bill',
            'view_any_accounting_invoice',
            'view_accounting_invoice',
            'view_any_accounting_payment',
            'view_accounting_payment',
            self::ViewDocuments,
            self::DownloadDocuments,
            'view_any_support_approval::request',
            'view_support_approval::request',
        ])));
    }

    /**
     * CFO -- executive leadership, one tier above VP Finance. Same
     * read-only + ReleasePayment shape; per spec it's fine for this role
     * to exist but stay unassigned if no workflow currently needs a
     * CFO-level approval step.
     *
     * @return array<int, string>
     */
    public static function cfo(): array
    {
        return self::vpFinance();
    }

    /**
     * Internal Auditor -- independent audit and control review.
     * READ-HEAVY / WRITE-LIGHT: every view permission relevant to an
     * audit trail, and explicitly nothing else -- no create, update,
     * approve, post, release or delete permission anywhere.
     *
     * @return array<int, string>
     */
    public static function internalAuditor(): array
    {
        return [
            'page_accounting_overview',
            'page_accounting_trial_balance',
            'page_accounting_balance_sheet',
            'page_accounting_profit_loss',
            'page_accounting_general_ledger',
            'page_accounting_financial_reports',
            'page_accounting_partner_ledger',
            'page_accounting_partner_analytics',
            'page_accounting_aged_payable',
            'page_accounting_aged_receivable',
            'page_accounting_direct_cash_flow',
            self::ViewMultiCurrencyReports,
            self::ViewManualAdjustments,
            self::ViewExchangeRates,
            self::BankStatements,
            self::BankTransactions,
            'view_any_accounting_bank_statement',
            'view_accounting_bank_statement',
            'view_any_accounting_bank_transaction_mapping',
            'view_accounting_bank_transaction_mapping',
            'view_any_accounting_manual_adjustment',
            'view_accounting_manual_adjustment',
            'view_any_accounting_exchange_rate',
            'view_accounting_exchange_rate',
            'view_any_accounting_journal::entry',
            'view_accounting_journal::entry',
            'view_any_accounting_journal::item',
            'view_accounting_journal::item',
            'view_any_accounting_bill',
            'view_accounting_bill',
            'view_any_accounting_invoice',
            'view_accounting_invoice',
            'view_any_accounting_payment',
            'view_accounting_payment',
            'view_any_accounting_vendor',
            'view_accounting_vendor',
            'view_any_accounting_customer',
            'view_accounting_customer',
            'view_any_accounting_refund',
            'view_accounting_refund',
            'view_any_accounting_credit::note',
            'view_accounting_credit::note',
            self::ViewDocuments,
            self::DownloadDocuments,
            'view_any_support_approval::request',
            'view_support_approval::request',
            'view_any_support_approval::workflow',
            'view_support_approval::workflow',
        ];
    }
}
