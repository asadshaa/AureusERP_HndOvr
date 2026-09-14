<?php

namespace Webkul\Accounting;

use Filament\Panel;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Payment as BaseAccountPayment;
use Webkul\Accounting\Console\Commands\AuthorizeDriveCommand;
use Webkul\Accounting\Console\Commands\CheckDocumentIntegrityCommand;
use Webkul\Accounting\Contracts\DocumentStorageProvider;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Database\Seeders\AccountingPermissionSeeder;
use Webkul\Accounting\Database\Seeders\IsoCurrencySeeder;
use Webkul\Accounting\Database\Seeders\ReportWorkbookSeeder;
use Webkul\Accounting\Events\DocumentContentChanged;
use Webkul\Accounting\Filament\Widgets\JournalChartWidget;
use Webkul\Accounting\Listeners\DispatchDriveSyncOnDocumentChanged;
use Webkul\Accounting\Livewire\InvoiceSummary;
use Webkul\Accounting\Models\Bill;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Accounting\Models\Invoice as AccountingInvoice;
use Webkul\Accounting\Models\JournalEntry;
use Webkul\Accounting\Models\Payment as AccountingPayment;
use Webkul\Accounting\Repositories\LedgerBalanceRepository;
use Webkul\Accounting\Services\Bank\BankStatementParserRegistry;
use Webkul\Accounting\Services\Bank\CommonWorkbookBankStatementParser;
use Webkul\Accounting\Services\Bank\HblBankStatementParser;
use Webkul\Accounting\Services\Bank\MeezanBankStatementParser;
use Webkul\Accounting\Services\Drive\GoogleDriveClient;
use Webkul\Accounting\Services\MeasureResolverRegistry;
use Webkul\Accounting\Services\ReportValueProviderRegistry;
use Webkul\Accounting\Services\Resolvers\LedgerMeasureResolver;
use Webkul\Accounting\Services\Storage\LocalDocumentStorageProvider;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class AccountingServiceProvider extends PackageServiceProvider
{
    public static string $name = 'accounting';

    public static string $viewNamespace = 'accounting';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasViews()
            ->hasTranslations()
            ->hasDependencies([
                'accounts',
            ])
            ->hasMigrations([
                '2025_07_16_000001_create_accounting_report_templates_table',
                '2025_07_16_000002_create_accounting_report_lines_table',
                '2025_07_16_000003_create_accounting_report_line_accounts_table',
                '2025_07_16_000004_create_accounting_report_line_formulas_table',
                '2026_07_16_000001_create_accounting_report_columns_table',
                '2026_07_16_000002_create_accounting_report_line_inputs_table',
                '2026_07_16_000003_add_engine_columns_to_accounting_report_lines_table',
                '2026_07_16_000004_add_purpose_to_accounting_report_line_formulas_table',
                '2026_07_17_000001_add_published_at_to_accounting_report_templates_table',
                '2026_07_20_000001_add_coa_import_fields_to_accounts_accounts_table',
                '2026_07_20_000002_create_accounting_coa_import_batches_table',
                '2026_07_20_000003_add_coa_migration_fields_to_accounts_account_moves_table',
                '2026_07_27_074828_implement_bank_statement_workflow',
                '2026_07_27_082400_allow_multiple_bank_sheets_per_workbook',
                '2026_07_28_000001_implement_multi_currency_accounting',
                '2026_07_28_000002_implement_configurable_import_platform',
                '2026_08_25_000001_add_import_failure_and_duplicate_controls',
                '2026_08_25_000002_add_invoice_import_reference_fields',
                '2026_08_25_000003_add_fs_tags_to_journal_lines',
                '2026_08_25_000004_link_bank_mappings_to_obligations',
                '2026_09_10_000001_create_accounting_documents_table',
                '2026_09_10_000002_create_accounting_document_versions_table',
                '2026_09_10_000003_create_accounting_document_attachments_table',
                '2026_09_10_000004_create_accounting_document_audits_table',
                '2026_09_11_000001_create_accounting_document_drive_syncs_table',
            ])
            ->runsMigrations()
            ->hasSeeders([
                ReportWorkbookSeeder::class,
                IsoCurrencySeeder::class,
                AccountingPermissionSeeder::class,
            ])
            ->hasCommands([
                CheckDocumentIntegrityCommand::class,
                AuthorizeDriveCommand::class,
            ])
            ->icon('accounting')
            ->hasInstallCommand(function (InstallCommand $command) {
                $command->installDependencies();
                $command->runsMigrations();
                // Idempotently seed the six workbook report templates (the
                // seeder skips codes that already exist), so a fresh install
                // always has the Stage 5 reports available.
                $command->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {});
    }

    public function packageBooted(): void
    {
        $this->registerCustomCss();

        $this->registerLivewireComponents();

        $this->registerDocumentAttachmentRelations();

        $this->registerDriveSyncListener();
    }

    /**
     * The single point where accounting_drive.enabled actually gates
     * anything happening: when false, DocumentContentChanged has no
     * listener at all, so firing it (which DocumentService always does,
     * unconditionally) costs nothing beyond the event dispatch itself --
     * no job, no Drive API client construction, nothing.
     */
    private function registerDriveSyncListener(): void
    {
        if (! config('accounting_drive.enabled')) {
            return;
        }

        Event::listen(DocumentContentChanged::class, DispatchDriveSyncOnDocumentChanged::class);
    }

    /**
     * BankStatement lives in the accounts plugin, which accounting depends
     * on -- but the dependency can never run the other way, so accounts
     * itself must never reference Document/DocumentAttachment directly.
     * resolveRelationUsing() lets accounting attach a real relation to a
     * model it doesn't own, entirely from this side, with the base model
     * file untouched.
     */
    private function registerDocumentAttachmentRelations(): void
    {
        BankStatement::resolveRelationUsing(
            'documentAttachments',
            fn ($model) => $model->morphMany(DocumentAttachment::class, 'attachable'),
        );

        // Registered on the base Move too (not just the invoices plugin's
        // own Invoice subclass) so anything built directly on Move --
        // including this suite's own test fixtures -- gets the relation
        // without needing the invoices plugin installed at all.
        // resolveRelationUsing() is keyed by exact class name, not
        // inherited down a hierarchy, so a subclass (Invoice) still needs
        // its own registration too -- see InvoiceServiceProvider.
        Move::resolveRelationUsing(
            'documentAttachments',
            fn ($model) => $model->morphMany(DocumentAttachment::class, 'attachable'),
        );

        // Bill, JournalEntry and this plugin's OWN Invoice subclass are
        // three more thin Move subclasses, but -- unlike the invoices
        // plugin's Invoice -- all three are owned by this same plugin
        // (accounting depends on accounts for Move, and Bill/JournalEntry/
        // Invoice live here alongside DocumentAttachment itself), so their
        // registration doesn't need to live in a separate service provider
        // the way the invoices plugin's does. Same "not inherited by
        // subclasses" caveat as above applies -- each still needs its own
        // line.
        //
        // Confusingly, there are actually THREE separate Invoice model
        // classes in this codebase, each with their own Filament resource:
        // Webkul\Account\Models\Invoice (accounts, base, no UI of its own),
        // Webkul\Invoice\Models\Invoice (invoices plugin, its own
        // top-level "Invoices" nav item at /admin/invoices/..., wired in
        // InvoiceServiceProvider), and this one -- Webkul\Accounting\Models
        // \Invoice, used by THIS plugin's own InvoiceResource under
        // Accounting > Customers > Invoices. All three needed their own
        // resolveRelationUsing() registration; missing this one is exactly
        // what silently broke the "Supporting documents" tab on the
        // Accounting > Customers > Invoices page during manual testing.
        Bill::resolveRelationUsing(
            'documentAttachments',
            fn ($model) => $model->morphMany(DocumentAttachment::class, 'attachable'),
        );

        JournalEntry::resolveRelationUsing(
            'documentAttachments',
            fn ($model) => $model->morphMany(DocumentAttachment::class, 'attachable'),
        );

        AccountingInvoice::resolveRelationUsing(
            'documentAttachments',
            fn ($model) => $model->morphMany(DocumentAttachment::class, 'attachable'),
        );

        // Payment is NOT a Move subclass (unlike Bill/JournalEntry/Invoice)
        // -- it's its own plain Eloquent model, so it never inherited the
        // relation from the Move registration above. Found missing during
        // the Google Drive integration inspection: neither the Customers >
        // Payments nor Vendors > Payments screen had a "Supporting
        // documents" tab at all. Same base+subclass dual registration as
        // Move/AccountingInvoice, for the same reason (resolveRelationUsing()
        // isn't inherited down a hierarchy).
        BaseAccountPayment::resolveRelationUsing(
            'documentAttachments',
            fn ($model) => $model->morphMany(DocumentAttachment::class, 'attachable'),
        );

        AccountingPayment::resolveRelationUsing(
            'documentAttachments',
            fn ($model) => $model->morphMany(DocumentAttachment::class, 'attachable'),
        );
    }

    public function packageRegistered(): void
    {
        // DocumentService only ever knows this interface, never Storage::disk()
        // or an SDK directly -- swapping to an S3-specific provider later
        // (or, further out, Google Drive) means changing this one binding,
        // not any business logic.
        //
        // Resolved via an explicit closure (not a bare class-string bind)
        // so Storage::disk('accounting_documents') is looked up fresh on
        // every resolution. A bare bind would let Laravel's container
        // auto-wire the constructor's Filesystem-typed parameter to
        // whatever the DEFAULT disk is instead of leaving it null for the
        // constructor's own fallback to run -- which silently ignores
        // Storage::fake('accounting_documents') in tests.
        $this->app->bind(DocumentStorageProvider::class, fn () => new LocalDocumentStorageProvider(
            Storage::disk('accounting_documents'),
        ));

        // Same reasoning as DocumentStorageProvider above: DriveSyncService
        // only ever knows this interface, never Google\Client or the Drive
        // service directly. Tests bind a fake implementation instead of
        // this one -- see tests/Helpers/DriveTestHelper.php -- so none of
        // them need real Google credentials.
        $this->app->bind(DriveClient::class, GoogleDriveClient::class);

        $this->app->singleton(ReportValueProviderRegistry::class);

        $this->app->singleton(BankStatementParserRegistry::class, function (): BankStatementParserRegistry {
            $registry = new BankStatementParserRegistry;
            $registry->register(new CommonWorkbookBankStatementParser);
            $registry->register(new HblBankStatementParser);
            $registry->register(new MeezanBankStatementParser);

            return $registry;
        });

        // The generic data-resolution seam (Phase 0). Only the ledger resolver
        // is registered today; imported-dataset, manual and external-API
        // resolvers register here in later phases without engine changes.
        $this->app->singleton(MeasureResolverRegistry::class, function ($app) {
            $registry = new MeasureResolverRegistry;

            $registry->register(new LedgerMeasureResolver($app->make(LedgerBalanceRepository::class)));

            return $registry;
        });

        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(AccountingPlugin::make());
        });
    }

    public function registerLivewireComponents()
    {
        Livewire::component('accounting-journal-chart', JournalChartWidget::class);

        Livewire::component('accounting-invoice-summary', InvoiceSummary::class);
    }

    public function registerCustomCss()
    {
        FilamentAsset::register([
            Css::make('accounting', __DIR__.'/../resources/dist/accounting.css'),
        ], 'accounting');
    }
}
