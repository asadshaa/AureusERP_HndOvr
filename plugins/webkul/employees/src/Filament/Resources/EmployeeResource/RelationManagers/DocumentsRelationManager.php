<?php

namespace Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Resources\EmployeeDocumentResource;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Policies\EmployeeDocumentPolicy;
use Webkul\Employee\Services\EmployeeDocumentService;
use Webkul\Security\Models\User;

/**
 * "Documents" tab of an employee: HR uploads, replaces, checks and deletes;
 * the employee sees their own documents of types visible to them and may
 * upload one, which waits for an HR check. Every action goes through
 * EmployeeDocumentService, which checks EmployeeDocumentPolicy again.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (EmployeeDocument $record): string => $record->displayTitle())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['documentType', 'uploader'])
                ->when(! $this->isHr(), fn (Builder $query): Builder => $query
                    ->whereHas('documentType', fn (Builder $types): Builder => $types->where('visible_to_employee', true))))
            ->description(fn (): ?string => $this->missingRequiredDescription())
            ->columns(EmployeeDocumentResource::documentColumns(withEmployee: false))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription($this->canUpload() ? 'Upload a CNIC copy, contract, degree or other document.' : null)
            ->headerActions([
                Action::make('upload')
                    ->label($this->isHrManager() ? 'Upload document' : 'Upload a document for HR')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (): bool => $this->canUpload())
                    ->modalDescription(fn (): ?string => $this->isHrManager() ? null : 'HR will check it before it counts as received.')
                    ->schema(fn (): array => EmployeeDocumentResource::documentFields(
                        fn (): array => $this->typeOptions(),
                        withFile: true,
                    ))
                    ->action(function (array $data): void {
                        app(EmployeeDocumentService::class)->upload($this->employee(), $this->user(), $data, $data['file_path']);

                        Notification::make()->success()->title('Document uploaded')->send();
                    }),
            ])
            ->recordActions([
                EmployeeDocumentResource::downloadAction(),
                ActionGroup::make(EmployeeDocumentResource::manageActions()),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $ownerRecord instanceof Employee
            && app(EmployeeDocumentPolicy::class)->viewForEmployee($user, $ownerRecord);
    }

    /** @return array<int, string> */
    private function typeOptions(): array
    {
        return DocumentType::query()
            ->where('company_id', $this->employee()->company_id)
            ->where('is_active', true)
            ->when(! $this->isHrManager(), fn (Builder $query): Builder => $query->where('visible_to_employee', true))
            ->orderBy('sort')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function missingRequiredDescription(): ?string
    {
        if (! $this->isHr()) {
            return null;
        }

        $missing = $this->employee()->missingRequiredDocumentTypes();

        return $missing->isEmpty()
            ? null
            : 'Missing required documents ('.$missing->count().'): '.$missing->pluck('name')->implode(', ');
    }

    private function employee(): Employee
    {
        /** @var Employee $employee */
        $employee = $this->getOwnerRecord();

        return $employee;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function isHr(): bool
    {
        return Auth::user() instanceof User && app(EmployeeDocumentPolicy::class)->hrCanView($this->user(), $this->employee());
    }

    private function isHrManager(): bool
    {
        return Auth::user() instanceof User && app(EmployeeDocumentPolicy::class)->hrCanManage($this->user(), $this->employee());
    }

    private function canUpload(): bool
    {
        return Auth::user() instanceof User && app(EmployeeDocumentPolicy::class)->upload($this->user(), $this->employee());
    }
}
