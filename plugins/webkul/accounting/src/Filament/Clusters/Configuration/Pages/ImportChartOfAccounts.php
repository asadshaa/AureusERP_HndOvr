<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Data\Coa\CoaWarning;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Services\Coa\CoaHeaderDetector;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaSheetParser;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Accounting\Services\Coa\CoaUploadException;
use Webkul\Accounting\Services\Coa\CoaUploadPathResolver;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * Upload a Chart-of-Accounts workbook (CSV/XLSX), choose company/currency and
 * import mode, dry-run to preview the hierarchy/balances/warnings, then confirm
 * a transactional import. Optionally posts balanced Opening/Movement/Adjustment
 * migration journals.
 */
class ImportChartOfAccounts extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.configuration.pages.import-chart-of-accounts';

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 4;

    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $previewData = null;

    /**
     * Absolute path of the importer-owned working copy of the uploaded file.
     * Persisted across the Preview → Confirm Livewire requests so both parse the
     * exact same bytes even if Livewire garbage-collects its temporary upload in
     * between. Reset whenever a new file is chosen.
     */
    public ?string $workingFilePath = null;

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_import_chart_of_accounts';
    }

    public static function getNavigationLabel(): string
    {
        return 'Import Chart of Accounts';
    }

    public function getTitle(): string
    {
        return 'Import Chart of Accounts';
    }

    public function mount(): void
    {
        $this->form->fill([
            'company_id'      => Auth::user()?->default_company_id,
            'mode'            => 'structure_only',
            'opening_date'    => now()->startOfMonth()->subDay()->toDateString(),
            'movement_date'   => now()->toDateString(),
            'adjustment_date' => now()->endOfMonth()->toDateString(),
        ]);
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make('Source file')
                ->schema([
                    FileUpload::make('file')
                        ->label('Chart of Accounts (CSV or XLSX)')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->disk('local')
                        ->directory('coa-imports')
                        ->storeFileNamesIn('file_original_name')
                        ->live()
                        ->afterStateUpdated(fn () => $this->resetUpload())
                        ->required(),
                ]),
            Section::make('Target')
                ->columns(2)
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(fn () => Company::query()->pluck('name', 'id'))
                        ->default(Auth::user()?->default_company_id)
                        ->required()
                        ->disabled()
                        ->dehydrated(),
                    Select::make('currency_id')
                        ->label('Currency')
                        ->options(fn () => Currency::query()->pluck('name', 'id'))
                        ->placeholder('Company default'),
                    Select::make('mode')
                        ->label('Import mode')
                        ->options([
                            'structure_only' => 'Structure only (accounts + hierarchy)',
                            'with_journals'  => 'Structure + migration journals',
                        ])
                        ->default('structure_only')
                        ->live()
                        ->required(),
                    DatePicker::make('opening_date')
                        ->label('Opening date')
                        ->helperText('Must be before the movement date so Trial Balance opening uses entries strictly before the report period.')
                        ->visible(fn (Get $get) => $get('mode') === 'with_journals'),
                    DatePicker::make('movement_date')
                        ->label('Movement date')
                        ->visible(fn (Get $get) => $get('mode') === 'with_journals'),
                    DatePicker::make('adjustment_date')
                        ->label('Adjustment date')
                        ->visible(fn (Get $get) => $get('mode') === 'with_journals'),
                ]),
        ];
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    protected function resolveRows(): array
    {
        return $this->resolveSheetData()['rows'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveSheetData(): array
    {
        $absolute = $this->workingFilePath();

        $reader = new CoaSheetReader;
        $source = $reader->readWithSource($absolute);
        $map = (new CoaHeaderDetector)->detect($source['rows']);

        return [
            'rows'              => (new CoaSheetParser)->parse($source['rows'], $map),
            'source_sheet'      => $source['sheet_name'],
            'file_hash'         => hash_file('sha256', $absolute),
            'header_row_number' => $map->headerRowIndex + 1,
            'source_headers'    => array_values($source['rows'][$map->headerRowIndex] ?? []),
            'metadata_rows'     => array_values(array_slice($source['rows'], 0, $map->headerRowIndex)),
        ];
    }

    /**
     * Absolute path to a stable, importer-owned copy of the uploaded file.
     * Resolves the upload state once (handling Livewire/Symfony upload objects
     * and absolute or relative paths) and persists a working copy so Preview and
     * Confirm Import parse identical bytes. Reused if a valid copy already
     * exists for this upload.
     */
    protected function workingFilePath(): string
    {
        $resolver = app(CoaUploadPathResolver::class);

        if ($resolver->isUsableWorkingCopy($this->workingFilePath)) {
            return $this->workingFilePath;
        }

        return $this->workingFilePath = $resolver->resolveWorkingCopy(
            $this->data['file'] ?? null,
            $this->data['file_original_name'] ?? null,
        );
    }

    /** Discard any preview + working copy when the uploaded file changes. */
    protected function resetUpload(): void
    {
        app(CoaUploadPathResolver::class)->cleanup($this->workingFilePath);
        $this->workingFilePath = null;
        $this->previewData = null;
    }

    public function preview(): void
    {
        try {
            $rows = $this->resolveRows();
            $result = app(CoaImportService::class)->preview($rows);

            // Store ONLY Livewire-serializable primitives/arrays in the public
            // property. The raw preview also carries CoaWarning and CoaRow objects
            // (in 'warnings' and 'plan'), which Livewire cannot dehydrate into a
            // public property — the blade only needs these scalars plus the
            // warning list as plain arrays.
            $this->previewData = [
                'groups'      => $result['groups'],
                'leaves'      => $result['leaves'],
                'has_errors'  => $result['has_errors'],
                'provisional' => $result['provisional'],
                'warnings'    => array_map(fn (CoaWarning $w) => $w->toArray(), $result['warnings']),
            ];

            Notification::make()
                ->title("Preview: {$this->previewData['leaves']} accounts, {$this->previewData['groups']} groups, "
                    .count($this->previewData['warnings']).' warning(s).')
                ->{$this->previewData['has_errors'] ? 'danger' : 'success'}()
                ->send();
        } catch (CoaUploadException $e) {
            $this->previewData = null;
            Notification::make()->warning()->title('Check the uploaded file')->body($e->getMessage())->send();
        } catch (\Throwable $e) {
            $this->previewData = null;
            Notification::make()->danger()->title('Preview failed')->body($e->getMessage())->send();
        }
    }

    public function import(): void
    {
        try {
            $source = $this->resolveSheetData();
            $company = Company::findOrFail($this->data['company_id']);

            $batch = app(CoaImportService::class)->import(
                rows: $source['rows'],
                company: $company,
                mode: $this->data['mode'] ?? 'structure_only',
                currencyId: $this->data['currency_id'] ? (int) $this->data['currency_id'] : null,
                filename: $this->data['file_original_name'] ?? null,
                openingDate: $this->data['opening_date'] ?? null,
                movementDate: $this->data['movement_date'] ?? null,
                adjustmentDate: $this->data['adjustment_date'] ?? null,
                sourceSheet: $source['source_sheet'],
                fileHash: $source['file_hash'],
                headerRowNumber: $source['header_row_number'],
                sourceHeaders: $source['source_headers'],
                metadataRows: $source['metadata_rows'],
            );

            Notification::make()
                ->success()
                ->title('Import complete')
                ->body("Created {$batch->accounts_created} accounts, {$batch->groups_created} groups, {$batch->journals_created} journals (updated {$batch->accounts_updated}).")
                ->persistent()
                ->send();

            // Import succeeded — drop the working copy and the stale preview.
            app(CoaUploadPathResolver::class)->cleanup($this->workingFilePath);
            $this->workingFilePath = null;
            $this->previewData = null;
        } catch (CoaUploadException $e) {
            Notification::make()->warning()->title('Check the uploaded file')->body($e->getMessage())->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Import failed — rolled back')->body($e->getMessage())->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview / Dry-run')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->action('preview'),
            Action::make('import')
                ->label('Import')
                ->icon('heroicon-o-check')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This imports the accounts (and, in journal mode, posts migration journals) transactionally. Re-importing the same file creates no duplicates.')
                ->action('import'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function previewWarnings(): array
    {
        return $this->previewData['warnings'] ?? [];
    }
}
