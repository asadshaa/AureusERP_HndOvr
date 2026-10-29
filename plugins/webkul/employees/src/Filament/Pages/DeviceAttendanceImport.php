<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Enums\DeviceImportRowStatus;
use Webkul\Employee\Enums\PunchPairingMode;
use Webkul\Employee\Models\AttendanceImportBatch;
use Webkul\Employee\Models\AttendanceImportRow;
use Webkul\Employee\Services\Attendance\DeviceAttendanceImportService;
use Webkul\Employee\Services\Attendance\DeviceImportExcel;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * Attendance -> Device import: HR uploads a biometric device export
 * (CSV / Excel), checks the preview (status per row, days per employee),
 * imports it, downloads the row report and can undo an import. Everything
 * is limited to the active company and re-checked server-side by
 * DeviceAttendanceImportService on every call.
 */
class DeviceAttendanceImport extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    /** Rows listed on screen in the preview; the Excel report has all of them. */
    public const PREVIEW_ROW_LIMIT = 200;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'attendance-device-import';

    protected string $view = 'employees::filament.pages.device-attendance-import';

    /** @var array{file?: mixed, file_name?: ?string, date_order?: string} */
    public ?array $data = [];

    public ?int $previewBatchId = null;

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return DeviceAttendanceImportService::canManage($user, CurrentCompany::id($user));
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return 'Device import';
    }

    public function getTitle(): string
    {
        return 'Device attendance import';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill(['date_order' => 'auto']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Upload a device export')
                ->description('A CSV or Excel export of the attendance device (ZKTeco and similar), or the Aureus template. Punches are matched to employees of '.$this->companyName().' by device ID, then employee code. Nothing is written until you press Import on the preview.')
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV or Excel file')
                        ->disk(DeviceAttendanceImportService::DISK)
                        ->directory(DeviceAttendanceImportService::DIRECTORY)
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream',
                        ])
                        ->maxSize(DeviceAttendanceImportService::MAX_SIZE_KB)
                        ->storeFileNamesIn('file_name')
                        ->required(),
                    Select::make('date_order')
                        ->label('Dates written like 01/10/2026 are')
                        ->options([
                            'auto' => 'Detect from the file (day first when unclear)',
                            'dmy'  => 'Day / month / year (01/10/2026 = 1 October)',
                            'mdy'  => 'Month / day / year (10/01/2026 = 1 October)',
                        ])
                        ->default('auto')
                        ->selectablePlaceholder(false)
                        ->required(),
                ])
                ->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    /** Reads the uploaded file into a preview batch (writes no attendance). */
    public function preview(): void
    {
        [$user, $companyId] = $this->hr();
        $state = $this->form->getState();
        $path = (string) ($state['file'] ?? '');

        try {
            $batch = app(DeviceAttendanceImportService::class)->preview($user, $companyId, $path, $state['file_name'] ?? null, (string) ($state['date_order'] ?? 'auto'));
        } catch (InvalidArgumentException $exception) {
            Storage::disk(DeviceAttendanceImportService::DISK)->delete($path);
            Notification::make()->danger()->title('The file could not be read')->body($exception->getMessage())->send();

            return;
        }

        $this->previewBatchId = $batch->id;
        $this->form->fill(['date_order' => $state['date_order'] ?? 'auto']);

        $ok = (int) ($batch->summary['counts'][DeviceImportRowStatus::Ok->value] ?? 0);
        Notification::make()
            ->success()
            ->title('Preview ready')
            ->body("{$ok} of {$batch->total_rows} rows can be imported. Check the rows below, then press Import.")
            ->send();
    }

    public function openPreview(int $batchId): void
    {
        $batch = $this->companyBatches()->whereKey($batchId)->where('status', AttendanceImportBatch::STATUS_PREVIEW)->first();
        $this->previewBatchId = $batch?->id;

        if ($batch === null) {
            Notification::make()->warning()->title('That preview is not available.')->send();
        }
    }

    public function closePreview(): void
    {
        $this->previewBatchId = null;
    }

    public function previewBatch(): ?AttendanceImportBatch
    {
        if ($this->previewBatchId === null || ! static::canAccess()) {
            return null;
        }

        return $this->companyBatches()->with('creator:id,name')->find($this->previewBatchId);
    }

    /** @return Collection<int, AttendanceImportRow> problem rows first, then the rest, up to PREVIEW_ROW_LIMIT */
    public function previewRows(AttendanceImportBatch $batch): Collection
    {
        $problems = collect(DeviceImportRowStatus::cases())->filter(fn (DeviceImportRowStatus $status): bool => $status->isProblem())->map->value->all();

        return AttendanceImportRow::query()
            ->where('batch_id', $batch->id)
            ->with('employee:id,name,employee_number')
            ->orderByRaw('CASE WHEN status IN ('.implode(',', array_fill(0, count($problems), '?')).') THEN 0 WHEN status = ? THEN 1 ELSE 2 END', [...$problems, DeviceImportRowStatus::Duplicate->value])
            ->orderBy('row_number')
            ->limit(self::PREVIEW_ROW_LIMIT)
            ->get();
    }

    public function pairingMode(): PunchPairingMode
    {
        return app(DeviceAttendanceImportService::class)->pairingMode((int) CurrentCompany::id());
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function importAction(): Action
    {
        return Action::make('import')
            ->label(fn (): string => 'Import '.((int) ($this->previewBatch()?->summary['counts'][DeviceImportRowStatus::Ok->value] ?? 0)).' punches')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Import this file into attendance?')
            ->modalDescription(fn (): string => 'The OK rows are written as biometric check-ins / check-outs ('.$this->pairingMode()->label().'). Days with manual entries are left alone, and every row is checked again first. You can undo the import later.')
            ->modalSubmitActionLabel('Import')
            ->visible(fn (): bool => ($this->previewBatch()?->summary['counts'][DeviceImportRowStatus::Ok->value] ?? 0) > 0)
            ->action(function (): void {
                [$user] = $this->hr();
                $batch = $this->previewBatch();
                if ($batch === null) {
                    return;
                }

                try {
                    $batch = app(DeviceAttendanceImportService::class)->import($user, $batch);
                } catch (RuntimeException $exception) {
                    Notification::make()->danger()->title('Not imported')->body($exception->getMessage())->send();

                    return;
                }

                $this->previewBatchId = null;
                $result = $batch->summary['result'] ?? [];

                Notification::make()
                    ->success()
                    ->title('Import #'.$batch->id.' done')
                    ->body(sprintf(
                        '%d punches imported into %d days (%d new, %d added to earlier imports). %d days need review for a missing check-out; %d days with manual entries were not changed; %d rows had problems. Download the report for details.',
                        $batch->imported_rows,
                        $batch->days_written,
                        (int) ($result['records_created'] ?? 0),
                        (int) ($result['days_merged'] ?? 0),
                        $batch->review_days,
                        $batch->flagged_days,
                        $batch->problem_rows,
                    ))
                    ->persistent()
                    ->send();
            });
    }

    public function discardAction(): Action
    {
        return Action::make('discard')
            ->label('Discard')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Discard this preview?')
            ->modalDescription('The uploaded file and its preview are deleted. Nothing was imported.')
            ->visible(fn (): bool => $this->previewBatch() !== null)
            ->action(function (): void {
                [$user] = $this->hr();
                $batch = $this->previewBatch();
                if ($batch !== null) {
                    app(DeviceAttendanceImportService::class)->discard($user, $batch);
                }

                $this->previewBatchId = null;
                Notification::make()->success()->title('Preview discarded')->send();
            });
    }

    public function previewReportAction(): Action
    {
        return Action::make('previewReport')
            ->label('Row report (Excel)')
            ->icon('heroicon-o-table-cells')
            ->color('gray')
            ->visible(fn (): bool => $this->previewBatch() !== null)
            ->action(fn (): ?StreamedResponse => ($batch = $this->previewBatch()) ? $this->reportDownload($batch) : null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')
                ->label('Download template')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function (): StreamedResponse {
                    $excel = app(DeviceImportExcel::class);

                    return $excel->download($excel->template(), 'device-attendance-template.xlsx');
                }),
            Action::make('pairing')
                ->label(fn (): string => 'Pairing: '.$this->pairingMode()->label())
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->modalHeading('How punches become check-in / check-out pairs')
                ->modalDescription('Applies to new imports of '.$this->companyName().'. Files already imported keep the mode they were imported with.')
                ->fillForm(fn (): array => ['mode' => $this->pairingMode()->value])
                ->schema([
                    Radio::make('mode')
                        ->label('Pairing')
                        ->options(PunchPairingMode::options())
                        ->descriptions(collect(PunchPairingMode::cases())->mapWithKeys(fn (PunchPairingMode $mode): array => [$mode->value => $mode->description()])->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    [$user, $companyId] = $this->hr();
                    app(DeviceAttendanceImportService::class)->setPairingMode($user, $companyId, PunchPairingMode::from((string) $data['mode']));
                    Notification::make()->success()->title('Pairing saved')->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->companyBatches()->with(['creator:id,name', 'importer:id,name']))
            ->heading('Imports of '.$this->companyName())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('file_name')
                    ->label('File')
                    ->searchable()
                    ->wrap()
                    ->description(fn (AttendanceImportBatch $record): string => $record->pairingMode()->label()),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AttendanceImportBatch $record): string => $record->statusLabel())
                    ->color(fn (AttendanceImportBatch $record): string => $record->statusColor()),
                TextColumn::make('creator.name')
                    ->label('Uploaded')
                    ->placeholder('—')
                    ->description(fn (AttendanceImportBatch $record): ?string => $record->created_at?->setTimezone($record->timezone)->format('d M Y H:i')),
                TextColumn::make('importer.name')
                    ->label('Imported')
                    ->placeholder('—')
                    ->description(fn (AttendanceImportBatch $record): ?string => $record->imported_at?->setTimezone($record->timezone)->format('d M Y H:i')),
                TextColumn::make('total_rows')->label('Rows')->numeric(),
                TextColumn::make('imported_rows')->label('Imported')->numeric(),
                TextColumn::make('duplicate_rows')->label('Duplicates')->numeric(),
                TextColumn::make('problem_rows')
                    ->label('Problems')
                    ->numeric()
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('review_days')->label('Days to review')->numeric(),
                TextColumn::make('flagged_days')->label('Days not changed')->numeric(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open preview')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (AttendanceImportBatch $record): bool => $record->status === AttendanceImportBatch::STATUS_PREVIEW)
                    ->action(fn (AttendanceImportBatch $record) => $this->openPreview($record->id)),
                Action::make('report')
                    ->label('Report')
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->action(fn (AttendanceImportBatch $record): StreamedResponse => $this->reportDownload($record)),
                Action::make('undo')
                    ->label('Undo')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (AttendanceImportBatch $record): string => 'Undo import #'.$record->id.'?')
                    ->modalDescription('Removes the attendance this import wrote. Days changed since the import (edits, HR corrections, GPS check-ins) are kept, days that also hold punches of another import are rebuilt from those, and nothing is undone if a day is in a locked month.')
                    ->modalSubmitActionLabel('Undo import')
                    ->visible(fn (AttendanceImportBatch $record): bool => $record->canBeUndone())
                    ->action(function (AttendanceImportBatch $record): void {
                        [$user] = $this->hr();

                        try {
                            $summary = app(DeviceAttendanceImportService::class)->undo($user, $record);
                        } catch (RuntimeException $exception) {
                            Notification::make()->danger()->title('Not undone')->body($exception->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Import #'.$record->id.' undone')
                            ->body(sprintf(
                                '%d days removed, %d rebuilt from other imports, %d kept because they were changed after the import.',
                                $summary['days_removed'],
                                $summary['days_rebuilt'],
                                $summary['days_kept'],
                            ))
                            ->persistent()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No device imports yet')
            ->emptyStateDescription('Upload a device export above to start.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return Builder<AttendanceImportBatch> */
    protected function companyBatches(): Builder
    {
        return AttendanceImportBatch::query()->where('company_id', CurrentCompany::id() ?? 0);
    }

    protected function reportDownload(AttendanceImportBatch $batch): StreamedResponse
    {
        $this->hr();
        abort_unless((int) $batch->company_id === (int) CurrentCompany::id(), 403);

        $excel = app(DeviceImportExcel::class);

        return $excel->download($excel->report($batch), 'device-import-'.$batch->id.'-report.xlsx');
    }

    protected function companyName(): string
    {
        return (string) (CurrentCompany::user()?->defaultCompany?->name ?? 'the active company');
    }

    /**
     * @return array{0: User, 1: int}
     *
     * @throws AuthorizationException
     */
    protected function hr(): array
    {
        $user = CurrentCompany::user();
        $companyId = CurrentCompany::id($user);

        if (! DeviceAttendanceImportService::canManage($user, $companyId)) {
            throw new AuthorizationException('Only HR of this company can import device attendance.');
        }

        return [$user, (int) $companyId];
    }
}
