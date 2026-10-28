<?php

namespace Webkul\Employee\Filament\Resources;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Webkul\Employee\Filament\Resources\EmployeeDocumentResource\Pages\ListEmployeeDocuments;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Services\EmployeeDocumentService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Filament\Concerns\ScopedToMemberCompanies;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * Employees -> Documents: every employee document HR may see, across the
 * user's member companies (Company filter starts on the active one), with
 * expiry and type filters. Uploading happens on the employee's Documents
 * tab; this list is for follow-up (expired, expiring, pending check,
 * missing required documents).
 */
class EmployeeDocumentResource extends Resource
{
    use ScopedToMemberCompanies;

    protected static ?string $model = EmployeeDocument::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $modelLabel = 'Employee document';

    protected static ?string $pluralModelLabel = 'Employee documents';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'employees/documents';
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        if (! $user instanceof User || CurrentCompany::id() === null || ! Gate::forUser($user)->allows('viewAny', EmployeeDocument::class)) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return CurrentCompany::scopeMemberships(parent::getEloquentQuery())
            ->whereNull('replaced_by_id')
            ->whereIn('employee_id', app(HrHierarchyService::class)->visibleEmployeeIdsAcrossMemberships($user)->all() ?: [0]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['documentType', 'employee', 'uploader']))
            ->columns(static::documentColumns(withEmployee: true))
            ->defaultSort('expires_on')
            ->filters([
                static::companyFilter(),
                SelectFilter::make('document_type')
                    ->label('Type')
                    ->multiple()
                    ->options(fn (): array => DocumentType::query()
                        ->whereIn('company_id', CurrentCompany::membershipIds() ?: [0])
                        ->orderBy('sort')
                        ->pluck('name', 'name')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['values'] ?? null)
                        ? $query->whereHas('documentType', fn (Builder $types): Builder => $types->whereIn('name', $data['values']))
                        : $query),
                Filter::make('expiring')
                    ->label('Expiring in 30 days')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->expiringWithin(EmployeeDocument::EXPIRING_SOON_DAYS)),
                Filter::make('expired')
                    ->label('Expired')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->expired()),
                Filter::make('pending')
                    ->label('Waiting for HR check')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('status', EmployeeDocument::STATUS_PENDING)),
            ])
            ->recordActions([
                static::downloadAction(),
                Action::make('employee')
                    ->label('Employee')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->url(fn (EmployeeDocument $record): string => EmployeeResource::getUrl('view', ['record' => $record->employee_id])),
                ActionGroup::make(static::manageActions()),
            ]);
    }

    /** @return array<int, Column> */
    public static function documentColumns(bool $withEmployee): array
    {
        return array_values(array_filter([
            $withEmployee
                ? TextColumn::make('employee.name')
                    ->label('Employee')
                    ->description(fn (EmployeeDocument $record): ?string => $record->employee?->employee_number)
                    ->searchable()
                    ->sortable()
                : null,
            TextColumn::make('documentType.name')
                ->label('Type')
                ->sortable(),
            TextColumn::make('title')
                ->label('Title')
                ->placeholder('—')
                ->searchable()
                ->toggleable(),
            TextColumn::make('number')
                ->label('Number')
                ->placeholder('—')
                ->searchable()
                ->copyable(),
            TextColumn::make('issued_on')
                ->label('Issued')
                ->date('d M Y')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('expires_on')
                ->label('Expiry')
                ->badge()
                ->sortable()
                ->state(fn (EmployeeDocument $record): string => $record->expiryLabel())
                ->color(fn (EmployeeDocument $record): string => EmployeeDocument::expiryColor($record->expiryState())),
            TextColumn::make('status')
                ->label('Check')
                ->badge()
                ->formatStateUsing(fn (string $state): string => $state === EmployeeDocument::STATUS_PENDING ? 'Waiting for HR' : 'Checked')
                ->color(fn (string $state): string => $state === EmployeeDocument::STATUS_PENDING ? 'warning' : 'success'),
            TextColumn::make('original_name')
                ->label('File')
                ->limit(30)
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('uploader.name')
                ->label('Uploaded by')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')
                ->label('Uploaded')
                ->dateTime('d M Y')
                ->sortable()
                ->toggleable(),
        ]));
    }

    /**
     * Upload / edit fields.
     *
     * @param  Closure(): array<int, string>  $typeOptions
     * @return array<int, Component|Field>
     */
    public static function documentFields(Closure $typeOptions, bool $withFile, bool $withType = true, bool $showExpiry = true): array
    {
        $hasExpiry = fn (Get $get): bool => (bool) DocumentType::query()->whereKey($get('document_type_id'))->value('has_expiry');

        return array_values(array_filter([
            $withType
                ? Select::make('document_type_id')
                    ->label('Type')
                    ->options($typeOptions)
                    ->required()
                    ->searchable()
                    ->live()
                : null,
            TextInput::make('title')
                ->label('Title')
                ->maxLength(255)
                ->helperText('Optional, e.g. "BS Computer Science".'),
            TextInput::make('number')
                ->label('Document number')
                ->maxLength(120)
                ->helperText('e.g. CNIC or passport number.'),
            DatePicker::make('issued_on')
                ->label('Issued on')
                ->native(false),
            DatePicker::make('expires_on')
                ->label('Expires on')
                ->native(false)
                ->afterOrEqual('issued_on')
                ->visible($withType ? $hasExpiry : $showExpiry),
            $withFile
                ? FileUpload::make('file_path')
                    ->label('File (PDF, JPG or PNG, up to 10 MB)')
                    ->disk(EmployeeDocument::DISK)
                    ->directory(EmployeeDocument::INCOMING_DIRECTORY)
                    ->visibility('private')
                    ->acceptedFileTypes(EmployeeDocument::ACCEPTED_MIME_TYPES)
                    ->maxSize(EmployeeDocument::MAX_SIZE_KB)
                    ->storeFileNamesIn('original_name')
                    ->required()
                : null,
            Textarea::make('notes')
                ->label('Notes')
                ->rows(2)
                ->maxLength(2000),
        ]));
    }

    /** Fresh signed link on every click; the route checks the permission again. */
    public static function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Download')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (EmployeeDocument $record): bool => Gate::allows('download', $record))
            ->action(fn (EmployeeDocument $record) => redirect()->to($record->temporaryDownloadUrl()));
    }

    /** @return array<int, Action> */
    public static function manageActions(): array
    {
        $service = fn (): EmployeeDocumentService => app(EmployeeDocumentService::class);
        $canManage = fn (EmployeeDocument $record): bool => Gate::allows('update', $record);

        return [
            Action::make('verify')
                ->label('Mark as checked')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (EmployeeDocument $record): bool => $record->status === EmployeeDocument::STATUS_PENDING && $canManage($record))
                ->requiresConfirmation()
                ->action(function (EmployeeDocument $record) use ($service): void {
                    $service()->verify($record, Auth::user());
                    Notification::make()->success()->title('Document checked')->send();
                }),
            Action::make('editDetails')
                ->label('Edit details')
                ->icon('heroicon-o-pencil-square')
                ->visible($canManage)
                ->fillForm(fn (EmployeeDocument $record): array => $record->only(['title', 'number', 'issued_on', 'expires_on', 'notes']))
                ->schema(fn (EmployeeDocument $record): array => static::documentFields(fn (): array => [], withFile: false, withType: false, showExpiry: (bool) $record->documentType?->has_expiry))
                ->action(function (EmployeeDocument $record, array $data) use ($service): void {
                    $service()->update($record, Auth::user(), $data);
                    Notification::make()->success()->title('Document updated')->send();
                }),
            Action::make('replace')
                ->label('Replace file')
                ->icon('heroicon-o-arrow-path')
                ->visible($canManage)
                ->modalDescription('The current file is kept in the history; the new one takes its place.')
                ->fillForm(fn (EmployeeDocument $record): array => $record->only(['title', 'number', 'issued_on', 'expires_on', 'notes']))
                ->schema(fn (EmployeeDocument $record): array => static::documentFields(fn (): array => [], withFile: true, withType: false, showExpiry: (bool) $record->documentType?->has_expiry))
                ->action(function (EmployeeDocument $record, array $data) use ($service): void {
                    $service()->replace($record, Auth::user(), $data['file_path'], $data);
                    Notification::make()->success()->title('Document replaced')->send();
                }),
            Action::make('delete')
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible($canManage)
                ->requiresConfirmation()
                ->modalDescription('The document is removed from the list. The file is kept for the audit trail.')
                ->action(function (EmployeeDocument $record) use ($service): void {
                    $service()->delete($record, Auth::user());
                    Notification::make()->success()->title('Document deleted')->send();
                }),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeDocuments::route('/'),
        ];
    }
}
